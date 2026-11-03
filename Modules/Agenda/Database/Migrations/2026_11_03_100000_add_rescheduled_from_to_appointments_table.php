<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cita reprogramada: el cliente avisó y pasó a otra fecha. La cita nueva apunta
 * a la original (que queda como «Reprogramó»), así la agenda muestra de dónde
 * viene cada una y a dónde se movió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreignId('rescheduled_from_id')->nullable()->after('status')->constrained('appointments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rescheduled_from_id');
        });
    }
};
