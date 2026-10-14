<?php

declare(strict_types=1);

namespace Modules\Catalog\Repositories;

use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Repositories\Contracts\ProductRepositoryInterface;

class ProductRepository extends BaseRepository implements ProductRepositoryInterface
{
    protected array $searchable = ['name', 'code', 'barcode', 'sku'];

    protected array $filterable = ['type', 'category_id', 'brand_id', 'unit_id', 'is_active'];

    protected array $sortable = ['id', 'name', 'code', 'price', 'cost', 'created_at'];

    protected array $defaultWith = ['category:id,name', 'brand:id,name', 'unit:id,name,abbreviation'];

    protected function model(): Model
    {
        return new Product();
    }

    /** Añade el stock total sumado (withSum) para evitar N+1 en los listados. */
    public function query(): Builder
    {
        return parent::query()->withSum('stocks', 'quantity');
    }

    /**
     * Además de los filtros comunes, `web`: `published` (lo que se ve en la
     * web), `hidden` (lo que no, incluido lo que nunca se decidió) o
     * `promotion` (lo que está en promoción).
     */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        match ($filters['web'] ?? null) {
            'published' => $query->where('web_published', true),
            'hidden' => $query->where(fn (Builder $q) => $q->where('web_published', false)->orWhereNull('web_published')),
            'promotion' => $query->where('on_promotion', true),
            default => null,
        };

        return parent::applyFilters($query, $filters);
    }

    public function findByBarcode(string $barcode): ?Model
    {
        return $this->query()
            ->where('barcode', $barcode)
            ->orWhereHas('barcodes', fn ($q) => $q->where('barcode', $barcode))
            ->first();
    }

    public function nextCode(): string
    {
        // Toma el mayor correlativo existente con prefijo PRD- y suma 1.
        $last = Product::withTrashed()
            ->where('code', 'like', 'PRD-%')
            ->orderByDesc('id')
            ->value('code');

        $number = $last ? ((int) substr($last, 4)) + 1 : 1;

        return 'PRD-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
