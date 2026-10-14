<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orden en que la web muestra los productos y servicios dentro de su
 * categoría («Orden en la web»). Vacío: nunca se ordenó, va al final por
 * nombre (así lo nuevo que se publica aparece último).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->nullable()->after('on_promotion');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('sort_order');
        });
    }
};
