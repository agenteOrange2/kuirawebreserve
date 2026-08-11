<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Condiciones de promoción del documento base:
 * - Temporadas/promos con `min_nights` = descuento por estancia larga (la
 *   temporada solo rige cuando la estancia alcanza esas noches/periodos).
 * - Cupones con condiciones: noches mínimas, tipo de habitación, cliente
 *   frecuente (visitas mínimas) y cumpleaños (válido solo en fechas
 *   cercanas al cumpleaños del huésped).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_plan_seasons', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_nights')->nullable()->after('weekdays');
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_nights')->nullable()->after('value');
            $table->unsignedSmallInteger('min_visits')->nullable()->after('min_nights');
            $table->foreignId('room_type_id')->nullable()->after('min_visits')->constrained()->nullOnDelete();
            $table->boolean('birthday')->default(false)->after('room_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('rate_plan_seasons', function (Blueprint $table) {
            $table->dropColumn('min_nights');
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_type_id');
            $table->dropColumn(['min_nights', 'min_visits', 'birthday']);
        });
    }
};
