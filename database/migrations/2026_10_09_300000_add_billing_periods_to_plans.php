<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pago por adelantado: además del mes, cada plan puede venderse por
 * trimestre, semestre, año o más, con su descuento por tiempo. Lista de
 * {months, discount}; vacía = solo mensual (lo que había hasta hoy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->json('billing_periods')->nullable()->after('activation_fee');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('billing_periods');
        });
    }
};
