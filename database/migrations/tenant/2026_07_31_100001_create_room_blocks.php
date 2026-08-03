<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloqueos de habitación con fechas (mantenimiento programado): un rango de
 * días en que la habitación NO se ofrece en disponibilidad futura. No toca
 * el semáforo presente — solo lo descuenta el motor de disponibilidad
 * (AvailabilityService), igual para panel, wizard y agentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->date('starts_at');
            $table->date('ends_at');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['room_id', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_blocks');
    }
};
