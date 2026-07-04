<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Proveedores LLM POR HOTEL: cada tenant da de alta sus propias keys
        // (Anthropic, OpenAI, DeepSeek, Kimi, MiniMax) para probar costo-
        // beneficio. Los activos forman la cadena de fallback del bot.
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('provider'); // anthropic|openai|deepseek|kimi|minimax
            $table->string('model');
            $table->text('api_key'); // cifrada (cast encrypted)
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
