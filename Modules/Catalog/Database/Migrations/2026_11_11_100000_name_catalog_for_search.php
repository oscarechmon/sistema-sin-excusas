<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nombres y textos del catálogo como los busca la gente en Google (pedido del
 * 2026-11-11). Solo toca lo que todavía se llama exactamente como antes: si
 * algo ya se cambió a mano en el sistema, se respeta. Las descripciones y la
 * marca solo se ponen donde están vacías.
 */
return new class extends Migration
{
    private const CLOSING = 'Cómpralo en línea con delivery en Lima, envío a provincias por Shalom o recojo en nuestro centro de Carabayllo. '
        .'Revisa la dosis en la etiqueta y consulta a tu médico si estás embarazada, das de lactar o tomas medicamentos.';

    /** Nombre actual => [nombre nuevo, marca, primera línea de la descripción]. */
    private const PRODUCTS = [
        'OMEGA 3 - 2500 MG - 120 SFG' => ['Omega 3 Nutricost 2500 mg, 120 cápsulas blandas', 'Nutricost',
            'Omega 3 de aceite de pescado Nutricost de 2500 mg, en frasco de 120 cápsulas blandas.'],
        'INOSITOL 500 MG - 240 CAPS' => ['Inositol Nutricost 500 mg, 240 cápsulas', 'Nutricost',
            'Inositol Nutricost de 500 mg, en frasco de 240 cápsulas.'],
        'ALPHA LIPOIC ACID 600 MG - 240 CAPS' => ['Ácido alfa lipoico Nutricost 600 mg, 240 cápsulas', 'Nutricost',
            'Ácido alfa lipoico (ALA) Nutricost de 600 mg, en frasco de 240 cápsulas.'],
        'DIGESTIVE ENZYMES 620 MG - 120 CAPS' => ['Enzimas digestivas Nutricost 620 mg, 120 cápsulas', 'Nutricost',
            'Enzimas digestivas Nutricost de 620 mg, en frasco de 120 cápsulas.'],
        'POTASSIUM CITRATE 99 MG + MAGNESIUM CITRATE 210 MG - 240' => ['Citrato de potasio 99 mg y magnesio 210 mg Nutricost, 240 cápsulas', 'Nutricost',
            'Citrato de potasio (99 mg) y citrato de magnesio (210 mg) Nutricost, en frasco de 240 cápsulas.'],
        // Marca sin confirmar: va sin marca hasta que se vea en la etiqueta.
        'BPL1 SLIMFLORA 210mL' => ['BPL1 Slimflora 210 ml', null,
            'BPL1 Slimflora en presentación de 210 ml.'],
    ];

    /** Servicios: con la palabra que escribe la gente ("bótox", "facial", "corporal"). */
    private const SERVICES = [
        'Toxina botulínica - Tercio superior (3 zonas)' => 'Bótox (toxina botulínica) - Tercio superior (3 zonas)',
        'Toxina botulínica - Tercio inferior (3 zonas)' => 'Bótox (toxina botulínica) - Tercio inferior (3 zonas)',
        'Toxina botulínica - Full face (6 zonas)' => 'Bótox (toxina botulínica) - Full face (6 zonas)',
        'Hidratación intensiva' => 'Hidratación facial intensiva',
        'Radiofrecuencia' => 'Radiofrecuencia corporal',
        'Lipolaser' => 'Lipoláser',
    ];

    private const CATEGORY = 'Suplementos';

    private const CATEGORY_OLD = 'Suplementos que acompañan los tratamientos de piel y bienestar.';

    private const CATEGORY_NEW = 'Suplementos Nutricost con delivery en Lima, envío a provincias y recojo en nuestro centro de Carabayllo. '
        .'Te orientamos para elegir el que complementa tu tratamiento.';

    public function up(): void
    {
        foreach (self::PRODUCTS as $old => [$new, $brand, $intro]) {
            $product = DB::table('products')->where('name', $old)->whereNull('deleted_at')->first(['id', 'brand_id', 'description']);

            if (! $product) {
                continue;
            }

            $changes = ['name' => $new, 'updated_at' => now()];

            if (blank($product->description)) {
                $changes['description'] = $intro."\n\n".self::CLOSING;
            }

            if ($brand !== null && $product->brand_id === null) {
                $changes['brand_id'] = $this->brandId($brand);
            }

            DB::table('products')->where('id', $product->id)->update($changes);
        }

        foreach (self::SERVICES as $old => $new) {
            DB::table('products')->where('type', 'service')->where('name', $old)->whereNull('deleted_at')
                ->update(['name' => $new, 'updated_at' => now()]);
        }

        DB::table('categories')->where('name', self::CATEGORY)
            ->where(fn ($q) => $q->whereNull('description')->orWhere('description', '')->orWhere('description', self::CATEGORY_OLD))
            ->update(['description' => self::CATEGORY_NEW, 'updated_at' => now()]);
    }

    public function down(): void
    {
        foreach (self::PRODUCTS as $old => [$new, , $intro]) {
            DB::table('products')->where('name', $new)->where('description', $intro."\n\n".self::CLOSING)
                ->update(['description' => null]);
            DB::table('products')->where('name', $new)->update(['name' => $old]);
        }

        foreach (self::SERVICES as $old => $new) {
            DB::table('products')->where('type', 'service')->where('name', $new)->update(['name' => $old]);
        }

        DB::table('categories')->where('name', self::CATEGORY)->where('description', self::CATEGORY_NEW)
            ->update(['description' => self::CATEGORY_OLD]);
    }

    /** La marca con ese nombre; si no existe, se crea. */
    private function brandId(string $name): int
    {
        $id = DB::table('brands')->where('name', $name)->whereNull('deleted_at')->value('id');

        return (int) ($id ?? DB::table('brands')->insertGetId([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }
};
