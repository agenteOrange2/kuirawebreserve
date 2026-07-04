<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (1) Palanca "API de integraciones" por tenant: los tokens/playground/guía
 * de /asistente solo se muestran si la plataforma lo permite (como BYOK).
 * (2) Canales Meta conectados (DB CENTRAL): mapean el id externo que llega
 * en el webhook (phone_number_id de WhatsApp, page_id de FB/IG) al tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_agent_settings', function (Blueprint $table) {
            $table->boolean('api_allowed')->default(false)->after('byok_allowed');
        });

        Schema::create('meta_channel_links', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('type', 20); // whatsapp | messenger | instagram
            $table->string('external_id'); // phone_number_id o page_id / ig_business_id
            $table->text('access_token'); // cifrado (cast encrypted)
            $table->string('name')->nullable(); // etiqueta: "WhatsApp prueba", página, número
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'external_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_agent_settings', function (Blueprint $table) {
            $table->dropColumn('api_allowed');
        });
        Schema::dropIfExists('meta_channel_links');
    }
};
