<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los aspectos del cuestionario se vuelven personalizables por hotel
 * (settings.survey_aspects): las respuestas dejan de vivir en columnas
 * fijas y pasan a un JSON {aspecto: calificación}. Las tres columnas
 * originales (rating_cleanliness/service/facilities) se conservan para
 * leer las respuestas históricas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stay_surveys', function (Blueprint $table) {
            $table->json('answers')->nullable()->after('rating');
        });
    }

    public function down(): void
    {
        Schema::table('stay_surveys', function (Blueprint $table) {
            $table->dropColumn('answers');
        });
    }
};
