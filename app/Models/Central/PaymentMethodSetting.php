<?php

namespace App\Models\Central;

/**
 * Interruptor de un método de pago: global (tenant_id null) o por hotel.
 * La verdad efectiva la resuelve PaymentMethodGate (global Y tenant).
 */
class PaymentMethodSetting extends CentralModel
{
    protected $table = 'payment_method_settings';

    protected $fillable = [
        'tenant_id',
        'method',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}
