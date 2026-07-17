<?php

namespace App\Services\Payments;

use App\Models\Central\PaymentGatewayLink;
use App\Models\PaymentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * PayPal vía Orders v2 (spec-pagos §8): útil para huéspedes extranjeros. Se
 * autentica con OAuth client_credentials (public_key = client id, secret_key
 * = secret). El comprador aprueba en PayPal y vuelve a nuestra return URL con
 * ?token={orderId}; ahí capturamos server-to-server (PaymentReturnController).
 * El webhook PAYMENT.CAPTURE.COMPLETED es respaldo idempotente: re-consulta
 * la API (como Mercado Pago) — un webhook forjado no puede fingir un COMPLETED.
 */
class PayPalGateway implements PaymentGateway
{
    public function createCheckout(PaymentRequest $request, PaymentGatewayLink $link): array
    {
        $token = $this->token($link);
        $returnUrl = route('tenant.payment.return', $request->uuid);

        $response = Http::withToken($token)
            ->post($this->base($link).'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'custom_id' => $request->uuid,
                    'description' => "{$request->subjectLabel()} — {$request->conceptLabel()}",
                    'amount' => [
                        'currency_code' => strtoupper($request->currency),
                        'value' => number_format((float) $request->amount, 2, '.', ''),
                    ],
                ]],
                'application_context' => [
                    'return_url' => $returnUrl,
                    'cancel_url' => $returnUrl,
                    'user_action' => 'PAY_NOW',
                    'shipping_preference' => 'NO_SHIPPING',
                ],
            ]);

        $approve = collect($response->json('links') ?? [])->firstWhere('rel', 'approve')['href'] ?? null;

        if (! $response->successful() || ! $approve) {
            throw new RuntimeException('PayPal rechazó el checkout: '.($response->json('message') ?? $response->status()));
        }

        return [
            'url' => (string) $approve,
            'gateway_ref' => (string) $response->json('id'),
        ];
    }

    /**
     * Captura una orden aprobada (al volver el comprador). Devuelve el evento
     * normalizado si el pago quedó COMPLETED, o null si aún no.
     *
     * @return array{event_id: string, uuid: ?string, ref: ?string, fee: ?float}|null
     */
    public function capture(PaymentGatewayLink $link, string $orderId): ?array
    {
        $token = $this->token($link);

        $response = Http::withToken($token)
            ->withBody('{}', 'application/json')
            ->post($this->base($link)."/v2/checkout/orders/{$orderId}/capture");

        if (! $response->successful() || $response->json('status') !== 'COMPLETED') {
            return null;
        }

        $capture = $response->json('purchase_units.0.payments.captures.0') ?? [];

        return [
            'event_id' => 'pp-'.($capture['id'] ?? $orderId).'-completed',
            'uuid' => $response->json('purchase_units.0.custom_id'),
            'ref' => $capture['id'] ?? $orderId,
            'fee' => isset($capture['seller_receivable_breakdown']['paypal_fee']['value'])
                ? round((float) $capture['seller_receivable_breakdown']['paypal_fee']['value'], 2)
                : null,
        ];
    }

    public function parseWebhook(Request $request, PaymentGatewayLink $link): ?array
    {
        $type = (string) $request->input('event_type');
        $resource = $request->input('resource', []);
        $ignored = ['event_id' => null, 'uuid' => null, 'gateway_ref' => null, 'status' => 'ignored', 'ref' => null, 'fee' => null];

        if ($type !== 'PAYMENT.CAPTURE.COMPLETED') {
            return $ignored;
        }

        $captureId = $resource['id'] ?? null;
        if (! $captureId) {
            return $ignored;
        }

        // Fuente de verdad: la API, no el payload del webhook (auth real).
        $capture = Http::withToken($this->token($link))
            ->get($this->base($link)."/v2/payments/captures/{$captureId}");

        if (! $capture->successful() || $capture->json('status') !== 'COMPLETED') {
            return $ignored;
        }

        return [
            'event_id' => "pp-{$captureId}-completed",
            'uuid' => $capture->json('custom_id') ?? ($resource['custom_id'] ?? null),
            'gateway_ref' => null,
            'status' => 'paid',
            'ref' => $captureId,
            'fee' => isset($capture->json()['seller_receivable_breakdown']['paypal_fee']['value'])
                ? round((float) $capture->json()['seller_receivable_breakdown']['paypal_fee']['value'], 2)
                : null,
        ];
    }

    public function testCredentials(PaymentGatewayLink $link): array
    {
        return $this->token($link) !== null
            ? ['ok' => true, 'detail' => 'Credenciales válidas.']
            : ['ok' => false, 'detail' => 'PayPal no aceptó el client id / secret (revisa el modo prueba/producción).'];
    }

    public function refund(\App\Models\Payment $payment, PaymentGatewayLink $link, float $amount): array
    {
        $token = $this->token($link);

        if (! $token) {
            return ['ok' => false, 'ref' => null, 'detail' => 'PayPal no aceptó las credenciales.'];
        }

        // gateway_ref del pago = id de la captura (lo dejó la captura/webhook).
        $response = Http::withToken($token)
            ->post($this->base($link)."/v2/payments/captures/{$payment->gateway_ref}/refund", [
                'amount' => [
                    'value' => number_format($amount, 2, '.', ''),
                    'currency_code' => strtoupper(
                        PaymentRequest::whereKey($payment->payment_request_id)->value('currency') ?? 'MXN',
                    ),
                ],
            ]);

        return $response->successful()
            ? ['ok' => true, 'ref' => (string) $response->json('id'), 'detail' => 'Reembolso enviado a PayPal.']
            : ['ok' => false, 'ref' => null, 'detail' => $response->json('message') ?? "PayPal respondió {$response->status()}."];
    }

    protected function base(PaymentGatewayLink $link): string
    {
        return $link->mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    protected function token(PaymentGatewayLink $link): ?string
    {
        $response = Http::asForm()
            ->withBasicAuth((string) $link->public_key, (string) $link->secret_key)
            ->post($this->base($link).'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        return $response->successful() ? $response->json('access_token') : null;
    }
}
