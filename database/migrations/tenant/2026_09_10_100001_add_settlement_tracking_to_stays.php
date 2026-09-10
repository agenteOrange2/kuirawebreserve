<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas por cerrar.
 *
 * El cierre automático de estancias vencidas (stays:auto-checkout) cerraba
 * la estancia sin mirar la cuenta: la salida manual exige cobrar el saldo o
 * forzarla a propósito, y la del reloj se saltaba las dos cosas. Después ya
 * no había nada que hacer —los cargos se rechazan en una estancia cerrada y
 * no existía pantalla para cobrar tarde—, así que el dinero desaparecía del
 * panel. En cabañas eso dejó trece estancias cerradas a las 11:15 sin un
 * peso de hospedaje registrado.
 *
 * - auto_closed_at: la cerró el reloj, no una persona.
 * - settlement_closed_at / settlement_note: alguien resolvió la cuenta sin
 *   cobrarla (cortesía, incobrable, error de captura) y dejó por qué.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->timestamp('auto_closed_at')->nullable()->after('check_out_at');
            $table->timestamp('settlement_closed_at')->nullable()->after('auto_closed_at');
            $table->string('settlement_note', 255)->nullable()->after('settlement_closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->dropColumn(['auto_closed_at', 'settlement_closed_at', 'settlement_note']);
        });
    }
};
