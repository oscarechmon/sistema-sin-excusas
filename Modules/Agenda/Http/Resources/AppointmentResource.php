<?php

declare(strict_types=1);

namespace Modules\Agenda\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Agenda\Models\Appointment;

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
            // Reprogramación: de qué cita viene y a cuál se movió.
            'rescheduled_from' => $this->whenLoaded('rescheduledFrom', fn () => $this->moment($this->rescheduledFrom)),
            'rescheduled_to' => $this->whenLoaded('rescheduledTo', fn () => $this->moment($this->rescheduledTo)),
        ];
    }

    /** @return array{id: int, appointment_date: string|null, start_time: string}|null */
    private function moment(?Appointment $appointment): ?array
    {
        return $appointment ? [
            'id' => $appointment->id,
            'appointment_date' => $appointment->appointment_date?->toDateString(),
            'start_time' => substr((string) $appointment->start_time, 0, 5),
        ] : null;
    }
}
