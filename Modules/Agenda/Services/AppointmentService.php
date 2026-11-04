<?php

declare(strict_types=1);

namespace Modules\Agenda\Services;

use App\Core\Exceptions\BusinessException;
use App\Core\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Agenda\Models\Appointment;
use Modules\Agenda\Repositories\AppointmentRepository;

/**
 * Agenda del centro. Un especialista no puede tener dos citas que se crucen
 * (las canceladas, reprogramadas o sin asistencia ya no ocupan el horario).
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

    /**
     * Varios servicios en la misma visita: una cita por servicio, cada una con
     * su especialista y horario, todas o ninguna. Si un especialista no está
     * libre, el error queda en la línea de ese servicio.
     *
     * @param  array<string, mixed>  $data  customer_id, appointment_date, title?, notes?,
     *                                      services[]{service_id, employee_id, start_time, end_time}
     * @return list<Appointment>
     */
    public function createMany(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $common = Arr::only($data, ['customer_id', 'appointment_date', 'title', 'notes']);
            $appointments = [];

            foreach (array_values($data['services']) as $i => $line) {
                try {
                    $appointments[] = $this->create(Arr::only($line, ['service_id', 'employee_id', 'start_time', 'end_time']) + $common);
                } catch (BusinessException $e) {
                    throw new BusinessException($e->getMessage(), 422, ["services.{$i}.employee_id" => [$e->getMessage()]]);
                }
            }

            return $appointments;
        });
    }

    public function update(int|string $id, array $data): Model
    {
        $data = $this->normalizeTimes($data);
        /** @var Appointment $current */
        $current = $this->repository->findOrFail($id);

        // Ya atendida: horario, servicio y especialista quedaron en la atención
        // (comisión, sesión, insumos). Solo se corrigen el título y las notas.
        if ($current->status === Appointment::STATUS_ATTENDED) {
            return $this->withVisit(parent::update($id, Arr::only($data, ['title', 'notes']))->load(['customer', 'service', 'employee']));
        }

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
     * El cliente avisó y pasa a otra fecha: la cita original queda como
     * «Reprogramó» (libera su horario) y se crea la nueva, enlazada a ella.
     *
     * @param  array{appointment_date: string, start_time: string, end_time: string, employee_id?: int|null}  $data
     */
    public function reschedule(int $id, array $data): Appointment
    {
        return DB::transaction(function () use ($id, $data): Appointment {
            /** @var Appointment $original */
            $original = Appointment::with('rescheduledTo:id,rescheduled_from_id')->lockForUpdate()->findOrFail($id);

            if (! in_array($original->status, Appointment::RESCHEDULABLE_STATUSES, true) || $original->rescheduledTo) {
                throw new BusinessException('Esta cita ya no se puede reprogramar.');
            }

            // Primero se libera su horario, así puede moverse a una hora que se cruce con la de antes.
            $original->update(['status' => Appointment::STATUS_POSTPONED]);

            /** @var Appointment $new */
            $new = $this->create([
                'customer_id' => $original->customer_id,
                'service_id' => $original->service_id,
                'employee_id' => $data['employee_id'] ?? $original->employee_id,
                'appointment_date' => $data['appointment_date'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'title' => $original->title,
                'notes' => $original->notes,
                'rescheduled_from_id' => $original->id,
            ]);

            return $new->load('rescheduledFrom:id,appointment_date,start_time');
        });
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
