<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El corte de caja se separa por ámbito: 'rooms' (recepción — cobros de
 * reservas/estancias + fianzas) y 'pos' (punto de venta). Los cortes ya
 * guardados quedan como 'all' (combinado, el comportamiento histórico) —
 * el default hace el backfill solo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Reparación: la migración 2026_08_03_000002 se editó después de
        // correr en los tenants productivos — orders/payments sí recibieron
        // shift_id pero cash_cuts no. Aquí se completa antes de usarla.
        if (! Schema::hasColumn('cash_cuts', 'shift_id')) {
            Schema::table('cash_cuts', function (Blueprint $table) {
                $table->foreignId('shift_id')->nullable()->after('user_id')
                    ->constrained()->nullOnDelete();
            });
        }

        Schema::table('cash_cuts', function (Blueprint $table) {
            $table->string('scope', 10)->default('all')->after('shift_id');
            $table->index(['user_id', 'scope', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_cuts', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'scope', 'closed_at']);
            $table->dropColumn('scope');
        });
    }
};
