<?php

namespace App\Services\Payments;

use App\Models\Central\PaymentGatewayLink;
use App\Models\PaymentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Stripe vía Checkout Sessions (spec-pagos §8): monto fijo calculado en el
 * servidor, checkout hospedado por Stripe, confirmación por webhook firmado
 * (Stripe-Signature). Sin SDK: la API REST con form-encoding basta.
 */
class StripeGateway implements PaymentGateway
{
    protected const BASE = 'https://api.stripe.com';

    public function createCheckout(PaymentRequest $request, PaymentGatewayLink $link): array
    {
        $returnUrl = route('tenant.payment.return', $request->uuid);

        // Stripe exige expiración entre 30 min y 24 h.
        $expiresAt = min(
            max($request->expires_at?->timestamp ?? now()->addHours(2)->timestamp, now()->addMinutes(31)->timestamp),
            now()->addHours(24)->timestamp,
        );

        $payload = [
            'mode' => 'payment',
            'client_reference_id' => $request->uuid,
            'line_items[0][price_data][currency]' => strtolower($request->currency),
            'line_items[0][price_data][unit_amount]' => (int) round(((float) $request->amount) * 100),
            'line_items[0][price_data][product_data][name]' => "{$request->subjectLabel()} — {$request->conceptLabel()}",
            'line_items[0][quantity]' => 1,
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
            'expires_at' => $expiresAt,
            'metadata[payment_request]' => $request->uuid,
        ];

        // Sin payment_method_types Stripe resuelve los métodos según la
        // config del dashboard del hotel (dinámicos: tarjeta, OXXO...).
        $response = $this->postCheckout($link, $payload);

        // Cuenta sin métodos activados compatibles con la moneda (típico en
        // cuentas recién dadas de alta que nunca tocaron Settings → Payment
        // methods): la resolución automática regresa vacío y Stripe rechaza.
        // Reintento pidiendo tarjeta explícita — el mínimo universal — para
        // que el hotel cobre hoy mismo; cuando active más métodos en su
        // dashboard, el primer intento (automático) los ofrecerá solo.
        if (! $response->successful() && str_contains((string) $response->json('error.message'), 'No valid payment method types')) {
            $response = $this->postCheckout($link, [...$payload, 'payment_method_types[0]' => 'card']);
        }

        if (! $response->successful() || ! $response->json('url')) {
            throw new RuntimeException('Stripe rechazó el checkout: '.($response->json('error.message') ?? $response->status()));
        }

        return [
            'url' => (string) $response->json('url'),
            'gateway_ref' => (string) $response->json('id'),
        ];
    }

    /** @param  array<string, mixed>  $payload */
    protected function postCheckout(PaymentGatewayLink $link, array $payload): \Illuminate\Http\Client\Response
    {
        return Http::withToken($link->secret_key)
            ->asForm()
            ->post(self::BASE.'/v1/checkout/sessions', $payload);
    }

    public function parseWebhook(Request $request, PaymentGatewayLink $link): ?array
    {
        if (! $this->validSignature($request, (string) $link->webhook_secret)) {
            return null;
        }

        $event = json_decode($request->getContent(), true) ?: [];
        $type = (string) ($event['type'] ?? '');
        $session = $event['data']['object'] ?? [];

        $base = [
            'event_id' => $event['id'] ?? null,
            'uuid' => $session['client_reference_id'] ?? ($session['metadata']['payment_request'] ?? null),
            'gateway_ref' => $session['id'] ?? null,
            'ref' => $session['payment_intent'] ?? null,
            'fee' => null, // el fee real viene del balance transaction; conciliación posterior
        ];

        return match (true) {
            // completed con payment_status unpaid = método asíncrono (OXXO):
            // el dinero llega después vía async_payment_succeeded.
            $type === 'checkout.session.completed' && ($session['payment_status'] ?? '') === 'paid',
            $type === 'checkout.session.async_payment_succeeded' => [...$base, 'status' => 'paid'],
            $type === 'checkout.session.expired' => [...$base, 'status' => 'expired'],
            default => [...$base, 'status' => 'ignored'],
        };
    }

    public function testCredentials(PaymentGatewayLink $link): array
    {
        $response = Http::withToken($link->secret_key)->get(self::BASE.'/v1/balance');

        return $response->successful()
            ? ['ok' => true, 'detail' => 'Credenciales válidas.']
            : ['ok' => false, 'detail' => $response->json('error.message') ?? "Stripe respondió {$response->status()}."];
    }

    public function refund(\App\Models\Payment $payment, PaymentGatewayLink $link, float $amount): array
    {
        // gateway_ref del pago = payment_intent (lo dejó el webhook).
        $response = Http::withToken($link->secret_key)
            ->asForm()
            ->post(self::BASE.'/v1/refunds', [
                'payment_intent' => $payment->gateway_ref,
                'amount' => (int) round($amount * 100),
            ]);

        return $response->successful()
            ? ['ok' => true, 'ref' => (string) $response->json('id'), 'detail' => 'Reembolso enviado a Stripe.']
            : ['ok' => false, 'ref' => null, 'detail' => $response->json('error.message') ?? "Stripe respondió {$response->status()}."];
    }

    /**
     * Firma Stripe: header "t=...,v1=..." con HMAC-SHA256 de "{t}.{payload}"
     * usando el signing secret del endpoint (sobre el body CRUDO).
     */
    protected function validSignature(Request $request, string $secret): bool
    {
        if ($secret === '') {
            return false; // sin signing secret no hay forma de autenticar
        }

        $header = (string) $request->header('Stripe-Signature', '');
        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$key][] = $value;
        }

        $timestamp = $parts['t'][0] ?? null;
        if (! $timestamp || abs(now()->timestamp - (int) $timestamp) > 300) {
            return false; // tolerancia de 5 min contra replay
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        foreach ($parts['v1'] ?? [] as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
