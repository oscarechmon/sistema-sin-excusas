<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Catalog\Models\Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'code' => $this->code,
            'barcode' => $this->barcode,
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->image_url,
            // Fotos adicionales de la ficha web: solo al pedir un ítem (el formulario).
            'gallery' => $this->whenLoaded('images', fn () => $this->images
                ->map(fn ($image) => ['id' => $image->id, 'url' => $image->url])
                ->values()
                ->all()),
            'category_id' => $this->category_id,
            'brand_id' => $this->brand_id,
            'unit_id' => $this->unit_id,
            'category' => $this->whenLoaded('category', fn () => ['id' => $this->category->id, 'name' => $this->category->name]),
            'brand' => $this->whenLoaded('brand', fn () => ['id' => $this->brand->id, 'name' => $this->brand->name]),
            'unit' => $this->whenLoaded('unit', fn () => [
                'id' => $this->unit->id,
                'name' => $this->unit->name,
                'abbreviation' => $this->unit->abbreviation,
            ]),
            'cost' => (float) $this->cost,
            'price' => (float) $this->price,
            'wholesale_price' => $this->wholesale_price !== null ? (float) $this->wholesale_price : null,
            'offer_price' => $this->offer_price !== null ? (float) $this->offer_price : null,
            // En promoción, y el precio que se cobra mientras dure (null: el normal).
            'on_promotion' => (bool) $this->on_promotion,
            'promotion_price' => $this->resource->promotionPrice(),
            'profit_margin' => $this->profit_margin,
            'current_stock' => $this->current_stock,
            'stock_min' => (float) $this->stock_min,
            'stock_max' => $this->stock_max !== null ? (float) $this->stock_max : null,
            'track_stock' => $this->track_stock,
            'has_expiry' => $this->has_expiry,
            'is_active' => $this->is_active,
            'web_published' => $this->web_published,
            'duration_minutes' => $this->duration_minutes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
