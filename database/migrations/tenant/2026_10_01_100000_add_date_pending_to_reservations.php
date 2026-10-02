<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fecha pendiente (cabañas, 2026-10-01): el huésped que reservó de último
 * momento cancela, no hay reembolso y no quiere reagendar todavía. La
 * cabaña se libera, pero lo pagado sigue siendo suyo para otra fecha. Sin
 * marca, esa reserva era una cancelada más y nadie sabía que había dinero
 * a favor del huésped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('date_pending_at')->nullable()->after('cancellation_reason');
            $table->string('date_pending_note', 255)->nullable()->after('date_pending_at');
            $table->index('date_pending_at');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex(['date_pending_at']);
            $table->dropColumn(['date_pending_at', 'date_pending_note']);
        });
    }
};
