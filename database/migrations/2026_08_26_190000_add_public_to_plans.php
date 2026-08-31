<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planes privados: `active` decía dos cosas a la vez (se puede asignar Y se
 * anuncia en la página de inicio). Se separan para poder armar planes a la
 * medida de un hotel sin publicarlos: `public = false` los deja fuera del
 * landing y del formulario de prospectos, pero siguen asignables desde
 * /admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('public')->default(true)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('public');
        });
    }
};
