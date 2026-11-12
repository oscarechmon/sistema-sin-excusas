<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Modules\Catalog\Models\Brand;
use Modules\Catalog\Models\Product;
use Modules\Integration\Services\CatalogNotifier;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Services\StockService;
use Modules\Sales\Models\Sale;
use Modules\Sales\Services\SaleService;
use Modules\Settings\Models\Warehouse;
use Tests\TestCase;

/**
 * API que usa la web (sin_excusas): catálogo, alta inicial, ventas web,
 * anulaciones y consumo de insumos, más el aviso de cambios hacia la web.
 */
class IntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = $this->seedErp()['warehouse'];
        config(['integration.token' => 'secreto-de-prueba', 'integration.web_url' => null]);
    }

    private function api(string $method, string $uri, array $data = []): TestResponse
    {
        return $this->withHeader('X-Integration-Token', 'secreto-de-prueba')
            ->json($method, "/api/v1/integration/{$uri}", $data);
    }

    private function stockOf(Product $product): float
    {
        return app(StockService::class)->available($product->id, $this->warehouse->id);
    }

    private function productWithStock(float $quantity, array $attributes = []): Product
    {
        $product = Product::factory()->create(['is_active' => true] + $attributes);
        app(StockService::class)->entry($product->id, $this->warehouse->id, $quantity, 10);

        return $product;
    }

    // ------------------------------------------------------------------ Acceso

    public function test_sin_token_configurado_la_api_no_existe(): void
    {
        config(['integration.token' => '']);

        $this->getJson('/api/v1/integration/catalog')->assertNotFound();
    }

    public function test_un_token_incorrecto_no_entra(): void
    {
        $this->getJson('/api/v1/integration/catalog')->assertForbidden();
        $this->withHeader('X-Integration-Token', 'otro')->getJson('/api/v1/integration/catalog')->assertForbidden();
    }

    // ---------------------------------------------------------------- Catálogo

    public function test_el_catalogo_trae_productos_y_servicios_con_el_stock_de_la_web(): void
    {
        $crema = $this->productWithStock(7, ['name' => 'Crema', 'price' => 55]);
        $facial = Product::factory()->service()->create(['name' => 'Limpieza facial', 'price' => 120, 'is_active' => true]);
        $retirado = Product::factory()->create(['is_active' => true]);
        $retirado->delete();

        $items = collect($this->api('GET', 'catalog')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('product', $items[$crema->id]['type']);
        $this->assertEquals(7.0, $items[$crema->id]['stock']);
        $this->assertEquals(55.0, $items[$crema->id]['price']);

        $this->assertSame('service', $items[$facial->id]['type']);
        $this->assertNull($items[$facial->id]['stock'], 'Un servicio no lleva stock.');

        $this->assertFalse($items[$retirado->id]['active'], 'Lo eliminado llega como inactivo para que la web lo oculte.');
    }

    public function test_el_catalogo_trae_marca_y_codigo_de_barras(): void
    {
        $marca = Brand::factory()->create(['name' => 'Nutricost']);
        $omega = Product::factory()->create(['brand_id' => $marca->id, 'barcode' => '810014671147']);
        $sinMarca = Product::factory()->create(['brand_id' => null, 'barcode' => null]);

        $items = collect($this->api('GET', 'catalog')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('Nutricost', $items[$omega->id]['brand']);
        $this->assertSame('810014671147', $items[$omega->id]['barcode']);
        $this->assertNull($items[$sinMarca->id]['brand']);
    }

    public function test_el_catalogo_se_puede_pedir_por_ids(): void
    {
        $uno = Product::factory()->create();
        Product::factory()->create();

        $items = $this->api('GET', 'catalog?ids[]='.$uno->id)->assertOk()->json('data');

        $this->assertCount(1, $items);
        $this->assertSame($uno->id, $items[0]['id']);
    }

    // ------------------------------------------------------------ Alta inicial

    public function test_importar_crea_el_producto_con_su_stock_inicial_y_no_duplica(): void
    {
        $payload = [
            'code' => 'WEB-P-12', 'type' => 'product', 'name' => 'Protector solar SPF50',
            'category' => 'Cosmética', 'price' => 65, 'cost' => 30, 'stock' => 8, 'stock_min' => 2,
        ];

        $id = $this->api('POST', 'products', $payload)->assertOk()->json('data.id');
        $this->api('POST', 'products', $payload)->assertOk()->assertJsonPath('data.id', $id);

        $product = Product::findOrFail($id);
        $this->assertSame(1, Product::where('code', 'WEB-P-12')->count());
        $this->assertSame('Cosmética', $product->category->name);
        $this->assertEquals(8.0, $this->stockOf($product));
        $this->assertSame(1, InventoryMovement::where('product_id', $id)->count(), 'El reintento no vuelve a cargar stock.');
    }

    public function test_importar_un_servicio_no_le_pone_stock(): void
    {
        $id = $this->api('POST', 'products', [
            'code' => 'WEB-S-3', 'type' => 'service', 'name' => 'Masaje relajante', 'category' => 'Corporales', 'price' => 90,
        ])->assertOk()->json('data.id');

        $product = Product::findOrFail($id);
        $this->assertTrue($product->isService());
        $this->assertFalse($product->track_stock);
    }

    // -------------------------------------------------------------- Venta web

    private function webSale(Product $product, string $reference = 'W-000001'): TestResponse
    {
        return $this->api('POST', 'sales', [
            'reference' => $reference,
            'customer' => ['name' => 'Ana Torres', 'document_number' => '45678912', 'email' => 'ana@example.com'],
            'items' => [['product_id' => $product->id, 'name' => $product->name, 'quantity' => 2, 'price' => 55]],
            'delivery_fee' => 10,
            'payments' => [['method' => 'izipay', 'amount' => 120, 'reference' => 'TX-1']],
        ]);
    }

    public function test_un_pedido_web_se_registra_como_venta_y_descuenta_stock(): void
    {
        $crema = $this->productWithStock(5, ['name' => 'Crema']);

        $this->webSale($crema)
            ->assertOk()
            ->assertJsonPath('data.sale.total', 120)
            ->assertJsonPath("data.stock.{$crema->id}", 3);

        $sale = Sale::where('external_reference', 'W-000001')->firstOrFail();
        $this->assertSame(Sale::CHANNEL_WEB, $sale->channel);
        $this->assertSame('Ana Torres', $sale->customer->name);
        $this->assertSame('45678912', $sale->customer->doc_number);
        $this->assertTrue($sale->items->contains('description', 'Delivery'));
        $this->assertSame('izipay', $sale->payments->first()->method);
        $this->assertEquals(3.0, $this->stockOf($crema));
    }

    public function test_el_mismo_pedido_no_se_registra_dos_veces(): void
    {
        $crema = $this->productWithStock(5);

        $first = $this->webSale($crema)->assertOk()->json('data.sale.id');
        $this->webSale($crema)->assertOk()->assertJsonPath('data.sale.id', $first);

        $this->assertSame(1, Sale::count());
        $this->assertEquals(3.0, $this->stockOf($crema));
    }

    public function test_un_producto_que_ya_no_existe_entra_como_concepto(): void
    {
        $crema = $this->productWithStock(5, ['name' => 'Crema']);
        $crema->forceDelete();

        $this->webSale($crema)->assertOk();

        $item = Sale::firstOrFail()->items->firstWhere('description', 'Crema');
        $this->assertNotNull($item);
        $this->assertNull($item->product_id);
    }

    public function test_anular_el_pedido_devuelve_el_stock_y_se_puede_reintentar(): void
    {
        $crema = $this->productWithStock(5);
        $this->webSale($crema)->assertOk();

        $this->api('POST', 'sales/W-000001/cancel', ['reason' => 'Cliente desistió'])
            ->assertOk()
            ->assertJsonPath('data.sale.status', 'cancelled')
            ->assertJsonPath("data.stock.{$crema->id}", 5);
        $this->api('POST', 'sales/W-000001/cancel')->assertOk();

        $this->assertEquals(5.0, $this->stockOf($crema));
    }

    public function test_anular_un_pedido_que_no_llego_responde_404(): void
    {
        $this->api('POST', 'sales/W-999999/cancel')->assertNotFound();
    }

    // -------------------------------------------------------- Consumo insumos

    public function test_el_consumo_de_una_atencion_descuenta_una_sola_vez(): void
    {
        $gasa = $this->productWithStock(10);
        $payload = ['reference' => 'atencion-7', 'items' => [['product_id' => $gasa->id, 'quantity' => 3]]];

        $this->api('POST', 'consumptions', $payload)->assertOk()->assertJsonPath("data.stock.{$gasa->id}", 7);
        $this->api('POST', 'consumptions', $payload)->assertOk()->assertJsonPath("data.stock.{$gasa->id}", 7);

        $this->assertEquals(7.0, $this->stockOf($gasa));
    }

    public function test_sin_stock_suficiente_el_consumo_no_toca_nada(): void
    {
        $gasa = $this->productWithStock(10);
        $aguja = $this->productWithStock(1, ['name' => 'Aguja']);

        $this->api('POST', 'consumptions', [
            'reference' => 'atencion-8',
            'items' => [['product_id' => $gasa->id, 'quantity' => 2], ['product_id' => $aguja->id, 'quantity' => 5]],
        ])->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_contains($m, 'Aguja'));

        $this->assertEquals(10.0, $this->stockOf($gasa), 'Si falta un insumo no se descuenta ninguno.');
        $this->api('POST', 'consumptions', [
            'reference' => 'atencion-8', 'items' => [['product_id' => $gasa->id, 'quantity' => 2]],
        ])->assertOk()->assertJsonPath("data.stock.{$gasa->id}", 8);
    }

    // ---------------------------------------------------- Aviso hacia la web

    public function test_cada_cambio_de_stock_se_avisa_a_la_web_en_un_solo_envio(): void
    {
        config(['integration.web_url' => 'https://web.test']);
        Http::fake(['web.test/*' => Http::response(['ok' => true])]);

        $crema = $this->productWithStock(4);
        app(StockService::class)->exit($crema->id, $this->warehouse->id, 1);
        app(CatalogNotifier::class)->flush();

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://web.test/erp/catalogo'
            && $request->header('X-Integration-Token')[0] === 'secreto-de-prueba'
            && $request['items'][0]['id'] === $crema->id
            && $request['items'][0]['stock'] == 3);
    }

    public function test_sin_web_configurada_no_se_avisa_nada(): void
    {
        Http::fake();

        $this->productWithStock(4);
        app(CatalogNotifier::class)->flush();

        Http::assertNothingSent();
    }

    public function test_si_la_web_no_contesta_el_cambio_igual_se_guarda(): void
    {
        config(['integration.web_url' => 'https://web.test']);
        Http::fake(['web.test/*' => Http::response('caída', 500)]);

        $crema = $this->productWithStock(4);
        app(CatalogNotifier::class)->flush();

        $this->assertEquals(4.0, $this->stockOf($crema));
    }

    // ---------------------------------------------------------- Servicios POS

    public function test_un_servicio_vendido_en_el_pos_no_mueve_stock(): void
    {
        $this->actingAsRole('Caja');
        $facial = Product::factory()->service()->create(['price' => 120]);

        $sale = app(SaleService::class)->checkout([
            'doc_type' => 'ticket',
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $facial->id, 'quantity' => 1]],
            'payments' => [['method' => 'efectivo', 'amount' => 120]],
        ]);

        $this->assertEquals(120.0, (float) $sale->total);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_un_servicio_nunca_queda_con_control_de_stock(): void
    {
        $facial = Product::factory()->service()->create();
        $facial->update(['track_stock' => true]);

        $this->assertFalse($facial->fresh()->track_stock);
    }
}
