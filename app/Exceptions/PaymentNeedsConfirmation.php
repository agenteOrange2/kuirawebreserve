<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Aprobar este comprobante registraría dinero que la reserva ya tiene
 * cubierto. No se registra hasta que alguien confirme que de verdad entró
 * dinero de más (caso real cabañas 2026-09-15: cinco comprobantes aprobados
 * sobre transferencias ya capturadas a mano, $7,500 duplicados).
 */
class PaymentNeedsConfirmation extends InvalidArgumentException {}
