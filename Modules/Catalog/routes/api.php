<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Catalog\Http\Controllers\Api\BrandController;
use Modules\Catalog\Http\Controllers\Api\CategoryController;
use Modules\Catalog\Http\Controllers\Api\ProductController;
use Modules\Catalog\Http\Controllers\Api\UnitController;

/*
| Rutas del módulo Catálogo (prefijo global /api/v1). Todas requieren sesión
| (Sanctum) y un permiso específico por acción (spatie/laravel-permission).
*/
Route::middleware('auth:sanctum')->group(function (): void {
    // --- Productos ---
    Route::controller(ProductController::class)->prefix('products')->group(function (): void {
        Route::get('/', 'index')->middleware('permission:products.view');
        Route::post('/', 'store')->middleware('permission:products.create');
        Route::get('scan/{barcode}', 'scan')->middleware('permission:products.view');
        Route::get('export', 'export')->middleware('permission:products.export');
        Route::post('bulk-destroy', 'bulkDestroy')->middleware('permission:products.delete');
        Route::get('{product}', 'show')->whereNumber('product')->middleware('permission:products.view');
        Route::get('{product}/label', 'label')->whereNumber('product')->middleware('permission:products.print');
        Route::post('{product}/web', 'publish')->whereNumber('product')->middleware('permission:products.edit');
        Route::post('{product}/promotion', 'promote')->whereNumber('product')->middleware('permission:products.edit');
        Route::match(['put', 'patch', 'post'], '{product}', 'update')->whereNumber('product')->middleware('permission:products.edit');
        Route::delete('{product}', 'destroy')->whereNumber('product')->middleware('permission:products.delete');
    });

    // --- Categorías ---
    Route::apiResource('categories', CategoryController::class)->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('index', 'permission:categories.view')
        ->middlewareFor('show', 'permission:categories.view')
        ->middlewareFor('store', 'permission:categories.create')
        ->middlewareFor('update', 'permission:categories.edit')
        ->middlewareFor('destroy', 'permission:categories.delete');

    // --- Marcas ---
    Route::apiResource('brands', BrandController::class)->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('index', 'permission:brands.view')
        ->middlewareFor('show', 'permission:brands.view')
        ->middlewareFor('store', 'permission:brands.create')
        ->middlewareFor('update', 'permission:brands.edit')
        ->middlewareFor('destroy', 'permission:brands.delete');

    // --- Unidades ---
    Route::apiResource('units', UnitController::class)->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('index', 'permission:units.view')
        ->middlewareFor('show', 'permission:units.view')
        ->middlewareFor('store', 'permission:units.create')
        ->middlewareFor('update', 'permission:units.edit')
        ->middlewareFor('destroy', 'permission:units.delete');
});
