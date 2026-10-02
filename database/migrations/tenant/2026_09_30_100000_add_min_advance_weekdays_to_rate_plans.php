<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Días de LLEGADA en que aplica la antelación mínima (0=domingo..6=sábado).
     * Hotel México pide un día de anticipación solo para llegar viernes,
     * sábado o domingo. Null = aplica toda la semana, como hasta ahora.
     */
    public function up(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->json('min_advance_weekdays')->nullable()->after('min_advance_value');
        });
    }

    public function down(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn('min_advance_weekdays');
        });
    }
};
