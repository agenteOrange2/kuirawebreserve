<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de eventos de pasarela (spec-pagos §4.3): idempotencia — los
 * proveedores reintentan webhooks y sin dedupe se duplican pagos — y
 * auditoría para depurar "dice que pagó y no se reflejó".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('event_id');
            $table->foreignId('payment_request_id')->nullable()
                ->constrained('payment_requests')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->dateTime('processed_at');

            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_events');
    }
};
