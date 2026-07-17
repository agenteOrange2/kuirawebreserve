<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cola de validación del agente importador (spec-integracion-sitios §4):
 * lo que la IA extrae del sitio del hotel NUNCA se aplica solo — cae aquí
 * como sugerencia pendiente y un humano la aplica o descarta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_import_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('source_url', 500);
            // Tipo a actualizar; null = propone crear uno nuevo.
            $table->unsignedBigInteger('room_type_id')->nullable();
            $table->string('action', 10); // update | create
            $table->json('payload');
            $table->string('status', 12)->default('pending'); // pending | applied | discarded
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_import_suggestions');
    }
};
