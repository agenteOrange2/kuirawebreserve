<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aprendizajes del asistente: correcciones puntuales que el hotel captura
 * de conversaciones reales ("cuando pidan varias cabañas, aparta TODAS y
 * reporta cada resultado"). Se inyectan al system prompt como reglas del
 * hotel — el bot se alimenta de sus propios errores, con control humano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_guidelines', function (Blueprint $table) {
            $table->id();
            $table->string('instruction', 500);
            // De qué conversación salió la lección (opcional, para el rastro).
            $table->foreignId('source_conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_guidelines');
    }
};
