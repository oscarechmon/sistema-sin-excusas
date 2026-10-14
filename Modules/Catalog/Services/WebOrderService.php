<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;

/**
 * «Orden en la web»: qué categoría va primero y, dentro de cada una, qué
 * producto o servicio. La web agrupa por categoría, así que se ordena igual.
 *
 * Lo que nunca se ordenó va al final, por nombre (un ítem sin `sort_order`,
 * una categoría con 0): lo nuevo que se publica aparece último. Al guardar se
 * avisa a la web por los eventos de siempre (IntegrationServiceProvider).
 */
class WebOrderService
{
    /**
     * Lo que se ve en la web de un tipo, en el orden en que se ve.
     *
     * @return array{categories: list<array<string, mixed>>, uncategorized: list<array<string, mixed>>}
     */
    public function groups(string $type): array
    {
        $items = Product::query()
            ->where('type', $type)
            ->where('web_published', true)
            ->where('is_active', true)
            ->get()
            ->sortBy([
                fn (Product $a, Product $b) => ($a->sort_order ?? PHP_INT_MAX) <=> ($b->sort_order ?? PHP_INT_MAX),
                fn (Product $a, Product $b) => strnatcasecmp($a->name, $b->name),
            ]);

        $categories = Category::whereIn('id', $items->pluck('category_id')->filter()->unique())
            ->get()
            ->sortBy([
                fn (Category $a, Category $b) => ($a->sort_order ?: PHP_INT_MAX) <=> ($b->sort_order ?: PHP_INT_MAX),
                fn (Category $a, Category $b) => $a->id <=> $b->id,
            ]);

        return [
            'categories' => $categories->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'items' => $this->rows($items->where('category_id', $category->id)),
            ])->values()->all(),
            'uncategorized' => $this->rows($items->whereNull('category_id')),
        ];
    }

    /**
     * Guarda el orden: las categorías y los ítems, cada uno en la posición en
     * que llegan (1, 2, 3…). Lo que no es de ese tipo se ignora.
     *
     * @param  list<int>  $categoryIds
     * @param  list<int>  $itemIds
     */
    public function save(string $type, array $categoryIds, array $itemIds): void
    {
        DB::transaction(function () use ($type, $categoryIds, $itemIds): void {
            $items = Product::where('type', $type)->whereIn('id', $itemIds)->get()->keyBy('id');
            foreach (array_values($itemIds) as $position => $id) {
                $items->get($id)?->update(['sort_order' => $position + 1]);
            }

            $categories = Category::whereIn('id', $categoryIds)->get()->keyBy('id');
            foreach (array_values($categoryIds) as $position => $id) {
                $categories->get($id)?->update(['sort_order' => $position + 1]);
            }
        });
    }

    /**
     * @param  Collection<int, Product>  $items
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $items): array
    {
        return $items->map(fn (Product $product): array => [
            'id' => $product->id,
            'name' => $product->name,
            'code' => $product->code,
            'image_url' => $product->image_url,
            'price' => (float) $product->price,
            'promotion_price' => $product->promotionPrice(),
            'on_promotion' => (bool) $product->on_promotion,
        ])->values()->all();
    }
}
