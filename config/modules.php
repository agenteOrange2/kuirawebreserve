<?php

/*
|--------------------------------------------------------------------------
| Catálogo de módulos de la plataforma
|--------------------------------------------------------------------------
|
| Módulo = capacidad que se enciende o apagada por plan (los límites
| contables siguen en config/plans.php). El catálogo vive en código porque
| un módulo implica rutas, menú y tools; el admin solo decide quién lo
| tiene: `plans.modules` (JSON) define el plan y `tenant_modules` (DB
| central) fuerza excepciones por hotel. La verdad efectiva la resuelve
| Tenant::hasModule().
|
| `available` = false: módulo aún en desarrollo — se puede incluir en
| planes desde ya (aparece "En desarrollo" en el admin), su área aparece
| cuando exista. Spec: docs/spec-plan-maestro.md §3.
|
*/

return [
    'pos' => [
        'label' => 'Punto de venta',
        'description' => 'POS, inventario, turnos y cortes de venta.',
        'available' => true,
    ],

    'cobros' => [
        'label' => 'Cobros en línea',
        'description' => 'Pasarelas de pago (Stripe, Mercado Pago) y links de cobro. Las transferencias con verificación van en todos los planes.',
        'available' => true,
    ],

    'agente-ia' => [
        'label' => 'Asistente IA',
        'description' => 'Bot que responde y aparta por chat con las llaves de la plataforma; la cuota mensual la define el plan.',
        'available' => true,
    ],

    'motor-web' => [
        'label' => 'Motor de reservas web',
        'description' => 'Integración con sitios (WordPress): catálogo con precios en vivo, tokens e importador; el wizard público de reservas viene en camino.',
        'available' => true,
    ],

    'extras' => [
        'label' => 'Extras de reserva',
        'description' => 'Add-ons que suman al total: decoraciones, desayuno, late checkout.',
        'available' => true,
    ],

    'experiencias' => [
        'label' => 'Experiencias',
        'description' => 'Tours y recorridos con horario y cupo propios.',
        'available' => true,
    ],

    'grupos' => [
        'label' => 'Reservas grupales',
        'description' => 'Varias habitaciones en una sola reserva: un folio de grupo, todo-o-nada.',
        'available' => true,
    ],
];
