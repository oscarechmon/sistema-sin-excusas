<?php

declare(strict_types=1);

namespace Modules\Agenda\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Agenda\Models\Appointment */
class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'appointment_date' => $this->appointment_date?->toDateString(),
            'start_time' => substr((string) $this->start_time, 0, 5),
            'end_time' => substr((string) $this->end_time, 0, 5),
            'title' => $this->title,
            'status' => $this->status,
            'notes' => $this->notes,
            // Lo anota VisitCounter en los listados y en el detalle.
            'visit_number' => $this->visit_number,
            'customer_id' => $this->customer_id,
            'service_id' => $this->service_id,
            'employee_id' => $this->employee_id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'code' => $this->customer->code,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
                'whatsapp' => $this->customer->whatsapp,
            ] : null),
            'service' => $this->whenLoaded('service', fn () => $this->service ? ['id' => $this->service->id, 'name' => $this->service->name] : null),
            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? ['id' => $this->employee->id, 'name' => $this->employee->name] : null),
        ];
    }
}
