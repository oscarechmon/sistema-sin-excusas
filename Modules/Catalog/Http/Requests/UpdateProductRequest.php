<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductImage;

/** Validación para actualizar un producto (código único ignorando el actual). */
class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('product');

        return [
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('products', 'code')->ignore($id)],
            'barcode' => ['nullable', 'string', 'max:50'],
            'sku' => ['nullable', 'string', 'max:50'],
            'type' => ['sometimes', Rule::in(Product::TYPES)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'unit_id' => ['nullable', 'exists:units,id'],
            'cost' => ['sometimes', 'numeric', 'min:0'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'offer_price' => ['nullable', 'numeric', 'min:0'],
            'stock_min' => ['nullable', 'numeric', 'min:0'],
            'stock_max' => ['nullable', 'numeric', 'min:0'],
            'track_stock' => ['boolean'],
            'has_expiry' => ['boolean'],
            'is_active' => ['boolean'],
            'web_published' => ['nullable', 'boolean'],
            'on_promotion' => ['sometimes', 'boolean'],
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
            'image.max' => 'La imagen no debe superar los 4 MB.',
            'gallery.max' => 'Puedes subir hasta ' . ProductImage::MAX . ' fotos adicionales.',
            'gallery.*.image' => 'Cada foto adicional debe ser JPG, PNG o WEBP.',
            'gallery.*.mimes' => 'Cada foto adicional debe ser JPG, PNG o WEBP.',
            'gallery.*.max' => 'Cada foto adicional debe pesar hasta 4 MB.',
        ];
    }

    /** En promoción, el precio de promoción tiene que rebajar el precio (el que llega o el que ya tiene). */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $offer = (float) $this->input('offer_price');
                if (! $this->boolean('on_promotion') || $offer <= 0) {
                    return;
                }
                $price = $this->has('price') ? $this->input('price') : Product::whereKey($this->route('product'))->value('price');
                if (is_numeric($price) && $offer >= (float) $price) {
                    $validator->errors()->add('offer_price', 'El precio de promoción debe ser menor que el precio normal.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'track_stock' => $this->boolean('track_stock', true),
            'has_expiry' => $this->boolean('has_expiry'),
            'is_active' => $this->boolean('is_active', true),
        ]);

        if ($this->has('on_promotion')) {
            $this->merge(['on_promotion' => $this->boolean('on_promotion')]);
        }
    }
}
