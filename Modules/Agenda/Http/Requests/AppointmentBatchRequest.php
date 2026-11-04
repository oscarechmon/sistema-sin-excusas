<?php

declare(strict_types=1);

namespace Modules\Agenda\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Catalog\Models\Product;

/**
 * Varios servicios en la misma visita: cliente, fecha, título y notas comunes,
 * y una línea por servicio con su especialista y su horario.
 */
class AppointmentBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'appointment_date' => ['required', 'date', 'after_or_equal:today'],
            'title' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'services' => ['required', 'array', 'min:1', 'max:10'],
            'services.*.service_id' => ['required', Rule::exists('products', 'id')->where('type', Product::TYPE_SERVICE)],
            'services.*.employee_id' => ['required', 'exists:employees,id'],
            'services.*.start_time' => ['required', 'date_format:H:i'],
            'services.*.end_time' => ['required', 'date_format:H:i', 'after:services.*.start_time'],
        ];
    }

    public function messages(): array
    {
        return [
            'appointment_date.after_or_equal' => 'La cita no puede ser en una fecha pasada.',
            'services.required' => 'Agrega al menos un servicio.',
            'services.*.service_id.required' => 'Elige el servicio.',
            'services.*.employee_id.required' => 'Elige el especialista.',
            'services.*.end_time.after' => 'La hora de fin debe ser posterior a la de inicio.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title')) ?: null]);
        }

        // Las horas pueden venir con segundos (HH:MM:SS).
        $services = $this->input('services');
        if (is_array($services)) {
            foreach ($services as $i => $line) {
                foreach (['start_time', 'end_time'] as $field) {
                    if (is_array($line) && is_string($line[$field] ?? null)) {
                        $services[$i][$field] = substr($line[$field], 0, 5);
                    }
                }
            }
            $this->merge(['services' => $services]);
        }
    }
}
