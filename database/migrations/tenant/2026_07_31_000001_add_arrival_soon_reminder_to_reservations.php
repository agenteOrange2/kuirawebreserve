<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de idempotencia del aviso el día de la llegada (segundo
 * recordatorio, N horas antes de la entrada): cada reserva recibe UN
 * solo aviso aunque el scheduler corra cada 15 minutos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('reservations', 'arrival_soon_reminder_sent_at')) {
            return;
        }

        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('arrival_soon_reminder_sent_at')->nullable()->after('arrival_reminder_sent_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('reservations', 'arrival_soon_reminder_sent_at')) {
            return;
        }

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('arrival_soon_reminder_sent_at');
        });
    }
};
