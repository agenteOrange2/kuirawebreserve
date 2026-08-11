<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Complemento del backfill anterior: la migración 200001 solo cubrió
 * basic/pro, pero el admin ya había creado planes propios en /admin/plans
 * (p. ej. "premium", con hoteles vivos). CUALQUIER plan anterior al
 * escalonado comercial vendía estas features como core — se le agregan
 * los toggles a todo plan que no sea de la terna nueva.
 */
return new class extends Migration
{
    private const NEW_TOGGLES = [
        'tarifas-flexibles',
        'anticipos',
        'corte-caja',
        'bitacora',
        'crm-avanzado',
        'incidencias',
        'encuestas',
        'promos',
        'incidencias-avanzado',
        'encuestas-avanzado',
        'cupones',
        'tablero-avanzado',
    ];

    public function up(): void
    {
        $legacy = DB::table('plans')
            ->whereNotIn('key', ['esencial', 'profesional', 'empresarial'])
            ->get();

        foreach ($legacy as $plan) {
            $modules = json_decode($plan->modules ?? '[]', true) ?: [];

            DB::table('plans')->where('key', $plan->key)->update([
                'modules' => json_encode(array_values(array_unique([...$modules, ...self::NEW_TOGGLES]))),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // No se revierte: quitar módulos a planes vivos es decisión
        // comercial, no de una migración.
    }
};
