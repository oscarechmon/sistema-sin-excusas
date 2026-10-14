<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un producto o servicio puede estar en promoción: la web lo muestra en
 * «Promociones» y, si tiene precio de promoción (`offer_price`, que ya
 * existía), la web y el POS cobran ese precio mientras dure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('on_promotion')->default(false)->after('web_published');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('on_promotion');
        });
    }
};
