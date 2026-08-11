<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuestionario de experiencia post-estancia: una encuesta por estancia,
 * creada al enviar el agradecimiento del check-out con un token público
 * de un solo uso. Las respuestas alimentan el reporte de /encuestas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stay_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token', 64)->unique();
            // 1..5; null mientras el huésped no responde.
            $table->unsignedTinyInteger('rating')->nullable();
            $table->unsignedTinyInteger('rating_cleanliness')->nullable();
            $table->unsignedTinyInteger('rating_service')->nullable();
            $table->unsignedTinyInteger('rating_facilities')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stay_surveys');
    }
};
