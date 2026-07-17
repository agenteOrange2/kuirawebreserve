<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extras del wizard público (área aislada /ajustes/wizard): el huésped
 * puede pedir productos reales del inventario (POS) durante la reserva.
 *
 * - `products.available_in_wizard`: el admin CURA qué productos son
 *   público-presentables (no todo el inventario — ingredientes crudos o
 *   artículos de back-of-house no deben verse en el wizard).
 * - `reservations.products`: snapshot JSON de lo elegido (mismo patrón que
 *   `extra_charges` — id/nombre/cantidad/precio congelados al momento de
 *   la reserva). NO descuenta stock aquí — el stock real se descuenta
 *   recién al check-in (TransitionReservation::checkIn), cuando la
 *   reserva se materializa en una Order real vía CreateOrder. Un hold que
 *   expira sin confirmarse nunca tocó inventario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('available_in_wizard')->default(false)->after('active');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->json('products')->nullable()->after('extra_charges');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('available_in_wizard');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('products');
        });
    }
};
