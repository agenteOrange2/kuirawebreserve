<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incidencias de mantenimiento: ticket con seguimiento (reportó, asignada,
 * prioridad, estado, resolución y fotos vía medialibrary). Complementa al
 * estado "mantenimiento" del semáforo y a los bloqueos por fechas: aquí
 * vive el QUÉ pasó y quién lo atiende, no la disponibilidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            // Nullable: también hay incidencias de áreas generales
            // (alberca, recepción...) sin habitación de por medio.
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->string('priority', 10)->default('medium'); // low|medium|high
            $table->string('status', 15)->default('open'); // open|in_progress|resolved
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
