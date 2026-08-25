<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nace el módulo `plano-operativo`: los paneles que dejan operar sin salir
 * del plano (estado de la casa, alta y edición de habitaciones, consumos y
 * cobro, caja del turno).
 *
 * La verdad viva de los módulos por plan es esta tabla, no config/plans.php
 * (que es solo semilla), así que el toggle nuevo se escribe aquí para que
 * los hoteles existentes lo vean.
 *
 * A quién se le da, siguiendo el criterio de los backfills anteriores:
 * al Empresarial (es control avanzado) y a los planes legacy o creados a
 * mano en /admin/plans, que traen todos los toggles. Esencial y Profesional
 * lo contratan como servicio adicional. Como es una feature NUEVA, no dárselo
 * a esos dos no le quita nada a nadie.
 */
return new class extends Migration
{
    private const MODULE = 'plano-operativo';

    public function up(): void
    {
        $plans = DB::table('plans')
            ->whereNotIn('key', ['esencial', 'profesional'])
            ->get();

        foreach ($plans as $plan) {
            $modules = json_decode($plan->modules ?? '[]', true) ?: [];

            if (in_array(self::MODULE, $modules, true)) {
                continue;
            }

            $modules[] = self::MODULE;

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

            if (! in_array(self::MODULE, $modules, true)) {
                continue;
            }

            DB::table('plans')->where('key', $plan->key)->update([
                'modules' => json_encode(array_values(array_diff($modules, [self::MODULE]))),
                'updated_at' => now(),
            ]);
        }
    }
};
