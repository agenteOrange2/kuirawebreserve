<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            // Tipo de falla (catálogo fijo del documento base: aire, fuga,
            // eléctrica, TV...): permite agrupar en reportes y detectar la
            // falla repetitiva. Nullable: las incidencias viejas no lo traen.
            $table->string('category', 20)->nullable()->after('title');

            // Quién levantó el reporte: el staff o un huésped que avisó en
            // recepción ("daño reportado por huésped" del documento).
            $table->string('source', 10)->default('staff')->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['category', 'source']);
        });
    }
};
