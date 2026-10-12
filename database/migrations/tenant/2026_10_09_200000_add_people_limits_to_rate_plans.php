<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Personas por TARIFA (Hotel México, 2026-10-09): la misma habitación
     * admite distinta gente según cómo se venda. La Sencilla por horas lleva
     * 2 incluidas y una tercera pagando, mientras su Día completo sigue con
     * 1 incluida y máximo 2; la Doble por horas solo admite 2 aunque de noche
     * admita 4. Null = lo que diga la habitación, como hasta ahora.
     */
    public function up(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->unsignedTinyInteger('included_people')->nullable()->after('price');
            $table->unsignedTinyInteger('max_people')->nullable()->after('included_people');
        });
    }

    public function down(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn(['included_people', 'max_people']);
        });
    }
};
