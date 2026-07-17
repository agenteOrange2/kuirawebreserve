<?php

namespace App\Models\Central;

use Illuminate\Support\Str;

/**
 * Pasarela de pago conectada por un hotel (spec-pagos §4.4): sus propias
 * llaves API (el dinero va directo a la cuenta del hotel). El webhook del
 * proveedor apunta a /webhooks/payments/{webhook_token}; el token enruta al
 * tenant y la firma del proveedor autentica el evento.
 */
class PaymentGatewayLink extends CentralModel
{
    public const PROVIDERS = [
        'stripe' => 'Stripe',
        'mercadopago' => 'Mercado Pago',
        'paypal' => 'PayPal',
    ];

    protected $table = 'payment_gateway_links';

    protected $fillable = [
        'tenant_id',
        'provider',
        'mode',
        'public_key',
        'secret_key',
        'webhook_secret',
        'webhook_token',
        'active',
        'last_event_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'secret_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'active' => 'boolean',
            'last_event_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public static function generateToken(): string
    {
        return Str::random(48);
    }

    /** URL que se registra como webhook en el dashboard del proveedor. */
    public function webhookUrl(): string
    {
        return rtrim(config('app.url'), '/').'/webhooks/payments/'.$this->webhook_token;
    }

    public function providerLabel(): string
    {
        return self::PROVIDERS[$this->provider] ?? $this->provider;
    }

    public function maskedSecret(): string
    {
        $key = (string) $this->secret_key;

        return strlen($key) <= 4 ? '••••' : '••••'.substr($key, -4);
    }
}
