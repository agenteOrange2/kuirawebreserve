<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tiempos objetivo en las incidencias: hasta ahora un ticket podía quedarse
 * abierto para siempre sin que nadie se enterara (caso real motellacupula,
 * 2026-08-21: fuga de agua de prioridad ALTA abierta dos semanas, con la
 * habitación vendiéndose).
 *
 * `due_at` se calcula al crear según la prioridad; `overdue_notified_at`
 * existe para avisar UNA sola vez por ticket — el comando corre cada 15
 * minutos y sin este sello la campana sonaría en cada pasada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->timestamp('due_at')->nullable()->after('status');
            $table->timestamp('overdue_notified_at')->nullable()->after('due_at');

            // El comando busca pendientes vencidas: sin índice barre la
            // tabla completa cada cuarto de hora, por tenant.
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex(['status', 'due_at']);
            $table->dropColumn(['due_at', 'overdue_notified_at']);
        });
    }
};
