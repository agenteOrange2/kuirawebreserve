<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Icono del tipo de habitación (docs/spec-plano-pantalla-completa.md): en el
 * plano el nombre del tipo desaparece al alejarse, y el icono sí sobrevive
 * junto al número. Guarda el nombre del icono del theme (App\Support\
 * RoomTypeIcons); null = sin icono, que es como quedan los tipos ya creados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->string('icon', 40)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};
