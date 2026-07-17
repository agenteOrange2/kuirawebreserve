<?php

namespace App\Services\Payments;

use App\Models\Central\PaymentMethodSetting;

/**
 * Punto único de verdad sobre qué métodos de cobro EN LÍNEA están
 * disponibles: plataforma manda (apagado global = no existe para nadie) y
 * el override por hotel refina. Sin fila = habilitado. Efectivo/tarjeta de
 * mostrador no pasan por aquí: son registro contable, no oferta al huésped.
 */
class PaymentMethodGate
{
    public const METHODS = [
        'transfer' => 'Transferencia bancaria',
        'stripe' => 'Stripe',
        'mercadopago' => 'Mercado Pago',
        'paypal' => 'PayPal',
    ];

    public function platformEnabled(string $method): bool
    {
        return PaymentMethodSetting::query()
            ->whereNull('tenant_id')
            ->where('method', $method)
            ->value('enabled') ?? true;
    }

    public function enabledFor(string $tenantId, string $method): bool
    {
        if (! $this->platformEnabled($method)) {
            return false;
        }

        return PaymentMethodSetting::query()
            ->where('tenant_id', $tenantId)
            ->where('method', $method)
            ->value('enabled') ?? true;
    }

    /**
     * Mapa método => habilitado efectivo para un hotel.
     *
     * @return array<string, bool>
     */
    public function methodsFor(string $tenantId): array
    {
        return collect(self::METHODS)
            ->mapWithKeys(fn ($label, $method) => [$method => $this->enabledFor($tenantId, $method)])
            ->all();
    }

    /** Fija el interruptor global (tenant_id null) o el de un hotel. */
    public function set(?string $tenantId, string $method, bool $enabled): void
    {
        PaymentMethodSetting::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'method' => $method],
            ['enabled' => $enabled],
        );
    }
}
