<?php

declare(strict_types=1);

namespace Modules\Attendances\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Catalog\Models\Product;

/** Varios servicios en la misma visita: los datos comunes y una línea por servicio. */
class AttendanceBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'appointment_id' => ['nullable', 'exists:appointments,id'],
            'attended_at' => ['required', 'date', 'before_or_equal:today'],
            'observations' => ['nullable', 'string', 'max:2000'],
            'measurements' => ['nullable', 'string', 'max:2000'],
            'services' => ['required', 'array', 'min:1', 'max:10'],
            'services.*.service_id' => ['required', Rule::exists('products', 'id')->where('type', Product::TYPE_SERVICE)],
            'services.*.employee_id' => ['required', 'exists:employees,id'],
            'services.*.customer_package_id' => ['nullable', 'exists:customer_packages,id'],
            'services.*.supplies' => ['nullable', 'array'],
            'services.*.supplies.*.product_id' => ['required', Rule::exists('products', 'id')->where('type', Product::TYPE_PRODUCT)],
            'services.*.supplies.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'attended_at.before_or_equal' => 'La atención no puede tener fecha futura.',
            'services.required' => 'Agrega al menos un servicio.',
            'services.*.service_id.required' => 'Elige el servicio.',
            'services.*.employee_id.required' => 'Elige el especialista.',
        ];
    }

    /** Un mismo insumo no se repite dentro de un servicio (entre servicios distintos sí puede). */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ((array) $this->input('services', []) as $i => $line) {
                    $ids = array_column((array) ($line['supplies'] ?? []), 'product_id');
                    if (count($ids) !== count(array_unique($ids))) {
                        $validator->errors()->add("services.{$i}.supplies", 'Un insumo está repetido.');
                    }
                }
            },
        ];
    }
}
