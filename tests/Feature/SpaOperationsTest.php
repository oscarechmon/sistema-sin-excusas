<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Agenda\Models\Appointment;
use Modules\Attendances\Models\Attendance;
use Modules\Cashbox\Services\CashboxService;
use Modules\Catalog\Models\Product;
use Modules\Commissions\Models\Commission;
use Modules\Commissions\Models\CommissionRule;
use Modules\Contacts\Models\Customer;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Services\StockService;
use Modules\Packages\Models\CustomerPackage;
use Modules\Packages\Models\Package;
use Modules\Sales\Models\Sale;
use Modules\Sales\Services\SaleService;
use Modules\Staff\Models\Employee;
use Tests\TestCase;

/**
 * Lo que antes se hacía en el panel de la web y ahora vive en el sistema:
 * personal, paquetes, agenda, atenciones, comisiones y ventas con saldo.
 */
class SpaOperationsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{company: \Modules\Settings\Models\Company, branch: \Modules\Settings\Models\Branch, warehouse: \Modules\Settings\Models\Warehouse} */
    private array $env;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->env = $this->seedErp();
        $this->admin = $this->actingAsRole('Administrador', $this->env['company'], $this->env['branch']);
    }

    private function service(float $price = 100, string $name = 'Limpieza facial'): Product
    {
        return Product::factory()->service()->create(['name' => $name, 'price' => $price, 'is_active' => true]);
    }

    private function supply(float $stock): Product
    {
        $product = Product::factory()->create(['name' => 'Gel conductor', 'is_active' => true]);
        app(StockService::class)->entry($product->id, $this->env['warehouse']->id, $stock, 5);

        return $product;
    }

    private function employee(array $serviceIds = []): Employee
    {
        $id = $this->postJson('/api/v1/employees', ['name' => 'Rosa Quispe', 'position' => 'Cosmiatra', 'service_ids' => $serviceIds])
            ->assertCreated()->json('data.id');

        return Employee::findOrFail($id);
    }

    private function package(Product $service, int $sessions = 4, float $price = 400): Package
    {
        $id = $this->postJson('/api/v1/packages', [
            'name' => "{$sessions} sesiones",
            'price' => $price,
            'total_sessions' => $sessions,
            'validity_days' => 90,
            'service_ids' => [$service->id],
        ])->assertCreated()->json('data.id');

        return Package::findOrFail($id);
    }

    private function stockOf(Product $product): float
    {
        return app(StockService::class)->available($product->id, $this->env['warehouse']->id);
    }

    // ---------------------------------------------------------------- Personal

    public function test_el_personal_se_registra_con_los_servicios_que_atiende(): void
    {
        $facial = $this->service();
        $masaje = $this->service(90, 'Masaje');
        $employee = $this->employee([$facial->id]);

        $this->assertSame([$facial->id], $employee->services()->pluck('products.id')->all());

        $forFacial = collect($this->getJson("/api/v1/employees?all=1&service_id={$facial->id}")->assertOk()->json('data'))->pluck('id');
        $forMasaje = collect($this->getJson("/api/v1/employees?all=1&service_id={$masaje->id}")->assertOk()->json('data'))->pluck('id');

        $this->assertContains($employee->id, $forFacial->all());
        $this->assertNotContains($employee->id, $forMasaje->all(), 'Solo aparece en los servicios que atiende.');
    }

    public function test_un_especialista_no_administra_el_personal(): void
    {
        $this->actingAsRole('Especialista');

        $this->postJson('/api/v1/employees', ['name' => 'Otra'])->assertForbidden();
    }

    // ---------------------------------------------------------------- Paquetes

    public function test_un_paquete_crea_su_producto_vendible(): void
    {
        $package = $this->package($this->service());

        $product = $package->product;
        $this->assertSame(Product::TYPE_PACKAGE, $product->type);
        $this->assertFalse($product->track_stock);
        $this->assertEquals(400.0, (float) $product->price);

        $this->putJson("/api/v1/products/{$product->id}", ['name' => 'Otro'])->assertStatus(422);
    }

    public function test_vender_un_paquete_exige_cliente_y_le_crea_el_saldo_de_sesiones(): void
    {
        $package = $this->package($this->service());
        $sale = fn (array $extra) => $this->postJson('/api/v1/sales', $extra + [
            'doc_type' => 'ticket',
            'warehouse_id' => $this->env['warehouse']->id,
            'items' => [['product_id' => $package->product_id, 'quantity' => 1]],
            'payments' => [['method' => 'yape', 'amount' => 400]],
        ]);

        $sale([])->assertStatus(422);
        $this->assertSame(0, Sale::count(), 'Sin cliente la venta no se guarda.');

        $customer = Customer::factory()->create();
        $saleId = $sale(['customer_id' => $customer->id])->assertCreated()->json('data.id');

        $owned = CustomerPackage::where('customer_id', $customer->id)->sole();
        $this->assertSame($saleId, $owned->sale_id);
        $this->assertSame(4, $owned->total_sessions);
        $this->assertSame(CustomerPackage::STATUS_ACTIVE, $owned->status);
        $this->assertSame(today()->addDays(90)->toDateString(), $owned->expires_at->toDateString());
    }

    public function test_anular_la_venta_anula_el_paquete_salvo_que_ya_tenga_sesiones(): void
    {
        $service = $this->service();
        $package = $this->package($service);
        $customer = Customer::factory()->create();
        $sales = app(SaleService::class);

        $first = $sales->checkout([
            'doc_type' => 'ticket', 'customer_id' => $customer->id,
            'items' => [['product_id' => $package->product_id, 'quantity' => 1]],
            'payments' => [['method' => 'efectivo', 'amount' => 400]],
        ]);
        $sales->cancel($first);
        $this->assertSame(CustomerPackage::STATUS_CANCELLED, CustomerPackage::where('sale_id', $first->id)->value('status'));

        $second = $sales->checkout([
            'doc_type' => 'ticket', 'customer_id' => $customer->id,
            'items' => [['product_id' => $package->product_id, 'quantity' => 1]],
            'payments' => [['method' => 'efectivo', 'amount' => 400]],
        ]);
        $owned = CustomerPackage::where('sale_id', $second->id)->sole();
        $this->postJson('/api/v1/attendances', [
            'customer_id' => $customer->id, 'service_id' => $service->id, 'employee_id' => $this->employee()->id,
            'customer_package_id' => $owned->id, 'attended_at' => today()->toDateString(),
        ])->assertCreated();

        $this->expectException(BusinessException::class);
        $sales->cancel($second->fresh());
    }

    // ------------------------------------------------------------------ Agenda

    public function test_un_especialista_no_puede_tener_dos_citas_cruzadas(): void
    {
        $service = $this->service();
        $employee = $this->employee();
        $customer = Customer::factory()->create();
        $date = today()->addDay()->toDateString();
        $book = fn (string $from, string $to) => $this->postJson('/api/v1/appointments', [
            'customer_id' => $customer->id, 'service_id' => $service->id, 'employee_id' => $employee->id,
            'appointment_date' => $date, 'start_time' => $from, 'end_time' => $to,
        ]);

        $first = $book('10:00', '11:00')->assertCreated()->json('data.id');
        $book('10:30', '11:30')->assertStatus(422);
        $book('11:00', '12:00')->assertCreated();

        // Cancelada, ya no ocupa el horario.
        $this->postJson("/api/v1/appointments/{$first}/status", ['status' => 'cancelled'])->assertOk();
        $book('10:00', '10:45')->assertCreated();
    }

    public function test_la_cita_lleva_titulo_y_el_numero_de_visita_del_cliente(): void
    {
        $service = $this->service();
        $employee = $this->employee();
        $customer = Customer::factory()->create();
        $book = fn (int $inDays, string $from, string $to, array $extra = []) => $this->postJson('/api/v1/appointments', [
            'customer_id' => $customer->id, 'service_id' => $service->id, 'employee_id' => $employee->id,
            'appointment_date' => today()->addDays($inDays)->toDateString(), 'start_time' => $from, 'end_time' => $to,
        ] + $extra)->assertCreated();

        // Vino una vez sin cita (o llegó así desde la web): esa ya es su visita 1.
        Attendance::create([
            'customer_id' => $customer->id, 'service_id' => $service->id, 'employee_id' => $employee->id,
            'attended_at' => today()->subDays(10)->toDateString(),
        ]);

        $book(1, '10:00', '11:00', ['title' => '  Evaluación inicial  '])
            ->assertJsonPath('data.title', 'Evaluación inicial')
            ->assertJsonPath('data.visit_number', 2);
        $book(1, '15:00', '16:00')->assertJsonPath('data.visit_number', 2); // mismo día, misma visita
        $cancelled = $book(3, '10:00', '11:00')->json('data.id');
        $this->postJson("/api/v1/appointments/{$cancelled}/status", ['status' => 'cancelled'])
            ->assertOk()->assertJsonPath('data.visit_number', null);
        $book(5, '10:00', '11:00')->assertJsonPath('data.visit_number', 3); // la cancelada no cuenta

        $this->getJson('/api/v1/appointments?all=1&customer_id='.$customer->id)
            ->assertOk()
            ->assertJsonPath('data.0.visit_number', 2)
            ->assertJsonPath('data.3.visit_number', 3);
        $this->getJson('/api/v1/appointments/visit-number?customer_id='.$customer->id.'&date='.today()->addDays(7)->toDateString())
            ->assertOk()->assertJsonPath('data.visit_number', 4);
    }

    // -------------------------------------------------------------- Atenciones

    public function test_una_atencion_consume_sesion_descuenta_insumos_cierra_la_cita_y_genera_comision(): void
    {
        $service = $this->service(150);
        $gel = $this->supply(10);
        $employee = $this->employee([$service->id]);
        $customer = Customer::factory()->create();
        $owned = app(\Modules\Packages\Services\CustomerPackageService::class)->assign($this->package($service, 4, 400), $customer->id);

        CommissionRule::create(['type' => 'percentage', 'value' => 10, 'is_active' => true]);
        CommissionRule::create(['employee_id' => $employee->id, 'type' => 'percentage', 'value' => 20, 'is_active' => true]);

        $appointment = Appointment::create([
            'customer_id' => $customer->id, 'service_id' => $service->id, 'employee_id' => $employee->id,
            'appointment_date' => today()->toDateString(), 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'status' => 'confirmed',
        ]);

        $id = $this->postJson('/api/v1/attendances', [
            'customer_id' => $customer->id, 'service_id' => $service->id, 'employee_id' => $employee->id,
            'appointment_id' => $appointment->id, 'customer_package_id' => $owned->id,
            'attended_at' => today()->toDateString(),
            'supplies' => [['product_id' => $gel->id, 'quantity' => 2]],
        ])->assertCreated()->assertJsonPath('data.session_number', 1)->json('data.id');

        $this->assertSame(1, $owned->fresh()->used_sessions);
        $this->assertEquals(8.0, $this->stockOf($gel));
        $this->assertTrue(InventoryMovement::where('reference_type', Attendance::class)->where('reference_id', $id)->exists(), 'El insumo deja su kardex.');
        $this->assertSame(Appointment::STATUS_ATTENDED, $appointment->fresh()->status);

        // Regla más específica (del empleado, 20%) sobre el valor de la sesión: 400 / 4 = 100.
        $commission = Commission::where('attendance_id', $id)->sole();
        $this->assertEquals(100.0, (float) $commission->base_amount);
        $this->assertEquals(20.0, (float) $commission->amount);
    }

    public function test_si_un_insumo_no_alcanza_no_se_registra_nada(): void
    {
        $service = $this->service();
        $gel = $this->supply(1);
        $customer = Customer::factory()->create();
        $owned = app(\Modules\Packages\Services\CustomerPackageService::class)->assign($this->package($service), $customer->id);

        $this->postJson('/api/v1/attendances', [
            'customer_id' => $customer->id, 'service_id' => $service->id, 'employee_id' => $this->employee()->id,
            'customer_package_id' => $owned->id, 'attended_at' => today()->toDateString(),
            'supplies' => [['product_id' => $gel->id, 'quantity' => 5]],
        ])->assertStatus(422);

        $this->assertSame(0, Attendance::count());
        $this->assertSame(0, $owned->fresh()->used_sessions);
        $this->assertEquals(1.0, $this->stockOf($gel));
    }

    public function test_un_paquete_ajeno_o_sin_el_servicio_no_se_puede_usar(): void
    {
        $service = $this->service();
        $otro = $this->service(80, 'Otro servicio');
        $customer = Customer::factory()->create();
        $owned = app(\Modules\Packages\Services\CustomerPackageService::class)->assign($this->package($service), $customer->id);
        $employee = $this->employee();

        $this->postJson('/api/v1/attendances', [
            'customer_id' => Customer::factory()->create()->id, 'service_id' => $service->id, 'employee_id' => $employee->id,
            'customer_package_id' => $owned->id, 'attended_at' => today()->toDateString(),
        ])->assertStatus(422);

        $this->postJson('/api/v1/attendances', [
            'customer_id' => $customer->id, 'service_id' => $otro->id, 'employee_id' => $employee->id,
            'customer_package_id' => $owned->id, 'attended_at' => today()->toDateString(),
        ])->assertStatus(422);
    }

    // -------------------------------------------------------------- Comisiones

    public function test_las_comisiones_se_pagan_una_vez(): void
    {
        $employee = $this->employee();
        $commission = Commission::create([
            'employee_id' => $employee->id, 'base_amount' => 100, 'type' => 'fixed', 'value' => 15,
            'amount' => 15, 'status' => 'pending', 'generated_at' => today(),
        ]);

        $this->getJson('/api/v1/commissions')->assertOk()->assertJsonPath('data.totals.pending', 15);
        $this->postJson('/api/v1/commissions/pay', ['ids' => [$commission->id]])->assertOk()->assertJsonPath('data.paid', 1);
        $this->postJson('/api/v1/commissions/pay', ['ids' => [$commission->id]])->assertOk()->assertJsonPath('data.paid', 0);

        $this->assertSame('paid', $commission->fresh()->status);
    }

    // ------------------------------------------------------ Ventas con saldo

    public function test_una_venta_puede_quedar_con_saldo_y_cobrarse_despues(): void
    {
        $service = $this->service(200);

        $payload = [
            'doc_type' => 'ticket',
            'allow_balance' => true,
            'items' => [['product_id' => $service->id, 'quantity' => 1]],
            'payments' => [['method' => 'efectivo', 'amount' => 50]],
        ];

        $this->postJson('/api/v1/sales', $payload)->assertStatus(422)->assertJsonValidationErrors('customer_id');

        $customer = Customer::factory()->create();
        $sale = $this->postJson('/api/v1/sales', $payload + ['customer_id' => $customer->id])
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.balance', 150)
            ->json('data');

        $this->postJson("/api/v1/sales/{$sale['id']}/payments", ['method' => 'yape', 'amount' => 200])->assertStatus(422);
        $this->postJson("/api/v1/sales/{$sale['id']}/payments", ['method' => 'yape', 'amount' => 100])->assertJsonPath('data.balance', 50);
        $this->postJson("/api/v1/sales/{$sale['id']}/payments", ['method' => 'efectivo', 'amount' => 50])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.balance', 0);
    }

    public function test_la_caja_cuenta_el_efectivo_cobrado_en_el_turno_y_descuenta_el_vuelto(): void
    {
        $service = $this->service(100);
        $customer = Customer::factory()->create();
        $sales = app(SaleService::class);
        $cash = app(CashboxService::class);

        // Venta de ayer con saldo, antes de abrir el turno.
        $old = $sales->checkout([
            'doc_type' => 'ticket', 'customer_id' => $customer->id, 'allow_balance' => true,
            'items' => [['product_id' => $service->id, 'quantity' => 1]], 'payments' => [],
        ]);
        $old->forceFill(['sold_at' => now()->subDay()])->save();

        $cash->open($this->admin->id, null, 20);
        $this->travel(1)->minutes();

        $sales->addPayment($old, 'efectivo', 60);           // saldo cobrado hoy: +60
        $sales->checkout([                                  // paga 150 por 100: +150 - 50 de vuelto
            'doc_type' => 'ticket',
            'items' => [['product_id' => $service->id, 'quantity' => 1]],
            'payments' => [['method' => 'efectivo', 'amount' => 150]],
        ]);

        $session = $cash->close($this->admin->id, 180);

        $this->assertEquals(160.0, (float) $session->cash_sales);
        $this->assertEquals(180.0, (float) $session->expected_amount);
        $this->assertEquals(0.0, (float) $session->difference);
    }
}
