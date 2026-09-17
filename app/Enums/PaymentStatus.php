<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Unpaid = 'unpaid';

    /**
     * Abonó algo pero no alcanza ni el anticipo. Caso real cabañas
     * 2026-09-15: $1,700 de un anticipo de $1,750 se mostraba "Sin pago".
     */
    case Partial = 'partial';

    case DepositPaid = 'deposit_paid';

    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Sin pago',
            self::Partial => 'Anticipo incompleto',
            // Solo dio el apartado: falta el saldo. "Anticipo pagado" se leía
            // como si ya no debiera nada.
            self::DepositPaid => 'Pago parcial',
            self::Paid => 'Pagada',
        };
    }

    /** ¿Ya cubrió el anticipo? Un abono que no lo alcanza no confirma nada. */
    public function coversDeposit(): bool
    {
        return $this === self::DepositPaid || $this === self::Paid;
    }
}
