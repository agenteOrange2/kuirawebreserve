<?php

/*
|--------------------------------------------------------------------------
| Wizard público de reservas (/reservar, spec-motor-reservas-web E0)
|--------------------------------------------------------------------------
|
| Anti-abuso mínimo v1 (spec §9.3): honeypot + tiempo mínimo de llenado.
| El límite de holds simultáneos por huésped/IP queda pendiente (ver
| "preguntas abiertas" del spec) — de momento el throttle de la ruta es
| la única defensa contra ráfagas.
|
*/

return [
    // Segundos mínimos entre que el paso 2 se mostró y se envió el
    // formulario. Un bot que rellena y manda en milisegundos cae aquí.
    'min_fill_seconds' => env('BOOKING_MIN_FILL_SECONDS', 3),

    // Purga de llaves de idempotencia más viejas que esto (mismo patrón
    // que agent_idempotency_keys).
    'idempotency_key_days' => env('BOOKING_IDEMPOTENCY_DAYS', 7),
];
