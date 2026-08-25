<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nace el módulo `limpieza`: registro del trabajo de las camaristas (quién
 * limpió, cuánto tardó, qué hizo, cuánta ropa usó) y reporte de rendimiento.
 *
 * La verdad viva de los módulos por plan es esta tabla, no config/plans.php
 * (que es solo semilla), así que el toggle nuevo se escribe aquí para que los
 * hoteles existentes lo vean.
 *
 * A quién se le da: Profesional y Empresarial (decisión del dueño), más los
 * planes legacy o creados a mano en /admin/plans, que traen todos los
 * toggles. El Esencial conserva el semáforo de limpieza, que es core y no
 * depende de este módulo: no pierde nada de lo que ya tenía.
 */
return new class extends Migration
{
    private const MODULE = 'limpieza';

    public function up(): void
    {
        $plans = DB::table('plans')->where('key', '!=', 'esencial')->get();

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
