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
        // Sin asistente IA (palanca de upsell a Pro).
        'ai' => ['enabled' => false, 'monthly_replies' => 0],
    ],

    'pro' => [
        'label' => 'Pro',
        'max_properties' => 3,
        'max_rooms' => 150,
        'max_users' => 20,
        // IA incluida con cuota mensual de respuestas del bot (null = sin límite).
        'ai' => ['enabled' => true, 'monthly_replies' => 500],
    ],
];
