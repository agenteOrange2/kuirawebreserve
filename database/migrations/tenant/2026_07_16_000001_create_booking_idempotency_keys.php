<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotencia del wizard público de reservas (spec-motor-reservas-web
 * §9.2), mismo patrón que agent_idempotency_keys pero en tabla propia:
 * es tráfico público sin sesión, distinto del canal de agentes IA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->unsignedSmallInteger('status');
            $table->longText('response');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_idempotency_keys');
    }
};
