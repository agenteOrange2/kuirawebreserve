<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            // Duración del periodo con unidad: minute|hour|day|week|month
            // (spec-profundidad §2.6.1). duration_minutes queda como derivado
            // para unidades exactas; los meses son calendario.
            $table->string('duration_unit')->nullable()->after('duration_minutes');
            $table->unsignedInteger('duration_value')->nullable()->after('duration_unit');

            // Antelación mínima para reservar (spec §2.6.2): hour|day|week.
            $table->string('min_advance_unit')->nullable()->after('price');
            $table->unsignedInteger('min_advance_value')->nullable()->after('min_advance_unit');
        });

        // Backfill: los bloques existentes quedan expresados en minutos.
        DB::table('rate_plans')
            ->where('type', 'block')
            ->whereNotNull('duration_minutes')
            ->update([
                'duration_unit' => 'minute',
                'duration_value' => DB::raw('duration_minutes'),
            ]);
    }

    public function down(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn(['duration_unit', 'duration_value', 'min_advance_unit', 'min_advance_value']);
        });
    }
};
