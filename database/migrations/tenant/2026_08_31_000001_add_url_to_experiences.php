<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga pública del recorrido (su página en el sitio del hotel). Mismo papel
 * que `room_types.photos_url`: el asistente la comparte cuando preguntan qué
 * hacer, en vez de describir el tour a ciegas o inventar una URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experiences', function (Blueprint $table) {
            $table->string('url', 500)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('experiences', function (Blueprint $table) {
            $table->dropColumn('url');
        });
    }
};
