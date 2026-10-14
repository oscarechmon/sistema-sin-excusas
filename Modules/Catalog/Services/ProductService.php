<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use App\Core\Exceptions\BusinessException;
use App\Core\Services\BaseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Intervention\Image\ImageManager;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductImage;
use Modules\Catalog\Repositories\Contracts\ProductRepositoryInterface;

/**
 * Lógica de negocio de productos: correlativo de código interno, generación
 * automática de código de barras, procesamiento de imágenes (la principal y
 * las adicionales de la ficha web) y escaneo por barras.
 */
class ProductService extends BaseService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly BarcodeService $barcodes,
    ) {
        parent::__construct($products);
    }

    public function create(array $data): Model
    {
        return DB::transaction(function () use ($data): Product {
            $data['code'] = $data['code'] ?? $this->products->nextCode();
            $data['company_id'] = $data['company_id'] ?? optional(auth()->user())->company_id;

            $image = $data['image'] ?? null;
            $gallery = $data['gallery'] ?? [];
            unset($data['image'], $data['gallery'], $data['remove_images']);

            /** @var Product $product */
            $product = $this->products->create($data);

            // Genera un EAN-13 válido si no se proporcionó código de barras.
            if (empty($product->barcode)) {
                $product->barcode = $this->barcodes->generateEan13($product->id);
                $product->saveQuietly();
            }

            if ($image instanceof UploadedFile) {
                $product->image_path = $this->storeImage($image, $product->id);
                $product->saveQuietly();
            }

            $this->syncGallery($product, [], $gallery);

            return $product->fresh();
        });
    }

    public function update(int|string $id, array $data): Model
    {
        $this->guardPackage($id);

        $image = $data['image'] ?? null;
        $gallery = $data['gallery'] ?? [];
        $remove = $data['remove_images'] ?? [];
        unset($data['image'], $data['gallery'], $data['remove_images']);

        return DB::transaction(function () use ($id, $data, $image, $gallery, $remove): Product {
            /** @var Product $product */
            $product = $this->products->update($id, $data);

            // Antes que la principal: si sobran fotos, no se toca ningún archivo.
            $this->syncGallery($product, $remove, $gallery);

            if ($image instanceof UploadedFile) {
                $this->replaceImage($product, $image);
            }

            // El aviso a la web sale al terminar la petición: ya lleva las fotos.
            return $product->fresh();
        });
    }

    /**
     * Publica u oculta el ítem en la web, sin tocar nada más. Al guardarse se
     * avisa a la web (IntegrationServiceProvider), que lo muestra o lo quita.
     */
    public function publish(int|string $id, bool $published): Product
    {
        $this->guardPackage($id);

        /** @var Product $product */
        $product = Product::findOrFail($id);
        $product->update(['web_published' => $published]);

        return $this->products->findOrFail($product->id);
    }

    /**
     * Pone o quita de promoción, sin tocar nada más. Como al publicar, se
     * avisa a la web, que lo muestra en «Promociones» con su precio.
     */
    public function promote(int|string $id, bool $onPromotion): Product
    {
        $this->guardPackage($id);

        /** @var Product $product */
        $product = Product::findOrFail($id);
        $product->update(['on_promotion' => $onPromotion]);

        return $this->products->findOrFail($product->id);
    }

    /**
     * Cambia la imagen del producto (borra la anterior). Se guarda sin pasar
     * por los eventos, como al crearlo: quien llama decide si avisar.
     */
    public function replaceImage(Product $product, UploadedFile $image): void
    {
        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }

        $product->image_path = $this->storeImage($image, $product->id);
        $product->saveQuietly();
    }

    /**
     * Fotos adicionales de la ficha web: quita las indicadas (solo si son de
     * este producto) y agrega las nuevas al final, hasta ProductImage::MAX.
     *
     * @param  array<int, mixed>  $removeIds
     * @param  array<int, mixed>  $files
     */
    private function syncGallery(Product $product, array $removeIds, array $files): void
    {
        $files = array_values(array_filter($files, fn ($file) => $file instanceof UploadedFile));

        if ($removeIds === [] && $files === []) {
            return;
        }

        $current = ProductImage::where('product_id', $product->id)->get();
        $removed = $current->whereIn('id', array_map('intval', $removeIds));

        if ($current->count() - $removed->count() + count($files) > ProductImage::MAX) {
            throw ValidationException::withMessages([
                'gallery' => 'Puedes tener hasta ' . ProductImage::MAX . ' fotos adicionales: quita alguna antes de subir más.',
            ]);
        }

        foreach ($removed as $image) {
            $image->delete();
            // El archivo se borra solo si el cambio se guarda de verdad.
            DB::afterCommit(fn () => Storage::disk('public')->delete($image->path));
        }

        $order = (int) $current->max('sort_order');
        foreach ($files as $file) {
            ProductImage::create([
                'product_id' => $product->id,
                'path' => $this->storeImage($file, $product->id),
                'sort_order' => ++$order,
            ]);
        }
    }

    public function delete(int|string $id): bool
    {
        $this->guardPackage($id);

        return parent::delete($id);
    }

    public function bulkDelete(array $ids): int
    {
        if (Product::whereIn('id', $ids)->where('type', Product::TYPE_PACKAGE)->exists()) {
            throw new BusinessException('Los paquetes se eliminan desde Paquetes.');
        }

        return parent::bulkDelete($ids);
    }

    /** El producto de un paquete lo mantiene el módulo Paquetes (sesiones, servicios, vigencia). */
    private function guardPackage(int|string $id): void
    {
        if (Product::whereKey($id)->where('type', Product::TYPE_PACKAGE)->exists()) {
            throw new BusinessException('Este ítem es un paquete: edítalo desde Paquetes.');
        }
    }

    /** Busca un producto por código de barras (para el POS / pistola lectora). */
    public function scan(string $barcode): Product
    {
        $product = $this->products->findByBarcode($barcode);

        if (! $product) {
            throw new BusinessException("No se encontró un producto con el código «{$barcode}».", 404);
        }

        return $product->load(['category:id,name', 'brand:id,name', 'unit:id,name,abbreviation']);
    }

    /**
     * Redimensiona (máx. 800px) y almacena la imagen del producto en el disco
     * público, devolviendo su ruta relativa.
     */
    private function storeImage(UploadedFile $file, int $productId): string
    {
        $manager = new ImageManager(\Intervention\Image\Drivers\Gd\Driver::class);
        $image = $manager->read($file->getRealPath())->scaleDown(width: 800);

        $path = "products/{$productId}/" . uniqid('img_') . '.webp';
        Storage::disk('public')->put($path, (string) $image->toWebp(80));

        return $path;
    }
}
