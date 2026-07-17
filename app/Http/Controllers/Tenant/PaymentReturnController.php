<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Payments\RegisterGatewayPayment;
use App\Http\Controllers\Controller;
use App\Models\Central\PaymentGatewayLink;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Services\Payments\PayPalGateway;
use App\Services\Payments\PaymentGuestNotifier;
use Illuminate\Http\Request as HttpRequest;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aterrizaje público tras el checkout de la pasarela (success/cancel URL,
 * spec-pagos §8): el huésped ve el estado real de su cobro — que declara el
 * webhook, no esta página. Pública como el webchat: sin login.
 */
class PaymentReturnController extends Controller
{
    public function __invoke(HttpRequest $httpRequest, string $uuid): Response
    {
        $request = PaymentRequest::query()
            ->with(['reservation:id,code,status,created_at', 'experienceBooking:id,experience_session_id,status,code,created_at'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        // PayPal usa flujo redirect: el comprador aprueba y vuelve con
        // ?token={orderId}; la captura ocurre aquí (spec-pagos §8). El webhook
        // PAYMENT.CAPTURE.COMPLETED es respaldo idempotente.
        if (
            $request->status === PaymentRequest::STATUS_PENDING
            && $request->provider === 'paypal'
            && $httpRequest->query('token')
        ) {
            $this->capturePayPal($request, (string) $httpRequest->query('token'));
            $request->refresh()->load(['reservation:id,code,status,created_at', 'experienceBooking:id,experience_session_id,status,code,created_at']);
        }

        return Inertia::render('tenant/payments/Return', [
            'hotel' => Property::query()->first()?->name ?? 'Hotel',
            'payment' => [
                'status' => $request->status,
                'status_label' => $request->statusLabel(),
                'concept' => $request->conceptLabel(),
                'amount_label' => $request->amountLabel(),
                'reservation_code' => $request->subjectCode(),
                // "Confirmada" del sujeto que sea: habitación o experiencia.
                'reservation_confirmed' => $request->reservation?->status === \App\Enums\ReservationStatus::Confirmed
                    || $request->experienceBooking?->status === \App\Models\ExperienceBooking::STATUS_CONFIRMED,
                'checkout_url' => $request->isPayable() ? $request->checkout_url : null,
            ],
        ]);
    }

    /**
     * Captura la orden de PayPal aprobada y, si quedó COMPLETED, registra el
     * pago por el mismo camino que el webhook (idempotente si además llega).
     */
    protected function capturePayPal(PaymentRequest $request, string $orderId): void
    {
        // Seguridad: el token debe ser la orden que creamos para este cobro.
        if ($request->gateway_ref !== null && $request->gateway_ref !== $orderId) {
            return;
        }

        $link = PaymentGatewayLink::query()
            ->where('tenant_id', (string) tenant('id'))
            ->where('provider', 'paypal')
            ->where('active', true)
            ->first();

        if (! $link) {
            return;
        }

        $event = app(PayPalGateway::class)->capture($link, $orderId);

        if (! $event) {
            return; // el comprador aún no aprobó, o PayPal no confirmó
        }

        // Dedupe con el webhook: la captura pudo confirmarse por ambas vías.
        $fresh = \Illuminate\Support\Facades\DB::table('gateway_events')->insertOrIgnore([
            'provider' => 'paypal',
            'event_id' => $event['event_id'],
            'payment_request_id' => $request->id,
            'payload' => json_encode($event),
            'processed_at' => now(),
        ]);

        if (! $fresh) {
            return;
        }

        app(RegisterGatewayPayment::class)->handle($request, [
            'gateway' => 'paypal',
            'gateway_ref' => $event['ref'],
            'fee_amount' => $event['fee'],
        ]);

        app(PaymentGuestNotifier::class)->paymentReceived($request->refresh());
    }
}
