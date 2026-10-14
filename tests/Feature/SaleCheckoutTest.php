<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Models\Product;
use Modules\Inventory\Services\StockService;
use Modules\Sales\Services\SaleService;
use Tests\TestCase;

class SaleCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_computes_igv_generates_correlativo_and_decrements_stock(): void
    {
        $env = $this->seedErp();
        $this->actingAsRole('Caja', $env['company'], $env['branch']);
        $warehouseId = $env['warehouse']->id;

        $product = Product::factory()->create(['price' => 118, 'cost' => 50]);
        app(StockService::class)->entry($product->id, $warehouseId, 10, 50);

        $sale = app(SaleService::class)->checkout([
            'doc_type' => 'boleta',
            'warehouse_id' => $warehouseId,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [['method' => 'efectivo', 'amount' => 300]],
        ]);

        // Total 2*118 = 236; base = 236/1.18 = 200; IGV = 36.
        $this->assertEquals(236.0, (float) $sale->total);
        $this->assertEquals(200.0, (float) $sale->subtotal);
        $this->assertEquals(36.0, (float) $sale->tax);
        $this->assertEquals(64.0, (float) $sale->change); // 300 - 236
        $this->assertEquals('B001-00000001', $sale->full_number);

        // Stock 10 - 2 = 8.
        $this->assertEquals(8.0, app(StockService::class)->available($product->id, $warehouseId));
    }

    public function test_checkout_rejects_insufficient_payment(): void
    {
        $env = $this->seedErp();
        $this->actingAsRole('Caja', $env['company'], $env['branch']);
        $product = Product::factory()->create(['price' => 100]);

        $this->postJson('/api/v1/sales', [
            'doc_type' => 'ticket',
            'warehouse_id' => $env['warehouse']->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'efectivo', 'amount' => 50]],
        ])->assertStatus(422);
    }

    public function test_quotation_does_not_affect_stock(): void
    {
        $env = $this->seedErp();
        $this->actingAsRole('Caja', $env['company'], $env['branch']);
        $warehouseId = $env['warehouse']->id;
        $product = Product::factory()->create(['price' => 100]);
        app(StockService::class)->entry($product->id, $warehouseId, 5, 50);

        app(SaleService::class)->checkout([
            'doc_type' => 'cotizacion',
            'warehouse_id' => $warehouseId,
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'payments' => [],
        ]);

        // La cotización no descuenta stock.
        $this->assertEquals(5.0, app(StockService::class)->available($product->id, $warehouseId));
    }

    public function test_en_promocion_se_cobra_el_precio_de_promocion(): void
    {
        $env = $this->seedErp();
        $this->actingAsRole('Caja', $env['company'], $env['branch']);
        $warehouseId = $env['warehouse']->id;
        $promo = Product::factory()->create(['price' => 100, 'offer_price' => 80, 'on_promotion' => true]);
        // Con precio de oferta guardado pero sin promoción, se cobra el normal.
        $normal = Product::factory()->create(['price' => 50, 'offer_price' => 40, 'on_promotion' => false]);
        app(StockService::class)->entry($promo->id, $warehouseId, 5, 50);
        app(StockService::class)->entry($normal->id, $warehouseId, 5, 20);

        $sale = app(SaleService::class)->checkout([
            'doc_type' => 'ticket',
            'warehouse_id' => $warehouseId,
            'items' => [['product_id' => $promo->id, 'quantity' => 2], ['product_id' => $normal->id, 'quantity' => 1]],
            'payments' => [['method' => 'efectivo', 'amount' => 210]],
        ]);

        $this->assertEquals(210.0, (float) $sale->total); // 2 × 80 + 50
    }
}
