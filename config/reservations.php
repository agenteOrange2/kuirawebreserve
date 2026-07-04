<?php

return [
    // Minutos que vive un hold (reserva pendiente) antes de dejar de
    // bloquear disponibilidad y ser cancelada por el scheduler.
    'hold_minutes' => env('RESERVATION_HOLD_MINUTES', 30),

    // Al vencer planned_end_at de una estancia activa, el scheduler hace el
    // check-out y la habitación pasa a "sucia" para housekeeping. El periodo
    // de gracia da margen a extender la estancia antes de que se cierre sola.
    'auto_checkout' => [
        'enabled' => env('RESERVATION_AUTO_CHECKOUT', true),
        'grace_minutes' => env('RESERVATION_AUTO_CHECKOUT_GRACE', 15),
    ],
];
