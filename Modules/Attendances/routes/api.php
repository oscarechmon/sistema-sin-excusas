<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Attendances\Http\Controllers\Api\AttendanceController;
use Modules\Attendances\Http\Controllers\Api\ServiceSupplyController;

/*
| Rutas del módulo Atenciones (prefijo /api/v1). Sesión + permisos por acción.
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::controller(AttendanceController::class)->prefix('attendances')->group(function (): void {
        Route::get('/', 'index')->middleware('permission:attendances.view');
        Route::post('/', 'store')->middleware('permission:attendances.create');
        Route::post('batch', 'storeMany')->middleware('permission:attendances.create');
        Route::get('{attendance}', 'show')->whereNumber('attendance')->middleware('permission:attendances.view');
    });

    Route::get('services/{service}/supplies', [ServiceSupplyController::class, 'index'])
        ->whereNumber('service')->middleware('permission:attendances.view|products.view');
    Route::put('services/{service}/supplies', [ServiceSupplyController::class, 'update'])
        ->whereNumber('service')->middleware('permission:products.edit');
});
