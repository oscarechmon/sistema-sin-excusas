<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers\Api;

use App\Core\Exceptions\BusinessException;
use App\Core\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Catalog\Exports\ProductsExport;
use Modules\Catalog\Http\Requests\StoreProductRequest;
use Modules\Catalog\Http\Requests\UpdateProductRequest;
use Modules\Catalog\Http\Resources\ProductResource;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Services\BarcodeService;
use Modules\Catalog\Services\ProductService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * API de Productos. Autorización por permisos (middleware en las rutas).
 * Respuestas estandarizadas vía ProductResource + envoltura ApiResponse.
 */
class ProductController extends ApiController
{
    public function __construct(
        private readonly ProductService $service,
        private readonly BarcodeService $barcodes,
    ) {
    }

    /** Listado paginado con búsqueda, filtros y ordenamiento. */
    public function index(Request $request): JsonResponse
    {
        $products = $this->service->list($request->all());
        $data = ProductResource::collection($products)->response()->getData(true);

        // La pantalla de Productos o Servicios muestra cuántos hay en la web y
        // en promoción: va en la misma respuesta para no hacer otra petición.
        if (in_array($request->query('type'), Product::TYPES, true)) {
            $data['web_published_total'] = Product::where('type', $request->query('type'))->where('web_published', true)->count();
            $data['promotion_total'] = Product::where('type', $request->query('type'))->where('on_promotion', true)->count();
        }

        return $this->ok($data);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->service->create($request->validated() + ['image' => $request->file('image'), 'gallery' => $request->file('gallery', [])]);

        return $this->created(new ProductResource($product), 'Producto creado correctamente.');
    }

    public function show(int $product): JsonResponse
    {
        $model = $this->service->find($product, ['category', 'brand', 'unit', 'barcodes', 'images']);

        return $this->ok(new ProductResource($model));
    }

    public function update(UpdateProductRequest $request, int $product): JsonResponse
    {
        $model = $this->service->update($product, $request->validated() + ['image' => $request->file('image'), 'gallery' => $request->file('gallery', [])]);

        return $this->ok(new ProductResource($model), 'Producto actualizado correctamente.');
    }

    /** Publicar u ocultar en la web con un clic, desde el listado. */
    public function publish(Request $request, int $product): JsonResponse
    {
        $data = $request->validate(['web_published' => ['required', 'boolean']]);

        $model = $this->service->publish($product, $data['web_published']);

        return $this->ok(
            new ProductResource($model),
            $model->web_published ? "«{$model->name}» ya se muestra en la web." : "«{$model->name}» ya no se muestra en la web.",
        );
    }

    /** Poner o quitar de promoción con un clic, desde el listado. */
    public function promote(Request $request, int $product): JsonResponse
    {
        $data = $request->validate(['on_promotion' => ['required', 'boolean']]);

        $model = $this->service->promote($product, (bool) $data['on_promotion']);

        return $this->ok(
            new ProductResource($model),
            $model->on_promotion ? "«{$model->name}» ahora está en promoción." : "«{$model->name}» ya no está en promoción.",
        );
    }

    public function destroy(int $product): JsonResponse
    {
        $this->service->delete($product);

        return $this->noContent('Producto eliminado.');
    }

    /** Eliminación masiva (acciones en lote de la tabla). */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];
        $count = $this->service->bulkDelete($ids);

        return $this->ok(['deleted' => $count], "{$count} producto(s) eliminado(s).");
    }

    /** Escaneo por código de barras (POS / pistola lectora USB). */
    public function scan(string $barcode): JsonResponse
    {
        return $this->ok(new ProductResource($this->service->scan($barcode)));
    }

    /** Exporta el catálogo (respetando el buscador) a Excel o CSV. */
    public function export(Request $request): BinaryFileResponse
    {
        $products = $this->service->all($request->all())->load(['category', 'brand', 'unit']);
        $format = strtolower((string) $request->query('export', 'xlsx'));
        $filename = 'productos-' . now()->format('Ymd_His');

        return match ($format) {
            'xlsx' => Excel::download(new ProductsExport($products), "{$filename}.xlsx", ExcelWriter::XLSX),
            'csv' => Excel::download(new ProductsExport($products), "{$filename}.csv", ExcelWriter::CSV),
            default => throw new BusinessException('Formato no soportado. Usa xlsx o csv.'),
        };
    }

    /** Devuelve la etiqueta (código de barras + QR) del producto para imprimir. */
    public function label(int $product): JsonResponse
    {
        $model = $this->service->find($product);
        $value = $model->barcode ?? $model->code;
        // EAN-13 sólo si el valor es numérico de 13 dígitos; si no, CODE128 (siempre válido).
        $type = preg_match('/^\d{13}$/', (string) $value) === 1 ? 'EAN13' : 'CODE128';

        return $this->ok([
            'name' => $model->name,
            'code' => $model->code,
            'price' => (float) $model->price,
            'barcode_png' => $this->barcodes->png($value, $type),
            'qr_png' => $this->barcodes->qr($value),
        ]);
    }
}
