<?php

declare(strict_types=1);

namespace Modules\Agenda\Repositories;

use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Agenda\Models\Appointment;

class AppointmentRepository extends BaseRepository
{
    protected array $searchable = ['title', 'notes'];

    protected array $filterable = ['customer_id', 'employee_id', 'service_id', 'status'];

    protected array $sortable = ['id', 'appointment_date', 'start_time', 'created_at'];

    protected array $defaultWith = [
        'customer:id,code,name,phone,whatsapp', 'service:id,name', 'employee:id,name',
        'rescheduledFrom:id,appointment_date,start_time', 'rescheduledTo:id,rescheduled_from_id,appointment_date,start_time',
    ];

    protected function model(): Model
    {
        return new Appointment;
    }

    /**
     * Filtros de la agenda: un día (`date`) o un rango (`from`/`to`), y la
     * búsqueda por cliente. Por defecto se ordena por fecha y hora.
     */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['date'])) {
            $query->whereDate('appointment_date', $filters['date']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('appointment_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('appointment_date', '<=', $filters['to']);
        }

        if (! empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            $query->where(fn (Builder $q) => $q
                ->where('title', 'like', "%{$term}%")
                ->orWhere('notes', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")));
            unset($filters['search']);
        }

        if (empty($filters['sort_by'])) {
            $query->orderBy('appointment_date')->orderBy('start_time');
            $filters['sort_by'] = '__none';
        }

        return parent::applyFilters($query, $filters);
    }
}
