<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Más de un canal del mismo tipo por propiedad (varias instancias de
 * WhatsApp Evolution, cada una con su propio modo auto/copilot/off).
 * external_id identifica la instancia: id del evolution_channel_link central.
 */
return new class extends Migration
{
    public function up(): void
    {
        // El índice sustituto va PRIMERO: en MySQL la FK de property_id se
        // apoya en el índice único y no deja soltarlo sin un reemplazo.
        Schema::table('channels', function (Blueprint $table) {
            $table->index(['property_id', 'type']);
            $table->string('external_id')->nullable()->after('type')->index();
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->dropUnique(['property_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->unique(['property_id', 'type']);
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->dropIndex(['property_id', 'type']);
            $table->dropIndex(['external_id']);
            $table->dropColumn('external_id');
        });
    }
};
