<?php

declare(strict_types=1);

namespace Modules\Attendances\Services;

use App\Core\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;
use Modules\Agenda\Models\Appointment;
use Modules\Attendances\Models\Attendance;
use Modules\Catalog\Models\Product;
use Modules\Commissions\Services\CommissionService;
use Modules\Inventory\Services\DefaultWarehouse;
use Modules\Inventory\Services\StockService;
use Modules\Packages\Models\CustomerPackage;
use Modules\Packages\Services\CustomerPackageService;

/**
 * Confirma una atención, el proceso central del centro.
 *
 * Encadena efectos que ocurren juntos o no ocurren:
 *
 *   atención → sesión de paquete → salida de insumos (kardex)
 *            → cita atendida → comisión
 *
 * Todo en una transacción: si un insumo no alcanza, no queda la atención
 * registrada ni la sesión consumida.
 */
class AttendanceService
{
    public function __construct(
        private readonly CustomerPackageService $packages,
        private readonly CommissionService $commissions,
        private readonly StockService $stock,
        private readonly DefaultWarehouse $warehouse,
    ) {}

    /**
     * @param  array<string, mixed>  $data  customer_id, service_id, employee_id, appointment_id?,
     *                                      customer_package_id?, attended_at, observations?, measurements?,
     *                                      supplies[]{product_id, quantity}
     */
    public function confirm(array $data, ?int $userId = null): Attendance
    {
        return DB::transaction(function () use ($data, $userId): Attendance {
            /** @var Product $service */
            $service = Product::where('type', Product::TYPE_SERVICE)->findOrFail($data['service_id']);
            $customerPackage = $this->customerPackage($data);

            $attendance = Attendance::create([
                'customer_id' => $data['customer_id'],
                'service_id' => $service->id,
                'employee_id' => $data['employee_id'],
                'appointment_id' => $data['appointment_id'] ?? null,
                'customer_package_id' => $customerPackage?->id,
                'attended_at' => $data['attended_at'] ?? today()->toDateString(),
                'observations' => $data['observations'] ?? null,
                'measurements' => $data['measurements'] ?? null,
                'created_by' => $userId,
            ]);

            if ($customerPackage) {
                $session = $this->packages->consumeSession($customerPackage, $attendance->id, $userId);
                $attendance->update(['session_number' => $session->session_number]);
            }

            $this->discountSupplies($attendance, $service, $data['supplies'] ?? []);

            if (! empty($data['appointment_id'])) {
                Appointment::whereKey($data['appointment_id'])->update(['status' => Appointment::STATUS_ATTENDED]);
            }

            // Si la atención salió de un paquete, la base es el valor de la
            // sesión (precio del paquete / sesiones), no el precio de lista;
            // si no, lo que se cobra hoy (el de promoción, si está en promoción).
            $base = $customerPackage
                ? round((float) $customerPackage->price / max(1, $customerPackage->total_sessions), 2)
                : $service->salePrice();

            $this->commissions->generateForAttendance(
                $attendance->id,
                (int) $attendance->employee_id,
                $service->id,
                $base,
                $attendance->attended_at->toDateString(),
            );

            return $attendance->load(['customer', 'service', 'employee', 'supplies.product', 'commission', 'customerPackage']);
        });
    }

    /** El paquete tiene que ser del cliente e incluir el servicio. */
    private function customerPackage(array $data): ?CustomerPackage
    {
        if (empty($data['customer_package_id'])) {
            return null;
        }

        $package = CustomerPackage::with('package.services:id')->findOrFail($data['customer_package_id']);

        if ((int) $package->customer_id !== (int) $data['customer_id']) {
            throw new BusinessException('Ese paquete es de otro cliente.');
        }

        $services = $package->package?->services->pluck('id')->all() ?? [];
        if ($services !== [] && ! in_array((int) $data['service_id'], $services, true)) {
            throw new BusinessException("El paquete «{$package->package_name}» no incluye este servicio.");
        }

        return $package;
    }

    /**
     * Registra los insumos y los saca del almacén por defecto. Si alguno no
     * alcanza, StockService lanza la excepción y se revierte todo.
     *
     * @param  list<array{product_id:int, quantity:float}>  $supplies
     */
    private function discountSupplies(Attendance $attendance, Product $service, array $supplies): void
    {
        $products = Product::whereIn('id', array_column($supplies, 'product_id'))->get()->keyBy('id');

        foreach ($supplies as $supply) {
            $product = $products->get($supply['product_id']);
            $quantity = (float) $supply['quantity'];

            if (! $product || $quantity <= 0) {
                continue;
            }

            $attendance->supplies()->create(['product_id' => $product->id, 'quantity' => $quantity]);

            if ($product->track_stock) {
                $this->stock->exit(
                    $product->id,
                    $this->warehouse->id(),
                    $quantity,
                    $attendance,
                    "Atención #{$attendance->id}: {$service->name}",
                );
            }
        }
    }
}
