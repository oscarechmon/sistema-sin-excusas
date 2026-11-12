<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Google Merchant Center tomó BPL1 Slimflora (frasco ámbar de 210 ml) por una
 * bebida alcohólica. La descripción dice ahora lo que trae la etiqueta: es un
 * probiótico de uso oral. Solo cambia si sigue el texto que se puso el
 * 2026-11-11; si alguien ya la editó en el sistema, se respeta.
 */
return new class extends Migration
{
    private const NAME = 'BPL1 Slimflora 210 ml';

    private const CLOSING = 'Cómpralo en línea con delivery en Lima, envío a provincias por Shalom o recojo en nuestro centro de Carabayllo. '
        .'Revisa la dosis en la etiqueta y consulta a tu médico si estás embarazada, das de lactar o tomas medicamentos.';

    private const BEFORE = 'BPL1 Slimflora en presentación de 210 ml.';

    private const AFTER = 'Probiótico BPL1 Slimflora con Bifidobacterium animalis subsp. lactis, de uso oral, en frasco de 210 ml.';

    public function up(): void
    {
        DB::table('products')->where('name', self::NAME)->where('description', self::BEFORE."\n\n".self::CLOSING)
            ->update(['description' => self::AFTER."\n\n".self::CLOSING, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('products')->where('name', self::NAME)->where('description', self::AFTER."\n\n".self::CLOSING)
            ->update(['description' => self::BEFORE."\n\n".self::CLOSING]);
    }
};
