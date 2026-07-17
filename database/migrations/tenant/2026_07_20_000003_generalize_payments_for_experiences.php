<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generaliza el motor de cobros (spec-reservas-avanzado §3.3, anticipado
 * en spec-motor-reservas-web §12.2): una solicitud de cobro y su pago
 * pueden apuntar a una reserva de habitación O a una reserva de
 * experiencia. Exactamente uno de los dos — el resto del motor (webhooks,
 * pasarelas, cola de transferencias, expiración) no distingue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->foreignId('reservation_id')->nullable()->change();
            $table->foreignId('experience_booking_id')
                ->nullable()
                ->after('reservation_id')
                ->constrained('experience_bookings')
                ->cascadeOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('reservation_id')->nullable()->change();
            $table->foreignId('experience_booking_id')
                ->nullable()
                ->after('reservation_id')
                ->constrained('experience_bookings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('experience_booking_id');
        });
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('experience_booking_id');
        });
    }
};
