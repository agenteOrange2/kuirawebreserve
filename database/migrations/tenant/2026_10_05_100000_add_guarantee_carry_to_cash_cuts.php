<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arrastre de fianzas entre cortes (2026-10-05). Una fianza en efectivo es
 * dinero del huésped: se queda en el cajón hasta devolverse. El corte guarda
 * cuántas venían del periodo anterior y cuántas se quedan al cerrar, para que
 * el historial y el PDF digan qué parte de lo contado NO se entrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_cuts', function (Blueprint $table) {
            $table->decimal('guarantees_carried', 12, 2)->default(0)->after('opening_cash');
            $table->decimal('guarantees_held', 12, 2)->default(0)->after('guarantees_carried');
        });
    }

    public function down(): void
    {
        Schema::table('cash_cuts', function (Blueprint $table) {
            $table->dropColumn(['guarantees_carried', 'guarantees_held']);
        });
    }
};
