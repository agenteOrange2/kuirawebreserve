<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp vía Evolution API (self-hosted) como alternativa a la Cloud API
 * de Meta: cada fila es una instancia conectada por un hotel. El webhook
 * central enruta por webhook_token → tenant (equivalente de meta_channel_links).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evolution_channel_links', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('name')->nullable();
            $table->string('base_url');
            $table->string('instance');
            $table->text('api_key'); // cifrada (cast encrypted)
            $table->string('webhook_token', 64)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();

            // Una instancia de un servidor Evolution solo puede estar
            // conectada a un hotel a la vez.
            $table->unique(['base_url', 'instance']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evolution_channel_links');
    }
};
