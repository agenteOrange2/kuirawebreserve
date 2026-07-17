<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Palanca por hotel: ¿puede el hotel ver/editar el contexto de su bot en
 * /asistente/contexto? Apagada = solo el super-admin gestiona ese contexto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_agent_settings', function (Blueprint $table) {
            $table->boolean('context_editable')->default(false)->after('platform_instructions');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_agent_settings', function (Blueprint $table) {
            $table->dropColumn('context_editable');
        });
    }
};
