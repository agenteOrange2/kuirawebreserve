<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas por cerrar, ahora también sin estancia.
 *
 * La bandeja de /reservas/cuentas nació mirando estancias, y eso solo
 * alcanza en un hotel que registra check-ins. El que vende por chat y cobra
 * por transferencia casi nunca abre el plano: el cierre de día completa la
 * reserva (rooms:advance-housekeeping) sin que exista estancia, así que el
 * saldo no aparecía en ninguna pantalla. En cabañas eso son 319 reservas
 * completadas con dinero sin registrar, y la brecha entre lo vendido y lo
 * capturado creció de 48% en julio a 73% en septiembre.
 *
 * settlement_closed_at / settlement_note: alguien resolvió la cuenta sin
 * cobrarla y dejó por qué. Mismo trato que en stays.
 *
 * El backfill cierra lo viejo con su motivo escrito: una bandeja que nace
 * con 319 renglones de reservas de abril no se trabaja, se ignora. Las de
 * los últimos 30 días quedan vivas, que es lo que el hotel todavía puede
 * cobrar; el resto sigue consultable en el filtro "cerradas".
 */
return new class extends Migration
{
    /** Hasta dónde atrás vale la pena perseguir un saldo. */
    protected const DIAS_VIVOS = 30;

    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('settlement_closed_at')->nullable()->after('cancellation_reason');
            $table->string('settlement_note', 255)->nullable()->after('settlement_closed_at');
        });

        DB::table('reservations')
            ->where('status', 'completed')
            ->where('ends_at', '<', now()->subDays(self::DIAS_VIVOS))
            ->whereNull('settlement_closed_at')
            ->whereRaw('reservations.total_amount > (select coalesce(sum(p.amount), 0) from payments p'
                ." where p.reservation_id = reservations.id and (p.kind is null or p.kind <> 'guarantee'))")
            ->update([
                'settlement_closed_at' => now(),
                'settlement_note' => 'Cerrada al estrenar la bandeja de cuentas: saldo anterior, sin registro de cobro.',
            ]);
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['settlement_closed_at', 'settlement_note']);
        });
    }
};
