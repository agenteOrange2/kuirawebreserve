<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\PaymentGatewayLink;
use App\Services\Payments\Gateways;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Conexión self-service de pasarelas de pago desde /ajustes (spec-pagos
 * §9.1): el hotel pega SUS llaves (Stripe / Mercado Pago) — el dinero va
 * directo a su cuenta. Credenciales cifradas en la tabla central; el
 * webhook del proveedor se registra con la URL por token que mostramos.
 */
class PaymentGatewayController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(PaymentGatewayLink::PROVIDERS))],
            'mode' => ['required', Rule::in(['test', 'live'])],
            'public_key' => ['nullable', 'string', 'max:255'],
            'secret_key' => ['required', 'string', 'max:255'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
        ]);

        if (PaymentGatewayLink::query()->where('tenant_id', tenant('id'))->where('provider', $data['provider'])->exists()) {
            return response()->json(['message' => 'Esa pasarela ya está conectada; edítala en su tarjeta.'], 422);
        }

        if (! app(\App\Services\Payments\PaymentMethodGate::class)->enabledFor((string) tenant('id'), $data['provider'])) {
            return response()->json(['message' => 'La plataforma no tiene habilitada esa pasarela para este hotel.'], 422);
        }

        // Límite del plan (spec-pagos §12): pasarelas conectadas simultáneas.
        $max = tenant()->planLimit('max_gateways');
        if ($max !== null && PaymentGatewayLink::query()->where('tenant_id', tenant('id'))->count() >= $max) {
            return response()->json([
                'message' => $max === 0
                    ? 'Tu plan no incluye pasarelas de pago en línea; puedes cobrar por transferencia o subir de plan.'
                    : "Límite del plan alcanzado: máximo {$max} pasarela(s) conectada(s).",
            ], 422);
        }

        $link = PaymentGatewayLink::create([
            ...$data,
            'tenant_id' => tenant('id'),
            'webhook_token' => PaymentGatewayLink::generateToken(),
            'active' => true,
        ]);

        return response()->json([
            ...$this->serialize($link),
            'test' => Gateways::for($link->provider)->testCredentials($link),
        ], 201);
    }

    public function update(Request $request, int $linkId): JsonResponse
    {
        $link = $this->ownLink($linkId);

        $data = $request->validate([
            'mode' => ['sometimes', Rule::in(['test', 'live'])],
            'public_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'secret_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);

        // Campo de llave vacío = conservar la actual.
        foreach (['secret_key', 'webhook_secret'] as $secret) {
            if (array_key_exists($secret, $data) && ! $data[$secret]) {
                unset($data[$secret]);
            }
        }

        $link->update($data);

        // Desactivar la pasarela mata sus cobros vivos (spec-pagos §6.5):
        // un link no puede cobrar por una vía que el hotel apagó.
        if (($data['active'] ?? true) === false) {
            $canceled = \App\Models\PaymentRequest::query()
                ->where('method', \App\Models\PaymentRequest::METHOD_GATEWAY)
                ->where('provider', $link->provider)
                ->where('status', \App\Models\PaymentRequest::STATUS_PENDING)
                ->update(['status' => \App\Models\PaymentRequest::STATUS_CANCELED, 'updated_at' => now()]);

            return response()->json([...$this->serialize($link), 'canceled_requests' => $canceled]);
        }

        return response()->json($this->serialize($link));
    }

    public function destroy(int $linkId): JsonResponse
    {
        $link = $this->ownLink($linkId);

        \App\Models\PaymentRequest::query()
            ->where('method', \App\Models\PaymentRequest::METHOD_GATEWAY)
            ->where('provider', $link->provider)
            ->where('status', \App\Models\PaymentRequest::STATUS_PENDING)
            ->update(['status' => \App\Models\PaymentRequest::STATUS_CANCELED, 'updated_at' => now()]);

        $link->delete();

        return response()->json(status: 204);
    }

    /** Prueba de credenciales contra la API del proveedor. */
    public function test(int $linkId): JsonResponse
    {
        $link = $this->ownLink($linkId);

        return response()->json([
            'test' => Gateways::for($link->provider)->testCredentials($link),
            'webhook_url' => $link->webhookUrl(),
            'last_event_at' => $link->last_event_at?->diffForHumans(),
        ]);
    }

    protected function ownLink(int $linkId): PaymentGatewayLink
    {
        return PaymentGatewayLink::query()
            ->where('tenant_id', tenant('id'))
            ->findOrFail($linkId);
    }

    /**
     * @return array<string, mixed>
     */
    public static function serialize(PaymentGatewayLink $link): array
    {
        return [
            'id' => $link->id,
            'provider' => $link->provider,
            'provider_label' => $link->providerLabel(),
            'mode' => $link->mode,
            'public_key' => $link->public_key,
            'masked_secret' => $link->maskedSecret(),
            'has_webhook_secret' => (bool) $link->webhook_secret,
            'webhook_url' => $link->webhookUrl(),
            'active' => $link->active,
            'last_event_at' => $link->last_event_at?->diffForHumans(),
        ];
    }
}
