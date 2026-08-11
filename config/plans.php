<?php

/*
|--------------------------------------------------------------------------
| Planes de la plataforma
|--------------------------------------------------------------------------
|
| Límites por plan de cada hotel (tenant). `null` = sin límite. El cobro
| de los planes (pasarela, trial, suspensión por impago) es fase 7 del
| roadmap; aquí solo se definen los límites que el core hace cumplir.
|
*/

// Toggles del escalonado comercial (documento "Planes de Suscripción",
// comparativa 2026-08): lo que trae el Esencial además del core puro.
$esencialToggles = [
    'tarifas-flexibles',
    'corte-caja',
    'bitacora',
];

// Lo que el Profesional enciende sobre el Esencial. Compartido para que
// basic/pro legacy y los planes nuevos no dupliquen la lista a mano.
// La mensajería (bandeja + canales) también entra aquí: el Esencial no
// tiene canales (max_channels 0) ni bandeja.
$profesionalToggles = [
    'mensajeria',
    'anticipos',
    'crm-avanzado',
    'incidencias',
    'encuestas',
    'promos',
    ...$esencialToggles,
];

// Lo que el Empresarial agrega sobre el Profesional.
$empresarialToggles = [
    'incidencias-avanzado',
    'encuestas-avanzado',
    'cupones',
    'tablero-avanzado',
];

return [
    /*
    |----------------------------------------------------------------------
    | Planes comerciales (documento "Planes de Suscripción Mensual")
    |----------------------------------------------------------------------
    | Esencial = control inicial de reservas (solo core). Profesional =
    | operación recomendada. Empresarial = control avanzado. El precio se
    | captura en /admin/plans (la tabla central es la verdad viva).
    */

    'esencial' => [
        'label' => 'Esencial',
        'max_properties' => 1,
        'max_rooms' => 15,
        'max_users' => 3,
        'max_channels' => 0,
        'max_gateways' => 0,
        // Core (reservas, walk-in, check-in/out, disponibilidad, huéspedes
        // básico, pagos, limpieza básica, bloqueo por mantenimiento y
        // reportes PDF de reservas) + los toggles que la comparativa 2026-08
        // marca con "Sí": tarifas flexibles, corte de caja y bitácora.
        // SIN mensajería: la bandeja da acceso al asistente y a canales,
        // que este plan no incluye.
        'modules' => $esencialToggles,
        'ai' => ['enabled' => false, 'monthly_replies' => 0],
    ],

    'profesional' => [
        'label' => 'Profesional',
        'max_properties' => 1,
        'max_rooms' => 40,
        'max_users' => 8,
        'max_channels' => 1,
        'max_gateways' => 0,
        'modules' => $profesionalToggles,
        'ai' => ['enabled' => false, 'monthly_replies' => 0],
    ],

    'empresarial' => [
        'label' => 'Empresarial',
        'max_properties' => 1,
        'max_rooms' => 80,
        'max_users' => 15,
        'max_channels' => 3,
        // "Preparación para futuras integraciones" (pasarelas): una lista.
        'max_gateways' => 1,
        'modules' => [...$profesionalToggles, ...$empresarialToggles],
        'ai' => ['enabled' => false, 'monthly_replies' => 0],
    ],

    /*
    |----------------------------------------------------------------------
    | Planes legacy (tenants existentes)
    |----------------------------------------------------------------------
    | Incluyen TODOS los toggles nuevos: esas features eran core cuando
    | estos hoteles contrataron — separarlas no debe quitarles nada.
    */

    'basic' => [
        'label' => 'Básico',
        'max_properties' => 1,
        'max_rooms' => 30,
        'max_users' => 5,
        // Canales de mensajería conectados (números WhatsApp Meta/Evolution,
        // páginas...). El webchat propio no cuenta.
        'max_channels' => 1,
        // Pasarelas de pago conectadas (spec-pagos §12). Las transferencias
        // con verificación van en todos los planes; las pasarelas son del Pro.
        'max_gateways' => 0,
        // Módulos incluidos (catálogo en config/modules.php). Sin cobros ni
        // asistente IA (palanca de upsell a Pro).
        'modules' => array_values(array_unique(['pos', ...$profesionalToggles, ...$empresarialToggles])),
        'ai' => ['enabled' => false, 'monthly_replies' => 0],
    ],

    'pro' => [
        'label' => 'Pro',
        // 1 en TODOS los planes mientras el panel opere una sola propiedad
        // por hotel (PropertyController::store lo hace cumplir). Anunciar
        // más era prometer algo que choca contra ese bloqueo al intentarlo.
        // Cuando exista multipropiedad de verdad, este número sube aquí y
        // en la tabla central `plans`.
        'max_properties' => 1,
        'max_rooms' => 150,
        'max_users' => 20,
        'max_channels' => 3,
        'max_gateways' => 3,
        // SEMILLA: la verdad viva por plan es la tabla central `plans`
        // (editable en /admin/plans) — ahí se activan lista-espera y
        // cupones para los planes que ya existen en BD.
        'modules' => array_values(array_unique(['pos', 'cobros', 'agente-ia', 'extras', 'experiencias', 'grupos', 'lista-espera', 'cupones', ...$profesionalToggles, ...$empresarialToggles])),
        // IA incluida con cuota mensual de respuestas del bot (null = sin límite).
        'ai' => ['enabled' => true, 'monthly_replies' => 500],
    ],
];
