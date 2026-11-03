<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Agenda\Http\Controllers\Api\AppointmentController;

/*
| Rutas del módulo Agenda (prefijo /api/v1). Sesión + permisos por acción.
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::controller(AppointmentController::class)->prefix('appointments')->group(function (): void {
        Route::get('/', 'index')->middleware('permission:appointments.view');
        Route::get('visit-number', 'visitNumber')->middleware('permission:appointments.view');
        Route::post('/', 'store')->middleware('permission:appointments.create');
        Route::get('{appointment}', 'show')->whereNumber('appointment')->middleware('permission:appointments.view');
        Route::match(['put', 'patch'], '{appointment}', 'update')->whereNumber('appointment')->middleware('permission:appointments.edit');
        Route::post('{appointment}/status', 'status')->whereNumber('appointment')->middleware('permission:appointments.edit');
        Route::post('{appointment}/reschedule', 'reschedule')->whereNumber('appointment')->middleware('permission:appointments.edit');
        Route::delete('{appointment}', 'destroy')->whereNumber('appointment')->middleware('permission:appointments.delete');
    });
});
