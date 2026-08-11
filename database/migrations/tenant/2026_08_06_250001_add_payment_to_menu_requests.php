<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo quiere pagar el huésped su pedido del menú (elegido en /menu):
 * cargo a la habitación (modo hotel: se paga al final, en el check-out) o
 * pago al recibir (efectivo/tarjeta; único camino en modo motel). Es la
 * preferencia que el staff cumple al entregar — el cobro real sigue
 * pasando por el POS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_requests', function (Blueprint $table) {
            $table->string('payment_mode', 20)->default('on_delivery')->after('total');
            $table->string('payment_method', 20)->nullable()->after('payment_mode');
        });
    }

    public function down(): void
    {
        Schema::table('menu_requests', function (Blueprint $table) {
            $table->dropColumn(['payment_mode', 'payment_method']);
        });
    }
};
