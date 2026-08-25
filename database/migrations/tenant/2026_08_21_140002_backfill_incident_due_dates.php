<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Las incidencias que ya existían nacieron sin `due_at`, y el comando de
 * vencidas solo mira las que tienen plazo: sin este relleno, los tickets
 * viejos —justo los que llevan semanas abiertos— seguirían invisibles.
 *
 * Se calcula desde su fecha de creación con las horas por defecto (4/24/72):
 * el hotel todavía no ha podido configurar las suyas.
 *
 * El cálculo va en PHP y no en SQL a propósito: `DATE_ADD` es de MySQL y las
 * pruebas corren sobre SQLite. Son decenas de filas por hotel, una sola vez.
 */
return new class extends Migration
{
    private const HOURS = ['high' => 4, 'medium' => 24, 'low' => 72];

    public function up(): void
    {
        DB::table('incidents')
            ->whereNull('due_at')
            ->select('id', 'priority', 'created_at')
            ->orderBy('id')
            ->chunk(200, function ($incidents) {
                foreach ($incidents as $incident) {
                    $hours = self::HOURS[$incident->priority] ?? self::HOURS['medium'];

                    DB::table('incidents')
                        ->where('id', $incident->id)
                        ->update([
                            'due_at' => Carbon::parse($incident->created_at)->addHours($hours),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // El plazo se recalcula solo al crear o cambiar prioridad: vaciarlo
        // no pierde nada que no se pueda reconstruir.
        DB::table('incidents')->update(['due_at' => null]);
    }
};
