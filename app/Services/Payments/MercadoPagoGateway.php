<?php

namespace App\Services\Payments;

use App\Models\Central\PaymentGatewayLink;
use App\Models\PaymentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mercado Pago vía Checkout Pro (spec-pagos §8). Particularidad clave: el
 * webhook solo trae el id del pago; el estado confiable se obtiene
 * RE-CONSULTANDO la API con el token del hotel — esa re-consulta es la
 * autenticación real del evento (un webhook forjado no puede marcar pagado
 * nada que Mercado Pago no confirme server-to-server).
 */
class MercadoPagoGateway implements PaymentGateway
{
    protected const BASE = 'https://api.mercadopago.com';

    public function createCheckout(PaymentRequest $request, PaymentGatewayLink $link): array
    {
        $returnUrl = route('tenant.payment.return', $request->uuid);

        $response = Http::withToken($link->secret_key)
            ->post(self::BASE.'/checkout/preferences', [
                'external_reference' => $request->uuid,
                'items' => [[
                    'id' => $request->uuid,
                    'title' => "{$request->subjectLabel()} — {$request->conceptLabel()}",
                    'quantity' => 1,
                    'unit_price' => (float) $request->amount,
                    'currency_id' => strtoupper($request->currency),
                ]],
                'notification_url' => $link->webhookUrl(),
                'back_urls' => [
                    'success' => $returnUrl,
                    'pending' => $returnUrl,
                    'failure' => $returnUrl,
                ],
                'expires' => true,
                'expiration_date_to' => $request->expires_at?->toIso8601String(),
                'metadata' => ['payment_request' => $request->uuid],
            ]);

        if (! $response->successful() || ! $response->json('init_point')) {
            throw new RuntimeException('Mercado Pago rechazó el checkout: '.($response->json('message') ?? $response->status()));
        }

        return [
            'url' => (string) $response->json('init_point'),
            'gateway_ref' => (string) $response->json('id'),
        ];
    }

    public function parseWebhook(Request $request, PaymentGatewayLink $link): ?array
    {
        // IPN clásico (?topic=payment&id=...) o webhook nuevo (JSON type/data.id).
        $topic = (string) ($request->input('topic') ?? $request->input('type') ?? '');
        $paymentId = $request->input('data.id') ?? $request->input('id');

        if (! str_contains($topic, 'payment') || ! $paymentId) {
            return ['event_id' => null, 'uuid' => null, 'gateway_ref' => null, 'status' => 'ignored', 'ref' => null, 'fee' => null];
        }

        // Fuente de verdad: la API, no el payload del webhook.
        $payment = Http::withToken($link->secret_key)
            ->get(self::BASE.'/v1/payments/'.$paymentId);

        if (! $payment->successful()) {
            return ['event_id' => null, 'uuid' => null, 'gateway_ref' => null, 'status' => 'ignored', 'ref' => null, 'fee' => null];
        }

        $status = (string) $payment->json('status');
        $fee = collect($payment->json('fee_details') ?? [])->sum('amount');

        return [
            'event_id' => "mp-{$paymentId}-{$status}",
            'uuid' => $payment->json('external_reference'),
            'gateway_ref' => null, // la referencia nuestra es la preference; el pago llega por uuid
            'status' => $status === 'approved' ? 'paid' : 'ignored',
            'ref' => (string) $paymentId,
            'fee' => $fee > 0 ? round((float) $fee, 2) : null,
        ];
    }

    public function testCredentials(PaymentGatewayLink $link): array
    {
        $response = Http::withToken($link->secret_key)->get(self::BASE.'/users/me');

        return $response->successful()
            ? ['ok' => true, 'detail' => 'Credenciales válidas ('.($response->json('nickname') ?? 'cuenta verificada').').']
            : ['ok' => false, 'detail' => $response->json('message') ?? "Mercado Pago respondió {$response->status()}."];
    }

    public function refund(\App\Models\Payment $payment, PaymentGatewayLink $link, float $amount): array
    {
        // gateway_ref del pago = id del payment de MP (lo dejó el webhook).
        // El monto parcial va en el body; omitirlo reembolsaría todo.
        $response = Http::withToken($link->secret_key)
            ->withHeaders(['X-Idempotency-Key' => "refund-{$payment->id}-".(int) round($amount * 100)])
            ->post(self::BASE."/v1/payments/{$payment->gateway_ref}/refunds", [
                'amount' => round($amount, 2),
            ]);

        return $response->successful()
            ? ['ok' => true, 'ref' => (string) $response->json('id'), 'detail' => 'Reembolso enviado a Mercado Pago.']
            : ['ok' => false, 'ref' => null, 'detail' => $response->json('message') ?? "Mercado Pago respondió {$response->status()}."];
    }
}
