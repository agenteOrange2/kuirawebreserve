<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Canales de conversación del hotel (webchat hoy; whatsapp/messenger/
        // instagram en fases siguientes — spec-pendientes §4.5).
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // webchat|whatsapp|messenger|instagram
            $table->string('name');
            // auto = el bot responde solo · copilot = el bot sugiere y un
            // humano aprueba · off = solo alimenta la bandeja.
            $table->string('mode')->default('auto');
            $table->json('credentials')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['property_id', 'type']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // identificador público (webchat)
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('status')->default('open'); // open|pending|resolved
            // Handoff: false = un humano tomó la conversación.
            $table->boolean('bot_enabled')->default(true);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction'); // in (visitante) | out (hotel)
            $table->string('sender_type'); // visitor|bot|staff|system
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->json('meta')->nullable(); // tool calls, tokens, canal…
            $table->dateTime('read_at')->nullable(); // leído por staff (bandeja)
            $table->dateTime('created_at');

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('channels');
    }
};
