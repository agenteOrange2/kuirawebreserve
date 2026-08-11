<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_cuts', function (Blueprint $table) {
            // Fondo de caja inicial del turno (shifts.opening_cash congelado
            // al cortar): el arqueo compara contra el cajón COMPLETO, no
            // solo contra lo cobrado en el periodo.
            $table->decimal('opening_cash', 12, 2)->default(0)->after('expected_cash');

            // Foto de los pagos pendientes al momento del corte (huéspedes
            // en casa con saldo, reservas con pago vencido, consumos
            // cargados a habitación sin liquidar). Se congela aquí porque
            // el estado vivo cambia después del corte.
            $table->unsignedInteger('pending_count')->default(0)->after('difference');
            $table->decimal('pending_total', 12, 2)->default(0)->after('pending_count');
            $table->json('pending_items')->nullable()->after('pending_total');
        });
    }

    public function down(): void
    {
        Schema::table('cash_cuts', function (Blueprint $table) {
            $table->dropColumn(['opening_cash', 'pending_count', 'pending_total', 'pending_items']);
        });
    }
};
