<?php

declare(strict_types=1);

namespace Modules\Agenda\Http\Controllers\Api;

use App\Core\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Agenda\Http\Requests\AppointmentRequest;
use Modules\Agenda\Http\Resources\AppointmentResource;
use Modules\Agenda\Models\Appointment;
use Modules\Agenda\Services\AppointmentService;

class AppointmentController extends ApiController
{
    public function __construct(private readonly AppointmentService $service) {}

    /** Agenda paginada; `all=1` devuelve el día o rango completo (vista de calendario). */
    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('all')) {
            return $this->ok(AppointmentResource::collection($this->service->all($request->all())));
        }

        return $this->ok(AppointmentResource::collection($this->service->list($request->all()))->response()->getData(true));
    }

    /** Qué número de visita sería una cita nueva del cliente en esa fecha (formulario). */
    public function visitNumber(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        return $this->ok(['visit_number' => $this->service->nextVisit((int) $data['customer_id'], $data['date'])]);
    }

    public function store(AppointmentRequest $request): JsonResponse
    {
        return $this->created(new AppointmentResource($this->service->create($request->validated())), 'Cita registrada.');
    }

    public function show(int $appointment): JsonResponse
    {
        return $this->ok(new AppointmentResource($this->service->find($appointment)));
    }

    public function update(AppointmentRequest $request, int $appointment): JsonResponse
    {
        return $this->ok(new AppointmentResource($this->service->update($appointment, $request->validated())), 'Cita actualizada.');
    }

    /** Cambio rápido de estado (confirmar, no se presentó, cancelar…). */
    public function status(Request $request, int $appointment): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(Appointment::STATUSES)]]);

        return $this->ok(new AppointmentResource($this->service->changeStatus($appointment, $data['status'])), 'Estado actualizado.');
    }

    public function destroy(int $appointment): JsonResponse
    {
        $this->service->delete($appointment);

        return $this->noContent('Cita eliminada.');
    }
}
