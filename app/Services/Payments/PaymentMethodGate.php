<?php

namespace App\Services\Payments;

use App\Models\Central\PaymentGatewayLink;
use App\Models\Central\PaymentMethodSetting;

/**
 * Punto único de verdad sobre qué métodos de cobro están disponibles:
 * plataforma manda (apagado global = no existe para nadie) y el override
 * por hotel refina. Sin fila = habilitado. 'cash' es la excepción al "en
 * línea": aquí solo vive el PERMISO de plataforma para ofrecer "pagar en
 * el hotel" en los wizards; que un hotel lo ofrezca de verdad es opt-in
 * suyo (ReservationPolicy::cashPaymentEnabled). El registro contable de
 * efectivo/tarjeta en mostrador sigue sin pasar por aquí.
 */
class PaymentMethodGate
{
    public const METHODS = [
        'transfer' => 'Transferencia bancaria',
        'stripe' => 'Stripe',
        'mercadopago' => 'Mercado Pago',
        'paypal' => 'PayPal',
        'cash' => 'Pago en el hotel (efectivo)',
    ];

    /**
     * Interruptores ya leídos por esta instancia, por ámbito. Preguntar
     * método por método costaba dos consultas por método (diez para armar
     * el mapa completo) y esto se consulta en cada página que ofrece
     * cobros; son pocas filas, se traen de una.
     *
     * @var array<string, array<string, bool>>
     */
    protected array $switchCache = [];

    /**
     * @return array<string, bool>
     */
    protected function switches(?string $tenantId): array
    {
        $scope = $tenantId ?? '__plataforma__';

        return $this->switchCache[$scope] ??= PaymentMethodSetting::query()
            ->when(
                $tenantId === null,
                fn ($query) => $query->whereNull('tenant_id'),
                fn ($query) => $query->where('tenant_id', $tenantId),
            )
            ->pluck('enabled', 'method')
            ->map(fn ($enabled) => (bool) $enabled)
            ->all();
    }

    public function platformEnabled(string $method): bool
    {
        return $this->switches(null)[$method] ?? true;
    }

    public function enabledFor(string $tenantId, string $method): bool
    {
        if (! $this->platformEnabled($method)) {
            return false;
        }

        return $this->switches($tenantId)[$method] ?? true;
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

    /**
     * Pasarela con la que este hotel PUEDE cobrar ahora mismo: conectada,
     * activa y con su método permitido por plataforma y por el hotel.
     *
     * Es la misma pregunta que responde el botón "Generar link de pago" antes
     * de mostrarse y el endpoint antes de emitir: si vive en dos lugares, la
     * UI ofrece cobros que el backend rechaza.
     */
    public function activeGatewayLink(string $tenantId): ?PaymentGatewayLink
    {
        $enabled = $this->methodsFor($tenantId);

        $providers = array_keys(array_filter([
            'stripe' => $enabled['stripe'],
            'mercadopago' => $enabled['mercadopago'],
            'paypal' => $enabled['paypal'],
        ]));

        if ($providers === []) {
            return null;
        }

        return PaymentGatewayLink::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->whereIn('provider', $providers)
            ->orderBy('id')
            ->first();
    }

    /** Fija el interruptor global (tenant_id null) o el de un hotel. */
    public function set(?string $tenantId, string $method, bool $enabled): void
    {
        PaymentMethodSetting::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'method' => $method],
            ['enabled' => $enabled],
        );

        // Lo memorizado quedó viejo en cuanto alguien mueve un interruptor.
        $this->switchCache = [];
    }
}
