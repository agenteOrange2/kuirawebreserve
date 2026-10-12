<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cómo terminó cada limpieza y cuánto tiempo tenía (cabañas, 2026-10-12).
     *
     * Antes el reloj liberaba la habitación y el registro se quedaba abierto
     * para siempre: el cronómetro seguía corriendo y la siguiente limpieza de
     * ese cuarto se rechazaba por "ya tiene una en curso". Ahora el cierre
     * queda escrito —la camarista (staff), el reloj (clock) o un cambio del
     * semáforo que la interrumpió (status)— junto con el tiempo asignado al
     * iniciarla, para saber cuáles se entregaron a tiempo.
     */
    public function up(): void
    {
        Schema::table('room_cleanings', function (Blueprint $table) {
            $table->string('closed_by', 10)->nullable()->after('minutes');
            // Foto del ajuste al iniciar: cambiar el tiempo mañana no
            // reescribe si una limpieza de ayer fue a tiempo.
            $table->unsignedSmallInteger('target_minutes')->nullable()->after('closed_by');
        });

        // Lo cerrado hasta hoy lo cerró una persona: el reloj nunca cerraba
        // registros. Sin tiempo asignado no se califica.
        DB::table('room_cleanings')->whereNotNull('ended_at')->update(['closed_by' => 'staff']);

        // Las que siguen abiertas reciben el tiempo de hoy: el tablero les
        // pinta su hora límite y, si el hotel prende el switch, el reloj las
        // cierra como cualquier otra.
        DB::table('room_cleanings')->whereNull('ended_at')->update([
            'target_minutes' => app(\App\Services\HousekeepingPolicy::class)->cleaningMinutes(),
        ]);
    }

    public function down(): void
    {
        Schema::table('room_cleanings', function (Blueprint $table) {
            $table->dropColumn(['closed_by', 'target_minutes']);
        });
    }
};
