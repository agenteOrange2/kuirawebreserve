<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué canales puede conectar cada hotel desde su panel.
 *
 * Antes /asistente ofrecía los cuatro (Evolution, WhatsApp de Meta, Telegram
 * y TikTok) a todos, aunque el hotel solo tuviera contratado uno. Con esto la
 * plataforma decide desde /admin qué ve cada quien.
 *
 * NULL = todos (comportamiento anterior, para no quitarle nada a nadie al
 * desplegar); un arreglo = solo esos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_agent_settings', function (Blueprint $table) {
            $table->json('channels_allowed')->nullable()->after('guidelines_editable');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_agent_settings', function (Blueprint $table) {
            $table->dropColumn('channels_allowed');
        });
    }
};
