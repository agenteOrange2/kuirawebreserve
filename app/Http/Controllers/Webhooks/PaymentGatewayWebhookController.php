<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Payments\RegisterGatewayPayment;
use App\Http\Controllers\Controller;
use App\Models\Central\PaymentGatewayLink;
use App\Models\PaymentRequest;
use App\Models\Tenant;
use App\Services\Payments\Gateways;
use App\Services\Payments\PaymentGuestNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Webhook central de pasarelas (spec-pagos §3.4): el token de la URL enruta
 * al tenant, la firma del proveedor (o la re-consulta server-to-server en
 * Mercado Pago) autentica el evento, y gateway_events lo hace idempotente.
 * Aquí es donde "pagado" se vuelve verdad: registra el pago, confirma la
 * reserva si procede y avisa al huésped — el mismo camino que la
 * verificación humana de transferencias.
 */
class PaymentGatewayWebhookController extends Controller
{
    public function receive(Request $request, string $token): JsonResponse
    {
        $link = PaymentGatewayLink::query()
            ->where('webhook_token', $token)
            ->where('active', true)
            ->first();

        if (! $link) {
            return response()->json(['message' => 'Unknown token'], 404);
        }

        // Latido del webhook (diagnóstico en el panel, como canales).
        $link->forceFill(['last_event_at' => now()])->saveQuietly();

        $event = Gateways::for($link->provider)->parseWebhook($request, $link);

        if ($event === null) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        if ($event['status'] === 'ignored' || (! $event['uuid'] && ! $event['gateway_ref'])) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        $tenant = Tenant::find($link->tenant_id);

        if (! $tenant) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        $tenant->run(function () use ($event, $link) {
            // Idempotencia: los proveedores reintentan; el mismo evento
            // procesado una vez no vuelve a tocar dinero.
            if ($event['event_id'] !== null) {
                $fresh = DB::table('gateway_events')->insertOrIgnore([
                    'provider' => $link->provider,
                    'event_id' => $event['event_id'],
                    'payload' => json_encode($event),
                    'processed_at' => now(),
                ]);

                if (! $fresh) {
                    return;
                }
            }

            $paymentRequest = PaymentRequest::query()
                ->when($event['uuid'], fn ($q) => $q->where('uuid', $event['uuid']))
                ->when(! $event['uuid'] && $event['gateway_ref'], fn ($q) => $q
                    ->where('provider', $link->provider)
                    ->where('gateway_ref', $event['gateway_ref']))
                ->first();

            if (! $paymentRequest) {
                return;
            }

            if ($event['event_id'] !== null) {
                DB::table('gateway_events')
                    ->where('provider', $link->provider)
                    ->where('event_id', $event['event_id'])
                    ->update(['payment_request_id' => $paymentRequest->id]);
            }

            if ($event['status'] === 'expired') {
                if ($paymentRequest->status === PaymentRequest::STATUS_PENDING) {
                    $paymentRequest->update(['status' => PaymentRequest::STATUS_EXPIRED]);
                }

                return;
            }

            // status paid: el dinero SIEMPRE se registra (§6.2); anomalías
            // (reserva cancelada, sobrepago) quedan marcadas y alertadas.
            app(RegisterGatewayPayment::class)->handle($paymentRequest, [
                'gateway' => $link->provider,
                'gateway_ref' => $event['ref'],
                'fee_amount' => $event['fee'],
            ]);

            app(PaymentGuestNotifier::class)->paymentReceived($paymentRequest->refresh());
        });

        return response()->json(['ok' => true]);
    }
}
