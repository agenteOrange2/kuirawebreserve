<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobro consolidado de grupos: UNA solicitud de cobro puede apuntar a un
 * grupo completo (un solo link para todas las habitaciones). Al pagarse,
 * el dinero se reparte en pagos por reserva según el desglose congelado
 * en meta — la contabilidad por habitación no cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->foreignId('reservation_group_id')
                ->nullable()
                ->after('experience_booking_id')
                ->constrained('reservation_groups')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reservation_group_id');
        });
    }
};
