<?php

declare(strict_types=1);

namespace Modules\Agenda\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Agenda\Models\Appointment;
use Modules\Catalog\Models\Product;

class AppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'service_id' => ['required', Rule::exists('products', 'id')->where('type', Product::TYPE_SERVICE)],
            'employee_id' => ['required', 'exists:employees,id'],
            // Al crear, no en el pasado; al editar se respeta la fecha que ya tenía.
            'appointment_date' => array_filter(['required', 'date', $creating ? 'after_or_equal:today' : null]),
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'title' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(Appointment::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'appointment_date.after_or_equal' => 'La cita no puede ser en una fecha pasada.',
            'end_time.after' => 'La hora de fin debe ser posterior a la de inicio.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title')) ?: null]);
        }

        // Las horas pueden venir con segundos (HH:MM:SS) desde la edición.
        foreach (['start_time', 'end_time'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => substr($this->input($field), 0, 5)]);
            }
        }
    }
}
