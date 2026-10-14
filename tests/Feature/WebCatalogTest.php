<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductImage;
use Modules\Integration\Services\CatalogNotifier;
use Modules\Packages\Models\Package;
use Modules\Packages\Services\PackageService;
use Tests\TestCase;

/**
 * La web pública es un cascarón: lo que muestra de cada producto, servicio y
 * paquete (si se publica, imagen, descripción, duración) se administra aquí y
 * le llega por la API de integración.
 */
class WebCatalogTest extends TestCase
{
    use RefreshDatabase;

    private array $env;

    protected function setUp(): void
    {
        parent::setUp();

        $this->env = $this->seedErp();
        config(['integration.token' => 'secreto-de-prueba', 'integration.web_url' => null]);
        Storage::fake('public');
    }

    private function api(string $method, string $uri, array $data = []): TestResponse
    {
        return $this->withHeader('X-Integration-Token', 'secreto-de-prueba')
            ->json($method, "/api/v1/integration/{$uri}", $data);
    }

    /** @return array<string, mixed> */
    private function feedItem(Product $product): array
    {
        return $this->api('GET', 'catalog?ids[]='.$product->id)->assertOk()->json('data.0');
    }

    public function test_el_catalogo_trae_lo_que_muestra_la_web(): void
    {
        $category = Category::create(['name' => 'Faciales', 'description' => 'Tratamientos para el rostro', 'is_active' => true]);
        $facial = Product::factory()->service()->create([
            'name' => 'Limpieza facial', 'category_id' => $category->id, 'description' => 'Limpieza profunda',
            'web_published' => true, 'duration_minutes' => 45, 'image_path' => 'products/1/foto.webp',
        ]);

        $item = $this->feedItem($facial);

        $this->assertTrue($item['web_published']);
        $this->assertSame(45, $item['duration_minutes']);
        $this->assertSame('Limpieza profunda', $item['description']);
        $this->assertSame('Tratamientos para el rostro', $item['category_description']);
        $this->assertStringStartsWith('http', $item['image_url'], 'La web la muestra tal cual: dirección completa.');
        $this->assertStringEndsWith('products/1/foto.webp', $item['image_url']);
    }

    public function test_lo_que_nunca_se_decidio_aqui_llega_vacio(): void
    {
        $crema = Product::factory()->create();

        $item = $this->feedItem($crema);

        $this->assertNull($item['web_published'], 'Vacío: la web sigue con lo que tenía.');
        $this->assertNull($item['image_url']);
    }

    public function test_publicar_y_la_duracion_se_guardan_desde_el_formulario(): void
    {
        $this->actingAsRole('Administrador', $this->env['company'], $this->env['branch']);
        $facial = Product::factory()->service()->create();

        $this->putJson("/api/v1/products/{$facial->id}", ['web_published' => true, 'duration_minutes' => 60])
            ->assertOk()
            ->assertJsonPath('data.web_published', true)
            ->assertJsonPath('data.duration_minutes', 60);
    }

    public function test_guardar_sin_decidir_la_publicacion_no_la_cambia(): void
    {
        $this->actingAsRole('Administrador', $this->env['company'], $this->env['branch']);
        $crema = Product::factory()->create(['price' => 40]);

        $this->putJson("/api/v1/products/{$crema->id}", ['price' => 45, 'web_published' => null])->assertOk();

        $this->assertNull($crema->fresh()->web_published);
        $this->assertEquals(45, $crema->fresh()->price);
    }

    public function test_la_web_entrega_su_ficha_con_la_imagen(): void
    {
        $crema = Product::factory()->create();

        $this->withHeader('X-Integration-Token', 'secreto-de-prueba')
            ->post("/api/v1/integration/products/{$crema->id}/web", [
                'description' => 'Crema hidratante de la web',
                'web_published' => '1',
                'image' => UploadedFile::fake()->image('crema.jpg', 1200, 900),
            ])
            ->assertOk()
            ->assertJsonPath('data.web_published', true);

        $crema->refresh();
        $this->assertTrue($crema->web_published);
        $this->assertSame('Crema hidratante de la web', $crema->description);
        $this->assertNotNull($crema->image_path);
        Storage::disk('public')->assertExists($crema->image_path);
    }

