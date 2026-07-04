<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tokens de API (Sanctum) por tenant: el asistente IA se autentica
        // con Bearer token emitido desde el panel.
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        // Idempotencia de escrituras del agente: los LLM reintentan; la misma
        // Idempotency-Key devuelve la respuesta original sin duplicar holds.
        Schema::create('agent_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->unsignedSmallInteger('status');
            $table->longText('response');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_idempotency_keys');
        Schema::dropIfExists('personal_access_tokens');
    }
};
