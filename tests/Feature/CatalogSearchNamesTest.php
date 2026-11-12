<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Models\Brand;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;
use Tests\TestCase;

/** Migración que deja el catálogo con los nombres que busca la gente en Google. */
class CatalogSearchNamesTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require base_path('Modules/Catalog/Database/Migrations/2026_11_11_100000_name_catalog_for_search.php');
    }

    public function test_renombra_describe_y_pone_la_marca_solo_donde_corresponde(): void
    {
        $category = Category::factory()->create(['name' => 'Suplementos', 'description' => 'Suplementos que acompañan los tratamientos de piel y bienestar.']);
        $omega = Product::factory()->create(['name' => 'OMEGA 3 - 2500 MG - 120 SFG', 'category_id' => $category->id, 'brand_id' => null, 'description' => null]);
        $inositol = Product::factory()->create(['name' => 'INOSITOL 500 MG - 240 CAPS', 'brand_id' => null, 'description' => 'Texto propio del centro.']);
        $bpl1 = Product::factory()->create(['name' => 'BPL1 SLIMFLORA 210mL', 'brand_id' => null, 'description' => null]);
        $botox = Product::factory()->service()->create(['name' => 'Toxina botulínica - Tercio superior (3 zonas)']);
        $otro = Product::factory()->create(['name' => 'Crema que no se toca']);

        $this->migration()->up();

        $omega->refresh();
        $this->assertSame('Omega 3 Nutricost 2500 mg, 120 cápsulas blandas', $omega->name);
        $this->assertStringStartsWith('Omega 3 de aceite de pescado Nutricost de 2500 mg', $omega->description);
        $this->assertSame('Nutricost', $omega->brand?->name);

        // Lo que el centro ya escribió se respeta; el nombre sí se actualiza.
        $inositol->refresh();
        $this->assertSame('Inositol Nutricost 500 mg, 240 cápsulas', $inositol->name);
        $this->assertSame('Texto propio del centro.', $inositol->description);
        $this->assertSame($omega->brand_id, $inositol->brand_id, 'Una sola marca Nutricost para todos.');
        $this->assertSame(1, Brand::where('name', 'Nutricost')->count());

        $this->assertNull($bpl1->fresh()->brand_id, 'Sin marca confirmada no se inventa una.');
        $this->assertSame('Bótox (toxina botulínica) - Tercio superior (3 zonas)', $botox->fresh()->name);
        $this->assertSame('Crema que no se toca', $otro->fresh()->name);
        $this->assertStringStartsWith('Suplementos Nutricost con delivery', $category->fresh()->description);

        $this->migration()->down();

        $this->assertSame('OMEGA 3 - 2500 MG - 120 SFG', $omega->fresh()->name);
        $this->assertNull($omega->fresh()->description);
        $this->assertSame('Texto propio del centro.', $inositol->fresh()->description);
        $this->assertSame('Toxina botulínica - Tercio superior (3 zonas)', $botox->fresh()->name);
    }

    public function test_bpl1_se_describe_como_probiotico_y_respeta_lo_editado(): void
    {
        $bpl1 = Product::factory()->create(['name' => 'BPL1 SLIMFLORA 210mL', 'brand_id' => null, 'description' => null]);
        $this->migration()->up();
        $editado = Product::factory()->create(['name' => 'BPL1 Slimflora 210 ml', 'description' => 'Texto propio.']);

        $probiotic = require base_path('Modules/Catalog/Database/Migrations/2026_11_11_110000_describe_bpl1_as_probiotic.php');
        $probiotic->up();

        $this->assertStringStartsWith('Probiótico BPL1 Slimflora con Bifidobacterium animalis subsp. lactis, de uso oral', $bpl1->fresh()->description);
        $this->assertStringContainsString('Cómpralo en línea', $bpl1->fresh()->description);
        $this->assertSame('Texto propio.', $editado->fresh()->description);

        $probiotic->down();
        $this->assertStringStartsWith('BPL1 Slimflora en presentación de 210 ml.', $bpl1->fresh()->description);
    }

    public function test_si_ya_se_renombro_a_mano_no_hace_nada(): void
    {
        $propio = Product::factory()->create(['name' => 'Omega 3 de la casa', 'description' => null]);

        $this->migration()->up();

        $this->assertSame('Omega 3 de la casa', $propio->fresh()->name);
        $this->assertNull($propio->fresh()->description);
    }
}
