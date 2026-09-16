<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cupones por día de la semana ("martes de descuento", "fin de semana
 * especial"): `weekdays` (0=domingo..6=sábado, null = toda la semana)
 * acota las NOCHES de la estancia en que el cupón vale. Misma convención
 * que rate_plan_seasons.weekdays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->json('weekdays')->nullable()->after('birthday');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('weekdays');
        });
    }
};
