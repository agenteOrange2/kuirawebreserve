<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Limpiezas abiertas cuya habitación ya NO está en limpieza: las dejó así
     * el bug que este cambio corrige (el semáforo salía de "en limpieza" y el
     * registro se quedaba abierto). Bloqueaban el siguiente "Iniciar" de ese
     * cuarto con "ya tiene una limpieza en curso".
     *
     * Se cierran como interrumpidas a la hora en que el semáforo dejó la
     * limpieza (o ahora, si no hay rastro), con sus minutos reales.
     */
    public function up(): void
    {
        $orphans = DB::table('room_cleanings')
            ->join('rooms', 'rooms.id', '=', 'room_cleanings.room_id')
            ->whereNull('room_cleanings.ended_at')
            ->where('rooms.status', '!=', 'cleaning')
            ->get(['room_cleanings.id', 'room_cleanings.room_id', 'room_cleanings.started_at']);

        foreach ($orphans as $cleaning) {
            $left = DB::table('room_status_logs')
                ->where('room_id', $cleaning->room_id)
                ->where('from_status', 'cleaning')
                ->where('created_at', '>=', $cleaning->started_at)
                ->orderBy('created_at')
                ->value('created_at');

            $ended = $left ? \Carbon\CarbonImmutable::parse($left) : now()->toImmutable();
            $started = \Carbon\CarbonImmutable::parse($cleaning->started_at);

            DB::table('room_cleanings')->where('id', $cleaning->id)->update([
                'ended_at' => $ended,
                'minutes' => max(0, (int) $started->diffInMinutes($ended)),
                'closed_by' => 'status',
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás: reabrirlas volvería a bloquear esos cuartos.
    }
};
