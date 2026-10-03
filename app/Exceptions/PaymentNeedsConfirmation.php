<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Aprobar este comprobante registraría dinero que la reserva ya tiene
 * cubierto. No se registra hasta que alguien confirme que de verdad entró
 * dinero de más (caso real cabañas 2026-09-15: cinco comprobantes aprobados
 * sobre transferencias ya capturadas a mano, $7,500 duplicados).
 */
class PaymentNeedsConfirmation extends InvalidArgumentException
{
    /** @param  string|null  $confirmLabel  texto del botón que confirma (default: "Sí, entró dinero de más") */
    public function __construct(string $message = '', public readonly ?string $confirmLabel = null)
    {
        parent::__construct($message);
    }
}
