<?php

return [
    // Vigencia de una solicitud de cobro por transferencia: hay banco de por
    // medio, así que es mucho más amplia que la de pasarela (spec-pagos §6.1).
    // Mientras la solicitud viva, el hold de la reserva se extiende con ella.
    'transfer_hours' => env('PAYMENT_TRANSFER_HOURS', 24),

    // Vigencia del checkout de pasarela (F1). Definida desde F0 para que la
    // extensión de hold no cambie de semántica al conectar la primera pasarela.
    'gateway_minutes' => env('PAYMENT_GATEWAY_MINUTES', 120),
];
