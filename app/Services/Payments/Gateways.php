<?php

namespace App\Services\Payments;

use InvalidArgumentException;

/** Resuelve el adapter por proveedor (spec-pagos §3.5). */
class Gateways
{
    public static function for(string $provider): PaymentGateway
    {
        return match ($provider) {
            'stripe' => app(StripeGateway::class),
            'mercadopago' => app(MercadoPagoGateway::class),
            'paypal' => app(PayPalGateway::class),
            default => throw new InvalidArgumentException("Pasarela desconocida: {$provider}."),
        };
    }
}
