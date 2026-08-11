<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ajuste de la comparativa 2026-08 al escalonado comercial:
 *
 * 1. El Esencial gana los renglones que el documento marca con "Sí":
 *    tarifas flexibles, corte de caja por turno y bitácora básica (los
 *    reportes PDF de reservas ya son core y el PDF del corte viaja con
 *    corte-caja).
 * 2. Nace el módulo `mensajeria` (bandeja + canales): la Bandeja daba
 *    acceso de facto al asistente IA en planes que no lo incluyen. Se
 *    agrega a TODOS los planes existentes menos al Esencial — antes del
 *    gate la bandeja era visible para todos, así que quitársela a un plan
 *    vivo sería regresión (mismo criterio que el backfill de 200001);
 *    el Esencial es exactamente el plan al que SÍ se le quita.
 */
return new class extends Migration
{
    private const ESENCIAL_MODULES = [
        'tarifas-flexibles',
        'corte-caja',
        'bitacora',
    ];

    public function up(): void
    {
        foreach (DB::table('plans')->get() as $plan) {
            $modules = json_decode($plan->modules ?? '[]', true) ?: [];

            if ($plan->key === 'esencial') {
                $modules = self::ESENCIAL_MODULES;
            } elseif (! in_array('mensajeria', $modules, true)) {
                array_unshift($modules, 'mensajeria');
            }

            DB::table('plans')->where('key', $plan->key)->update([
                'modules' => json_encode(array_values($modules)),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('plans')->get() as $plan) {
            $modules = json_decode($plan->modules ?? '[]', true) ?: [];

            if ($plan->key === 'esencial') {
                $modules = [];
            } else {
                $modules = array_values(array_diff($modules, ['mensajeria']));
            }

            DB::table('plans')->where('key', $plan->key)->update([
                'modules' => json_encode($modules),
                'updated_at' => now(),
            ]);
        }
    }
};
