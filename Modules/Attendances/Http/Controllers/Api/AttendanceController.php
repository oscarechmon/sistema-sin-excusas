<?php

declare(strict_types=1);

namespace Modules\Attendances\Http\Controllers\Api;

use App\Core\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Attendances\Http\Requests\AttendanceBatchRequest;
use Modules\Attendances\Http\Requests\AttendanceRequest;
use Modules\Attendances\Http\Resources\AttendanceResource;
use Modules\Attendances\Repositories\AttendanceRepository;
use Modules\Attendances\Services\AttendanceService;

class AttendanceController extends ApiController
{
    public function __construct(
        private readonly AttendanceRepository $repository,
        private readonly AttendanceService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->ok(AttendanceResource::collection($this->repository->paginate($request->all()))->response()->getData(true));
    }

    public function show(int $attendance): JsonResponse
    {
        return $this->ok(new AttendanceResource($this->repository->findOrFail($attendance, ['supplies.product', 'customerPackage'])));
    }

    public function store(AttendanceRequest $request): JsonResponse
    {
        $attendance = $this->service->confirm($request->validated(), (int) auth()->id());

        return $this->created(new AttendanceResource($attendance), 'Atención registrada.');
    }

    /** Varios servicios en la misma visita: una atención por cada uno. */
    public function storeMany(AttendanceBatchRequest $request): JsonResponse
    {
        $attendances = $this->service->confirmMany($request->validated(), (int) auth()->id());
        $message = count($attendances) === 1 ? 'Atención registrada.' : count($attendances).' atenciones registradas.';

        return $this->created(AttendanceResource::collection($attendances), $message);
    }
}
