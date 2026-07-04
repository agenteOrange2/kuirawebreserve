<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DB CENTRAL: la IA es producto de la plataforma. Aquí viven las keys
     * maestras, la asignación por tenant y el consumo (costo-beneficio).
     */
    public function up(): void
    {
        // Keys maestras de la plataforma (Claude, GPT, DeepSeek, Kimi, MiniMax).
        Schema::create('platform_ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('model');
            $table->text('api_key'); // cifrada
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['active', 'sort_order']);
        });

        // Configuración del agente POR TENANT (la administra el super-admin).
        Schema::create('tenant_agent_settings', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->unique();
            $table->boolean('enabled')->default(true); // kill switch por tenant
            // Proveedor asignado; null = cadena automática (todos los activos).
            $table->foreignId('platform_ai_provider_id')->nullable()
                ->constrained('platform_ai_providers')->nullOnDelete();
            // Override de cuota; null = la del plan.
            $table->unsignedInteger('monthly_reply_limit')->nullable();
            // BYOK: el hotel puede usar sus propias keys (enterprise).
            $table->boolean('byok_allowed')->default(false);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        // Consumo diario por tenant (rollup en tiempo real desde el bot).
        Schema::create('tenant_ai_usage', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->date('date');
            $table->unsignedInteger('replies')->default(0);
            $table->unsignedBigInteger('prompt_tokens')->default(0);
            $table->unsignedBigInteger('completion_tokens')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'date']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_ai_usage');
        Schema::dropIfExists('tenant_agent_settings');
        Schema::dropIfExists('platform_ai_providers');
    }
};
