<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de idempotencia del recordatorio de llegada: el scheduler corre
 * cada hora y cada reserva debe recibir UN solo recordatorio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('arrival_reminder_sent_at')->nullable()->after('payment_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('arrival_reminder_sent_at');
        });
    }
};