    public function test_la_ficha_de_un_paquete_queda_en_el_paquete(): void
    {
        $facial = Product::factory()->service()->create();
        $package = app(PackageService::class)->create([
            'name' => '6 faciales', 'price' => 500, 'total_sessions' => 6, 'is_active' => true, 'service_ids' => [$facial->id],
        ]);

        $this->withHeader('X-Integration-Token', 'secreto-de-prueba')
            ->post("/api/v1/integration/products/{$package->product_id}/web", ['description' => 'Para la web', 'web_published' => '1'])
            ->assertOk();

        $package = Package::findOrFail($package->id);
        $this->assertTrue($package->web_published, 'Se guarda en el paquete: su producto se copia de él.');
        $this->assertSame('Para la web', $package->description);
        $this->assertTrue(Product::findOrFail($package->product_id)->web_published);
    }

    public function test_la_descripcion_de_una_categoria_solo_llega_si_aqui_no_hay_una(): void
    {
        Category::create(['name' => 'Suplementos', 'is_active' => true]);
        Category::create(['name' => 'Faciales', 'description' => 'La de aquí', 'is_active' => true]);

        $this->api('POST', 'categories/describe', ['name' => 'Suplementos', 'description' => 'De la web'])->assertJsonPath('data.updated', true);
        $this->api('POST', 'categories/describe', ['name' => 'Faciales', 'description' => 'De la web'])->assertJsonPath('data.updated', false);

        $this->assertSame('De la web', Category::where('name', 'Suplementos')->value('description'));
        $this->assertSame('La de aquí', Category::where('name', 'Faciales')->value('description'));
    }

