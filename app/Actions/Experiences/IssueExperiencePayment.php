<?php

namespace App\Actions\Experiences;

use App\Models\Central\PaymentGatewayLink;
use App\Models\ExperienceBooking;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\User;
use App\Services\Payments\Gateways;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Emite el cobro de una reserva de experiencia — el mismo motor de
 * payment_requests de las habitaciones (pasarela con checkout hospedado o
 * transferencia con verificación humana), apuntando a experience_booking.
 * Siempre por el TOTAL: las experiencias no manejan anticipos parciales.
 * Idempotente: una solicitud viva por reserva; emitir con otro método
 * cancela la anterior.
 */
class IssueExperiencePayment
{
    public function handle(
        ExperienceBooking $booking,
        string $method = PaymentRequest::METHOD_TRANSFER,
        ?User $user = null,
        ?PaymentGatewayLink $link = null,
    ): PaymentRequest {
        if ($link !== null) {
            $method = PaymentRequest::METHOD_GATEWAY;
        }

        $request = DB::transaction(function () use ($booking, $method, $user, $link) {
            $booking = ExperienceBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if (! in_array($booking->status, [ExperienceBooking::STATUS_PENDING, ExperienceBooking::STATUS_CONFIRMED], true)) {
                throw new InvalidArgumentException("La reserva está \"{$booking->statusLabel()}\"; no se le pueden emitir cobros.");
            }

            if ($booking->paymentRequests()->where('status', PaymentRequest::STATUS_PAID)->exists()) {
                throw new InvalidArgumentException('Esta reserva ya está pagada.');
            }

            $existing = $booking->paymentRequests()->active()->latest('id')->first();

            if ($existing && $existing->method === $method) {
                return $existing;
            }

            // Cambió el método: la solicitud anterior ya no debe poder cobrar.
            $booking->paymentRequests()
                ->where('status', PaymentRequest::STATUS_PENDING)
                ->update(['status' => PaymentRequest::STATUS_CANCELED, 'updated_at' => now()]);

            return PaymentRequest::create([
                'experience_booking_id' => $booking->id,
                'concept' => PaymentRequest::CONCEPT_FULL,
                'amount' => $booking->total,
                'currency' => Property::query()->first()?->settings['currency'] ?? 'MXN',
                'method' => $method,
                'provider' => $link?->provider,
                'mode' => $link?->mode ?? 'live',
                'status' => PaymentRequest::STATUS_PENDING,
                'expires_at' => $method === PaymentRequest::METHOD_TRANSFER
                    ? now()->addMinutes(app(\App\Services\ReservationPolicy::class)->transferMinutes())
                    : now()->addMinutes((int) config('payments.gateway_minutes', 120)),
                'requested_by' => $user?->id,
            ]);
        });

        // El checkout se crea FUERA de la transacción (llamada HTTP externa)
        // — mismo patrón que IssuePaymentRequest.
        if ($link !== null && $request->checkout_url === null) {
            try {
                $checkout = Gateways::for($link->provider)->createCheckout($request, $link);
                $request->update([
                    'checkout_url' => $checkout['url'],
                    'gateway_ref' => $checkout['gateway_ref'],
                ]);
            } catch (Throwable $e) {
                report($e);
                $request->update(['status' => PaymentRequest::STATUS_CANCELED]);

                throw new \RuntimeException(
                    "No se pudo generar el link de pago con {$link->providerLabel()}; revisa la conexión de la pasarela.",
                );
            }
        }

        return $request;
    }
}
