<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductImage;

/** Validación para crear un producto. */
class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // La autorización se controla por middleware de permiso en la ruta.
    }

    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:50', Rule::unique('products', 'code')],
            'barcode' => ['nullable', 'string', 'max:50'],
            'sku' => ['nullable', 'string', 'max:50'],
            'type' => ['required', Rule::in(Product::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'unit_id' => ['nullable', 'exists:units,id'],
            'cost' => ['required', 'numeric', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'offer_price' => ['nullable', 'numeric', 'min:0'],
            'stock_min' => ['nullable', 'numeric', 'min:0'],
            'stock_max' => ['nullable', 'numeric', 'min:0'],
            'track_stock' => ['boolean'],
            'has_expiry' => ['boolean'],
            'is_active' => ['boolean'],
            'web_published' => ['nullable', 'boolean'],
            'on_promotion' => ['boolean'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:600'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            // Fotos adicionales de la ficha web (la principal es `image`).
            'gallery' => ['nullable', 'array', 'max:' . ProductImage::MAX],
            'gallery.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_images' => ['nullable', 'array'],
            'remove_images.*' => ['integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'duration_minutes.min' => 'La duración debe ser de al menos 5 minutos.',
            'duration_minutes.max' => 'La duración no puede pasar de 600 minutos.',
            'type.in' => 'El tipo debe ser producto o servicio.',
            'name.required' => 'El nombre del producto es obligatorio.',
            'code.unique' => 'Ya existe un producto con este código interno.',
            'cost.required' => 'El costo es obligatorio.',
            'price.required' => 'El precio de venta es obligatorio.',
            'price.min' => 'El precio no puede ser negativo.',
            'image.max' => 'La imagen no debe superar los 4 MB.',
            'gallery.max' => 'Puedes subir hasta ' . ProductImage::MAX . ' fotos adicionales.',
            'gallery.*.image' => 'Cada foto adicional debe ser JPG, PNG o WEBP.',
            'gallery.*.mimes' => 'Cada foto adicional debe ser JPG, PNG o WEBP.',
            'gallery.*.max' => 'Cada foto adicional debe pesar hasta 4 MB.',
        ];
    }

    /** En promoción, el precio de promoción tiene que rebajar el precio normal. */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $offer = (float) $this->input('offer_price');
                if ($this->boolean('on_promotion') && $offer > 0 && is_numeric($this->input('price')) && $offer >= (float) $this->input('price')) {
                    $validator->errors()->add('offer_price', 'El precio de promoción debe ser menor que el precio normal.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        // Normaliza los booleanos que llegan como "true"/"1"/on desde el formulario.
        $this->merge([
            'type' => $this->input('type', Product::TYPE_PRODUCT),
            'track_stock' => $this->boolean('track_stock', true),
            'has_expiry' => $this->boolean('has_expiry'),
            'is_active' => $this->boolean('is_active', true),
            'on_promotion' => $this->boolean('on_promotion'),
        ]);
    }
}
