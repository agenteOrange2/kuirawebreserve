<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instrucciones de PLATAFORMA por hotel: el super-admin ajusta el
 * comportamiento del bot (cómo cotizar, cómo apartar, métodos de pago...)
 * por encima de las instrucciones del propio hotel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_agent_settings', function (Blueprint $table) {
            $table->text('platform_instructions')->nullable()->after('api_allowed');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_agent_settings', function (Blueprint $table) {
            $table->dropColumn('platform_instructions');
        });
    }
};
