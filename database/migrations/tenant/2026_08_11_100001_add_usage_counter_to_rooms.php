<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contador de usos por habitación con candado opcional: usage_count se
 * incrementa cada vez que la habitación queda por limpiar tras usarse;
 * usage_limit (opcional, por habitación) activa el candado — al alcanzarlo
 * la habitación se marca usage_locked_at y sale de disponibilidad hasta que
 * recepción resetea el contador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedInteger('usage_count')->default(0)->after('status');
            $table->unsignedInteger('usage_limit')->nullable()->after('usage_count');
            $table->timestamp('usage_locked_at')->nullable()->after('usage_limit');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['usage_count', 'usage_limit', 'usage_locked_at']);
        });
    }
};
