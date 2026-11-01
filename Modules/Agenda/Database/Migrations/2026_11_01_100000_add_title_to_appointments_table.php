<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Título corto de la cita (p. ej. «Control post limpieza»), para ubicarla de un
 * vistazo en la agenda y en los avisos. Opcional: las notas siguen aparte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('title', 120)->nullable()->after('end_time');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn('title');
        });
    }
};