    public function test_publicar_desde_la_lista_solo_cambia_eso_y_se_avisa_a_la_web(): void
    {
        $this->actingAsRole('Administrador', $this->env['company'], $this->env['branch']);
        $facial = Product::factory()->service()->create(['is_active' => false, 'price' => 120, 'web_published' => null]);
        config(['integration.web_url' => 'https://web.test']);
        Http::fake(['web.test/*' => Http::response(['ok' => true])]);

        $this->postJson("/api/v1/products/{$facial->id}/web", ['web_published' => true])
            ->assertOk()
            ->assertJsonPath('data.web_published', true)
            ->assertJsonPath('message', "«{$facial->name}» ya se muestra en la web.");
        app(CatalogNotifier::class)->flush();

        $fresh = $facial->fresh();
        $this->assertTrue($fresh->web_published);
        $this->assertFalse($fresh->is_active, 'Publicar no reactiva lo que estaba inactivo.');
        $this->assertEquals(120, $fresh->price);
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://web.test/erp/catalogo'
            && $request['items'][0]['id'] === $facial->id
            && $request['items'][0]['web_published'] === true);
    }

    public function test_un_paquete_se_publica_desde_paquetes_y_ventas_no_publica(): void
    {
        $this->actingAsRole('Administrador', $this->env['company'], $this->env['branch']);
        $paquete = Product::factory()->create(['type' => Product::TYPE_PACKAGE]);
        $this->postJson("/api/v1/products/{$paquete->id}/web", ['web_published' => true])->assertStatus(422);

        $this->actingAsRole('Ventas', $this->env['company'], $this->env['branch']);
        $crema = Product::factory()->create();
        $this->postJson("/api/v1/products/{$crema->id}/web", ['web_published' => true])->assertForbidden();
    }

    public function test_la_promocion_se_activa_desde_la_lista_y_llega_a_la_web_con_su_precio(): void
    {
        $this->actingAsRole('Administrador', $this->env['company'], $this->env['branch']);
        $facial = Product::factory()->service()->create(['price' => 150, 'offer_price' => 120, 'web_published' => true]);
        config(['integration.web_url' => 'https://web.test']);
        Http::fake(['web.test/*' => Http::response(['ok' => true])]);

        $this->postJson("/api/v1/products/{$facial->id}/promotion", ['on_promotion' => true])
            ->assertOk()
            ->assertJsonPath('data.on_promotion', true)
            ->assertJsonPath('data.promotion_price', 120)
            ->assertJsonPath('message', "«{$facial->name}» ahora está en promoción.");
        app(CatalogNotifier::class)->flush();

        $this->assertEquals(150, $facial->fresh()->price, 'El precio normal no cambia.');
        Http::assertSent(fn (HttpRequest $request): bool => $request['items'][0]['id'] === $facial->id
            && $request['items'][0]['on_promotion'] === true
            && $request['items'][0]['promotion_price'] == 120
            && $request['items'][0]['price'] == 150);

        $this->getJson('/api/v1/products?type=service&web=promotion')->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.promotion_total', 1);

        // Al quitarla, el precio de oferta queda guardado pero ya no se cobra.
        $this->postJson("/api/v1/products/{$facial->id}/promotion", ['on_promotion' => false])
            ->assertOk()
            ->assertJsonPath('data.promotion_price', null);
        $this->assertEquals(120, $facial->fresh()->offer_price);
    }

    public function test_en_promocion_el_precio_de_promocion_debe_rebajar_el_precio(): void
    {
        $this->actingAsRole('Administrador', $this->env['company'], $this->env['branch']);
        $crema = Product::factory()->create(['price' => 50]);

        $this->putJson("/api/v1/products/{$crema->id}", ['price' => 50, 'offer_price' => 60, 'on_promotion' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('offer_price');

        // Sin precio de promoción también vale: se vende al precio normal.
        $this->putJson("/api/v1/products/{$crema->id}", ['price' => 50, 'offer_price' => null, 'on_promotion' => true])
            ->assertOk()
            ->assertJsonPath('data.on_promotion', true)
            ->assertJsonPath('data.promotion_price', null);

        // Sin el precio en la petición, se compara con el que ya tiene.
        $this->putJson("/api/v1/products/{$crema->id}", ['offer_price' => 45, 'on_promotion' => true])
            ->assertOk()
            ->assertJsonPath('data.promotion_price', 45);
    }

    public function test_un_paquete_no_se_pone_en_promocion_desde_productos(): void
    {
        $this->actingAsRole('Administrador', $this->env['company'], $this->env['branch']);
        $paquete = Product::factory()->create(['type' => Product::TYPE_PACKAGE]);

        $this->postJson("/api/v1/products/{$paquete->id}/promotion", ['on_promotion' => true])->assertStatus(422);
    }

    public function test_el_orden_en_la_web_se_guarda_y_llega_a_la_web(): void
    {
        $this->actingAsRole('Administrador', $this->env['company'], $this->env['branch']);
        $faciales = Category::create(['name' => 'Faciales', 'is_active' => true]);
        $corporales = Category::create(['name' => 'Corporales', 'is_active' => true]);
        $limpieza = Product::factory()->service()->create(['name' => 'Limpieza', 'category_id' => $faciales->id, 'web_published' => true, 'is_active' => true]);
        $dermapen = Product::factory()->service()->create(['name' => 'Dermapen', 'category_id' => $faciales->id, 'web_published' => true, 'is_active' => true]);
        $masaje = Product::factory()->service()->create(['name' => 'Masaje', 'category_id' => $corporales->id, 'web_published' => true, 'is_active' => true]);
        Product::factory()->service()->create(['name' => 'Oculto', 'category_id' => $faciales->id, 'web_published' => false]);
        $crema = Product::factory()->create(['category_id' => $faciales->id, 'web_published' => true, 'is_active' => true]);

        // Sin ordenar: categorías como se crearon e ítems por nombre; solo lo que se ve en la web.
        $order = $this->getJson('/api/v1/products/web-order?type=service')->assertOk()->json('data');
        $this->assertSame(['Faciales', 'Corporales'], array_column($order['categories'], 'name'));
        $this->assertSame(['Dermapen', 'Limpieza'], array_column($order['categories'][0]['items'], 'name'));

        config(['integration.web_url' => 'https://web.test']);
        Http::fake(['web.test/*' => Http::response(['ok' => true])]);

        $this->postJson('/api/v1/products/web-order', [
            'type' => 'service',
            'categories' => [$corporales->id, $faciales->id],
            // Un producto en la lista de servicios se ignora.
            'items' => [$masaje->id, $limpieza->id, $dermapen->id, $crema->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.categories.0.name', 'Corporales')
            ->assertJsonPath('data.categories.1.items.0.name', 'Limpieza');
        app(CatalogNotifier::class)->flush();

        $this->assertSame(2, $limpieza->fresh()->sort_order);
        $this->assertNull($crema->fresh()->sort_order);
        Http::assertSent(function (HttpRequest $request) use ($limpieza): bool {
            $item = collect($request['items'])->firstWhere('id', $limpieza->id);

            return $item !== null && $item['sort_order'] === 2 && $item['category_order'] === 2;
        });

        $this->actingAsRole('Ventas', $this->env['company'], $this->env['branch']);
        $this->postJson('/api/v1/products/web-order', ['type' => 'service', 'categories' => [], 'items' => []])->assertForbidden();
    }

    public function test_la_lista_separa_productos_y_servicios_y_filtra_lo_publicado(): void
    {
        $this->actingAsRole('Administrador', $this->env['company'], $this->env['branch']);
        $faciales = Category::create(['name' => 'Faciales', 'is_active' => true]);
        $suplementos = Category::create(['name' => 'Suplementos', 'is_active' => true]);
        Category::create(['name' => 'Nueva', 'is_active' => true]);
        Product::factory()->service()->create(['category_id' => $faciales->id, 'web_published' => true]);
        Product::factory()->service()->create(['category_id' => $faciales->id, 'web_published' => null]);
        Product::factory()->create(['category_id' => $suplementos->id, 'web_published' => true]);

        $this->getJson('/api/v1/products?type=service')->assertOk()
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonPath('data.web_published_total', 1);
        $this->getJson('/api/v1/products?type=service&web=published')->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/v1/products?type=service&web=hidden')->assertOk()->assertJsonPath('data.meta.total', 1);

        // Un servicio no se ofrece en "Suplementos"; una categoría sin nada sirve para ambos.
        $names = collect($this->getJson('/api/v1/categories?all=1&for=service')->assertOk()->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Faciales', 'Nueva'], $names);
    }

    public function test_un_cambio_de_categoria_se_avisa_a_la_web(): void
    {
        $category = Category::create(['name' => 'Faciales', 'is_active' => true]);
        $facial = Product::factory()->service()->create(['category_id' => $category->id]);
        config(['integration.web_url' => 'https://web.test']);
        Http::fake(['web.test/*' => Http::response(['ok' => true])]);

        $category->update(['description' => 'Nueva descripción']);
        app(CatalogNotifier::class)->flush();

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://web.test/erp/catalogo'
            && $request['items'][0]['id'] === $facial->id
            && $request['items'][0]['category_description'] === 'Nueva descripción');
    }

    public function test_las_fotos_adicionales_se_suben_se_quitan_y_llegan_a_la_web(): void
    {
        $this->actingAsRole('Administrador', $this->env['company'], $this->env['branch']);
        $facial = Product::factory()->service()->create(['image_path' => 'products/1/principal.webp']);
        config(['integration.web_url' => 'https://web.test']);
        Http::fake(['web.test/*' => Http::response(['ok' => true])]);

        $this->withHeader('Accept', 'application/json')->post("/api/v1/products/{$facial->id}", [
            '_method' => 'PUT',
            'gallery' => [UploadedFile::fake()->image('antes.jpg', 900, 900), UploadedFile::fake()->image('despues.jpg', 900, 900)],
        ])->assertOk();
        app(CatalogNotifier::class)->flush();

        $gallery = $this->getJson("/api/v1/products/{$facial->id}")->assertOk()->json('data.gallery');
        $this->assertCount(2, $gallery);
        [$antes, $despues] = $facial->images()->get()->all();
        Storage::disk('public')->assertExists([$antes->path, $despues->path]);
        $this->assertSame('products/1/principal.webp', $facial->fresh()->image_path, 'La principal no cambia.');

        $item = $this->feedItem($facial);
        $this->assertStringEndsWith('principal.webp', $item['image_url']);
        $this->assertCount(2, $item['gallery']);
        $this->assertStringStartsWith('http', $item['gallery'][0], 'La web la muestra tal cual: dirección completa.');
        $this->assertStringEndsWith($antes->path, $item['gallery'][0]);
        Http::assertSent(fn (HttpRequest $request): bool => count($request['items'][0]['gallery']) === 2);

        // Se quita una y se agrega otra: la nueva va al final.
        $this->withHeader('Accept', 'application/json')->post("/api/v1/products/{$facial->id}", [
            '_method' => 'PUT',
            'remove_images' => [$antes->id],
            'gallery' => [UploadedFile::fake()->image('otra.jpg', 900, 900)],
        ])->assertOk();

        $paths = $facial->images()->pluck('path')->all();
        $this->assertCount(2, $paths);
        $this->assertSame($despues->path, $paths[0]);
        Storage::disk('public')->assertMissing($antes->path);
    }

    public function test_no_se_pasa_del_maximo_de_fotos_adicionales(): void
    {
        $this->actingAsRole('Administrador', $this->env['company'], $this->env['branch']);
        $crema = Product::factory()->create();
        $otro = Product::factory()->create();
        foreach (range(1, ProductImage::MAX) as $i) {
            ProductImage::create(['product_id' => $crema->id, 'path' => "products/{$crema->id}/{$i}.webp", 'sort_order' => $i]);
        }
        $ajena = ProductImage::create(['product_id' => $otro->id, 'path' => "products/{$otro->id}/x.webp"]);

        // Quitar una foto de otro producto no hace lugar.
        $this->withHeader('Accept', 'application/json')->post("/api/v1/products/{$crema->id}", [
            '_method' => 'PUT',
            'price' => 99,
            'remove_images' => [$ajena->id],
            'gallery' => [UploadedFile::fake()->image('una-mas.jpg')],
        ])->assertStatus(422)->assertJsonValidationErrors('gallery');

        $this->assertSame(ProductImage::MAX, $crema->images()->count());
        $this->assertNotNull($ajena->fresh());
        $this->assertNotEquals(99, $crema->fresh()->price, 'Si las fotos no pasan, no se guarda nada.');

        // Quitando una, sí entra.
        $this->withHeader('Accept', 'application/json')->post("/api/v1/products/{$crema->id}", [
            '_method' => 'PUT',
            'remove_images' => [$crema->images()->value('id')],
            'gallery' => [UploadedFile::fake()->image('una-mas.jpg')],
        ])->assertOk();
        $this->assertSame(ProductImage::MAX, $crema->images()->count());
    }

    public function test_un_producto_nuevo_nace_con_sus_fotos_adicionales(): void
    {
        $this->actingAsRole('Administrador', $this->env['company'], $this->env['branch']);

        $id = $this->withHeader('Accept', 'application/json')->post('/api/v1/products', [
            'type' => 'product', 'name' => 'Colágeno', 'cost' => 50, 'price' => 90,
            'image' => UploadedFile::fake()->image('principal.jpg'),
            'gallery' => [UploadedFile::fake()->image('tabla.jpg'), UploadedFile::fake()->image('uso.jpg')],
        ])->assertCreated()->json('data.id');

        $product = Product::findOrFail($id);
        $this->assertNotNull($product->image_path);
        $this->assertSame(2, $product->images()->count());
    }
}
