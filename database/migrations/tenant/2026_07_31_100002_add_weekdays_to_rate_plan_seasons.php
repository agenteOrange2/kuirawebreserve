<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Precios por día de la semana: una temporada puede limitarse a ciertos
 * días (weekdays JSON, 0=domingo..6=sábado; null = todos los días, el
 * comportamiento de siempre). Además las fechas se vuelven opcionales para
 * permitir reglas recurrentes permanentes ("todos los viernes y sábados"):
 * sin fechas + weekdays aplica todo el año esos días.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_plan_seasons', function (Blueprint $table) {
            $table->json('weekdays')->nullable()->after('ends_on');
        });

        Schema::table('rate_plan_seasons', function (Blueprint $table) {
            $table->date('starts_on')->nullable()->change();
            $table->date('ends_on')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('rate_plan_seasons', function (Blueprint $table) {
            $table->dropColumn('weekdays');
        });

        Schema::table('rate_plan_seasons', function (Blueprint $table) {
            $table->date('starts_on')->nullable(false)->change();
            $table->date('ends_on')->nullable(false)->change();
        });
    }
};
