<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case DepositPaid = 'deposit_paid';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Sin pago',
            self::DepositPaid => 'Anticipo pagado',
            self::Paid => 'Pagada',
        };
    }
}
