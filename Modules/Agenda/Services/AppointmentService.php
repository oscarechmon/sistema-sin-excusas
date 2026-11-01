<?php

declare(strict_types=1);

namespace Modules\Agenda\Services;

use App\Core\Exceptions\BusinessException;
use App\Core\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\Agenda\Models\Appointment;
use Modules\Agenda\Repositories\AppointmentRepository;

/**
 * Agenda del centro. Un especialista no puede tener dos citas que se crucen
 * (las canceladas, pospuestas o sin asistencia ya no ocupan el horario).
 * Cada cita que se lee lleva el número de visita del cliente.
 */
class AppointmentService extends BaseService
{
    public function __construct(AppointmentRepository $repository, private readonly VisitCounter $visits)
    {
        parent::__construct($repository);
    }

    public function list(array $filters = []): LengthAwarePaginator
    {
        $page = parent::list($filters);
        $this->visits->annotate($page->getCollection());

        return $page;
    }

    public function all(array $filters = []): Collection
    {
        $appointments = parent::all($filters);
        $this->visits->annotate($appointments);

        return $appointments;
    }

    public function find(int|string $id, array $with = []): Model
    {
        return $this->withVisit(parent::find($id, $with));
    }

    /** Número de visita que tendría una cita nueva del cliente en esa fecha. */
    public function nextVisit(int $customerId, string $date): int
    {
        return $this->visits->next($customerId, $date);
    }

    public function create(array $data): Model
    {
        $data = $this->normalizeTimes($data);
        $data['status'] ??= Appointment::STATUS_PENDING;
        $data['created_by'] = auth()->id();
        $this->assertFree($data);

        return $this->withVisit(parent::create($data)->load(['customer', 'service', 'employee']));
    }

    public function update(int|string $id, array $data): Model
    {
        $data = $this->normalizeTimes($data);
        /** @var Appointment $current */
        $current = $this->repository->findOrFail($id);
        $this->assertFree($data + $current->only(['employee_id', 'appointment_date', 'start_time', 'end_time', 'status']), (int) $id);

        return $this->withVisit(parent::update($id, $data)->load(['customer', 'service', 'employee']));
    }

    public function changeStatus(int $id, string $status): Appointment
    {
        /** @var Appointment $appointment */
        $appointment = $this->repository->findOrFail($id);

        if (! in_array($status, Appointment::FREE_STATUSES, true)) {
            $this->assertFree(['status' => $status] + $appointment->only(['employee_id', 'appointment_date', 'start_time', 'end_time']), $id);
        }

        $appointment->update(['status' => $status]);

        return $this->withVisit($appointment->load(['customer', 'service', 'employee']));
    }

    /**
     * @template T of Model
     *
     * @param  T  $appointment
     * @return T
     */
    private function withVisit(Model $appointment): Model
    {
        $this->visits->annotate([$appointment]);

        return $appointment;
    }

    /**
     * Horas siempre como HH:MM:SS, igual en MySQL y en SQLite, para que la
     * comparación de cruces funcione en ambos.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeTimes(array $data): array
    {
        foreach (['start_time', 'end_time'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = substr((string) $data[$field], 0, 5).':00';
            }
        }

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    private function assertFree(array $data, ?int $ignoreId = null): void
    {
        if (in_array($data['status'] ?? Appointment::STATUS_PENDING, Appointment::FREE_STATUSES, true)) {
            return;
        }

        $date = $data['appointment_date'] instanceof \DateTimeInterface
            ? $data['appointment_date']->format('Y-m-d')
            : (string) $data['appointment_date'];
        $start = substr((string) $data['start_time'], 0, 5);
        $end = substr((string) $data['end_time'], 0, 5);

        $clash = Appointment::with('customer:id,name')
            ->where('employee_id', $data['employee_id'])
            ->whereDate('appointment_date', $date)
            ->whereNotIn('status', Appointment::FREE_STATUSES)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where('start_time', '<', $end.':00')
            ->where('end_time', '>', $start.':00')
            ->first();

        if ($clash) {
            $from = substr((string) $clash->start_time, 0, 5);
            $to = substr((string) $clash->end_time, 0, 5);
            throw new BusinessException("El especialista ya tiene una cita de {$from} a {$to} con {$clash->customer?->name}.");
        }
    }
}
