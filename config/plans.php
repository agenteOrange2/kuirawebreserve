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

return [
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
        'modules' => ['pos'],
        'ai' => ['enabled' => false, 'monthly_replies' => 0],
    ],

    'pro' => [
        'label' => 'Pro',
        'max_properties' => 3,
        'max_rooms' => 150,
        'max_users' => 20,
        'max_channels' => 3,
        'max_gateways' => 3,
        'modules' => ['pos', 'cobros', 'agente-ia', 'extras', 'experiencias', 'grupos'],
        // IA incluida con cuota mensual de respuestas del bot (null = sin límite).
        'ai' => ['enabled' => true, 'monthly_replies' => 500],
    ],
];
