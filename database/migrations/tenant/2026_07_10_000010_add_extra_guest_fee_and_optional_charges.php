<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobros extra por habitación: la tarifa incluye N personas y cada persona
 * adicional cuesta $X por unidad de tarifa (noche/periodo); además cargos
 * opcionales propios del cuarto (mascota, decoración…) que el personal
 * aplica al crear el walk-in o la reserva. El desglose aplicado se guarda
 * en stays/reservations (extra_charges) para que el folio sea transparente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            // Personas que la tarifa incluye; null = sin cobro por persona extra.
            $table->unsignedTinyInteger('included_occupancy')->nullable()->after('max_occupancy');
            // $ por persona extra, por unidad de tarifa (noche/periodo).
            $table->decimal('extra_guest_fee', 10, 2)->nullable()->after('price_modifier');
            // [{concept: "Mascota", amount: 200}, ...] — cargos de una sola vez.
            $table->json('optional_charges')->nullable()->after('extra_guest_fee');
        });

        Schema::table('stays', function (Blueprint $table) {
            // [{concept, amount, kind: extra_guests|optional}] ya sumado en amount.
            $table->json('extra_charges')->nullable()->after('amount');
        });

        Schema::table('reservations', function (Blueprint $table) {
            // Igual que en stays: desglose ya sumado en total_amount.
            $table->json('extra_charges')->nullable()->after('total_amount');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['included_occupancy', 'extra_guest_fee', 'optional_charges']);
        });

        Schema::table('stays', function (Blueprint $table) {
            $table->dropColumn('extra_charges');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('extra_charges');
        });
    }
};
