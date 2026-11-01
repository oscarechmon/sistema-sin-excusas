<?php

declare(strict_types=1);

namespace Modules\Agenda\Services;

use Modules\Agenda\Models\Appointment;
use Modules\Attendances\Models\Attendance;

/**
 * Número de visita del cliente en cada cita: los días distintos en que ya vino
 * o tiene cita vigente antes de esa fecha, más uno. Dos citas el mismo día son
 * la misma visita; las canceladas, pospuestas o sin asistencia no cuentan, y
 * las atenciones sin cita (o importadas de la web) sí.
 */
class VisitCounter
{
    /**
     * Anota `visit_number` en cada cita (null si la cita ya no ocupa el horario).
     *
     * @param  iterable<Appointment>  $appointments
     */
    public function annotate(iterable $appointments): void
    {
        $list = collect($appointments);
        if ($list->isEmpty()) {
            return;
        }

        $until = $list->max(fn (Appointment $a) => $a->appointment_date->toDateString());
        $days = $this->days($list->pluck('customer_id')->unique()->all(), $until);

        foreach ($list as $appointment) {
            $appointment->setAttribute('visit_number', in_array($appointment->status, Appointment::FREE_STATUSES, true)
                ? null
                : $this->countBefore($days[$appointment->customer_id] ?? [], $appointment->appointment_date->toDateString()) + 1);
        }
    }

    /** Qué número de visita sería una cita nueva del cliente en esa fecha. */
    public function next(int $customerId, string $date): int
    {
        return $this->countBefore($this->days([$customerId], $date)[$customerId] ?? [], $date) + 1;
    }

    /**
     * Días (Y-m-d) con cita vigente o atención de cada cliente, antes de $until.
     *
     * @param  list<int>  $customerIds
     * @return array<int, array<string, true>>
     */
    private function days(array $customerIds, string $until): array
    {
        $days = [];

        Appointment::query()
            ->whereIn('customer_id', $customerIds)
            ->whereNotIn('status', Appointment::FREE_STATUSES)
            ->whereDate('appointment_date', '<', $until)
            ->get(['customer_id', 'appointment_date'])
            ->each(function (Appointment $a) use (&$days): void {
                $days[$a->customer_id][$a->appointment_date->toDateString()] = true;
            });

        Attendance::query()
            ->whereIn('customer_id', $customerIds)
            ->whereDate('attended_at', '<', $until)
            ->get(['customer_id', 'attended_at'])
            ->each(function (Attendance $a) use (&$days): void {
                $days[$a->customer_id][$a->attended_at->toDateString()] = true;
            });

        return $days;
    }

    /** @param  array<string, true>  $days */
    private function countBefore(array $days, string $date): int
    {
        return count(array_filter(array_keys($days), fn (string $day) => $day < $date));
    }
}
