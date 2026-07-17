<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Palanca por hotel: ¿puede el hotel capturar aprendizajes de su bot en
 * /asistente/aprendizajes (y desde la Bandeja)? Apagada = solo el
 * super-admin decide qué lecciones recibe el bot — mismo patrón que
 * context_editable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_agent_settings', function (Blueprint $table) {
            $table->boolean('guidelines_editable')->default(false)->after('context_editable');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_agent_settings', function (Blueprint $table) {
            $table->dropColumn('guidelines_editable');
        });
    }
};
