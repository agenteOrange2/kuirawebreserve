<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Escalonado comercial (documento "Planes de Suscripción Mensual"): crea
 * Esencial / Profesional / Empresarial en la DB central (la verdad viva,
 * editable en /admin/plans) y hace BACKFILL de los planes legacy
 * (basic/pro): las features que hoy se separan en módulos
 * (corte-caja, bitácora, incidencias, encuestas, promos...) eran core
 * cuando esos hoteles contrataron — se les agregan para no quitarles nada.
 *
 * Los valores van EN DURO y no de config('plans'): al correr esta
 * migración el AppServiceProvider ya hidrató config desde la tabla, así
 * que el archivo no es confiable aquí. El precio queda en 0: se captura
 * en /admin/plans según la tabla "Resumen de Planes e Inversión".
 */
return new class extends Migration
{
    /** Toggles que el Profesional enciende sobre el Esencial. */
    private const PROFESIONAL_TOGGLES = [
        'tarifas-flexibles',
        'anticipos',
        'corte-caja',
        'bitacora',
        'crm-avanzado',
        'incidencias',
        'encuestas',
        'promos',
    ];

    /** Lo que el Empresarial agrega sobre el Profesional. */
    private const EMPRESARIAL_TOGGLES = [
        'incidencias-avanzado',
        'encuestas-avanzado',
        'cupones',
        'tablero-avanzado',
    ];

    public function up(): void
    {
        $plans = [
            [
                'key' => 'esencial',
                'label' => 'Esencial',
                'description' => 'Control inicial de reservas: ordena la recepción, disponibilidad en tiempo real, limpieza básica y bloqueo por mantenimiento.',
                'max_rooms' => 15,
                'max_users' => 3,
                'max_channels' => 0,
                'max_gateways' => 0,
                'modules' => [],
                'sort_order' => 1,
            ],
            [
                'key' => 'profesional',
                'label' => 'Profesional',
                'description' => 'Operación recomendada: tarifas flexibles, anticipos, cortes de caja, CRM completo, incidencias, encuestas, promociones y bitácora.',
                'max_rooms' => 40,
                'max_users' => 8,
                'max_channels' => 1,
                'max_gateways' => 0,
                'modules' => self::PROFESIONAL_TOGGLES,
                'sort_order' => 2,
            ],
            [
                'key' => 'empresarial',
                'label' => 'Empresarial',
                'description' => 'Control avanzado y acompañamiento: incidencias con responsables, satisfacción con alertas, cupones con condiciones y tablero personalizado.',
                'max_rooms' => 80,
                'max_users' => 15,
                'max_channels' => 3,
                'max_gateways' => 1,
                'modules' => array_values(array_unique([...self::PROFESIONAL_TOGGLES, ...self::EMPRESARIAL_TOGGLES])),
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            // Upsert: en instalaciones frescas la fila ya existe (la sembró
            // create_plans desde config, con modules de relleno) — aquí se
            // asientan los valores canónicos del escalonado. El precio no
            // se pisa: lo captura el admin.
            DB::table('plans')->updateOrInsert(
                ['key' => $plan['key']],
                [
                    'label' => $plan['label'],
                    'description' => $plan['description'],
                    'max_properties' => 1,
                    'max_rooms' => $plan['max_rooms'],
                    'max_users' => $plan['max_users'],
                    'max_channels' => $plan['max_channels'],
                    'max_gateways' => $plan['max_gateways'],
                    'modules' => json_encode($plan['modules']),
                    'ai_enabled' => false,
                    'ai_monthly_replies' => 0,
                    'active' => true,
                    'sort_order' => $plan['sort_order'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        // Backfill de los planes legacy: TODOS los toggles nuevos.
        $allToggles = array_values(array_unique([...self::PROFESIONAL_TOGGLES, ...self::EMPRESARIAL_TOGGLES]));

        foreach (DB::table('plans')->whereIn('key', ['basic', 'pro'])->get() as $legacy) {
            $modules = json_decode($legacy->modules ?? '[]', true) ?: [];

            DB::table('plans')->where('key', $legacy->key)->update([
                'modules' => json_encode(array_values(array_unique([...$modules, ...$allToggles]))),
                // Los comerciales van primero en el admin y en el sitio.
                'sort_order' => $legacy->key === 'basic' ? 10 : 11,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('plans')->whereIn('key', ['esencial', 'profesional', 'empresarial'])->delete();
        // El backfill de basic/pro no se revierte: quitar módulos a planes
        // vivos es una decisión comercial, no de una migración.
    }
};
