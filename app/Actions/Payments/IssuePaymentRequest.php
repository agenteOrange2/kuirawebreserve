<?php

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Central\PaymentGatewayLink;
use App\Models\PaymentRequest;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Payments\Gateways;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Emite una solicitud de cobro para una reserva (spec-pagos §4.1): resuelve
 * el concepto pendiente (anticipo → saldo), calcula el monto SIEMPRE en el
 * servidor y extiende el hold mientras la solicitud viva (§6.1). Idempotente:
 * si ya hay una solicitud viva por el mismo dinero, la devuelve; si el monto
 * cambió (§6.4), la cancela y emite una nueva. Con pasarela ($link) además
 * crea el checkout hospedado y guarda su URL.
 */
class IssuePaymentRequest
{
    public function handle(
        Reservation $reservation,
        string $method = PaymentRequest::METHOD_TRANSFER,
        ?User $user = null,
        ?PaymentGatewayLink $link = null,
    ): PaymentRequest {
        if ($link !== null) {
            $method = PaymentRequest::METHOD_GATEWAY;
        }

        $request = DB::transaction(function () use ($reservation, $method, $user, $link) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if (! in_array($reservation->status, [ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::CheckedIn], true)) {
                throw new InvalidArgumentException(
                    "La reserva está \"{$reservation->status->label()}\"; no se le pueden emitir cobros.",
                );
            }

            [$concept, $amount] = $this->resolveConcept($reservation);

            if ($amount <= 0) {
                throw new InvalidArgumentException('La reserva no tiene saldo pendiente.');
            }

            // Idempotencia: una sola solicitud viva por el mismo dinero.
            $existing = $reservation->paymentRequests()->active()->latest('id')->first();

            if ($existing && $existing->concept === $concept && (float) $existing->amount === $amount && $existing->method === $method) {
                return $existing;
            }

            // El monto o el concepto cambiaron: la solicitud anterior no puede
            // cobrar un dinero que ya no corresponde (spec-pagos §6.4).
            $reservation->paymentRequests()
                ->where('status', PaymentRequest::STATUS_PENDING)
                ->update(['status' => PaymentRequest::STATUS_CANCELED, 'updated_at' => now()]);

            // Vigencia de transferencia configurable por hotel (Métodos de
            // pago); la de pasarela sigue en config: la limita el proveedor.
            $expiresAt = $method === PaymentRequest::METHOD_TRANSFER
                ? now()->addMinutes(app(\App\Services\ReservationPolicy::class)->transferMinutes())
                : now()->addMinutes((int) config('payments.gateway_minutes', 120));

            // El saldo por transferencia vive hasta su fecha límite (no tiene
            // sentido que el cobro muera antes de que venza el plazo). Los
            // checkouts de pasarela no: Stripe topa la sesión a 24 h — si
            // expira, el scheduler o el bot emiten uno fresco (spec §7.2).
            if (
                $concept === PaymentRequest::CONCEPT_BALANCE
                && $method === PaymentRequest::METHOD_TRANSFER
                && $reservation->payment_due_at?->gt($expiresAt)
            ) {
                $expiresAt = $reservation->payment_due_at;
            }

            $request = $reservation->paymentRequests()->create([
                'concept' => $concept,
                'amount' => $amount,
                'currency' => \App\Models\Property::query()->first()?->settings['currency'] ?? 'MXN',
                'method' => $method,
                'provider' => $link?->provider,
                'mode' => $link?->mode ?? 'live',
                'status' => PaymentRequest::STATUS_PENDING,
                'expires_at' => $expiresAt,
                'requested_by' => $user?->id,
            ]);

            // Un hold que muere antes que su solicitud genera pagos huérfanos:
            // se extiende hasta donde la solicitud viva (spec-pagos §6.1).
            if (
                $reservation->status === ReservationStatus::Pending
                && $reservation->hold_expires_at !== null
                && $reservation->hold_expires_at->lt($expiresAt)
            ) {
                $reservation->update(['hold_expires_at' => $expiresAt]);
            }

            return $request;
        });

        // El checkout se crea FUERA de la transacción (llamada HTTP externa).
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

                // RuntimeException ≠ error de negocio: el caller puede caer
                // a transferencia (el saldo sí existe, la pasarela falló).
                throw new \RuntimeException(
                    "No se pudo generar el link de pago con {$link->providerLabel()}; revisa la conexión de la pasarela.",
                );
            }
        }

        return $request;
    }

    /**
     * Qué toca cobrar: pago total (tarifas con anticipo del 100% o sin
     * anticipo definido), el anticipo si aún no se cubre, o el saldo.
     *
     * @return array{0: string, 1: float}
     */
    protected function resolveConcept(Reservation $reservation): array
    {
        $pending = $reservation->pendingBalance();
        $deposit = (float) $reservation->deposit_amount;
        $paid = $reservation->paidTotal();

        if ($deposit <= 0 || $deposit >= (float) $reservation->total_amount) {
            return [PaymentRequest::CONCEPT_FULL, $pending];
        }

        if ($reservation->payment_status === PaymentStatus::Unpaid && $paid < $deposit) {
            return [PaymentRequest::CONCEPT_DEPOSIT, round($deposit - $paid, 2)];
        }

        return [PaymentRequest::CONCEPT_BALANCE, $pending];
    }
}
