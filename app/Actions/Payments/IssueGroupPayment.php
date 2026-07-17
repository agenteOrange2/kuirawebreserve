<?php

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Central\PaymentGatewayLink;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\User;
use App\Services\Payments\Gateways;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Cobro consolidado de un grupo: UN solo link/solicitud por todas las
 * habitaciones. El monto es la suma de lo que cada reserva cobraría por
 * su cuenta (su anticipo si lo tiene y no está cubierto; si no, su
 * pendiente) y el desglose por reserva queda congelado en meta — al
 * pagarse, RegisterGatewayPayment reparte pagos por habitación con esa
 * verdad, sin inventar contabilidad nueva.
 */
class IssueGroupPayment
{
    public function handle(
        ReservationGroup $group,
        string $method = PaymentRequest::METHOD_TRANSFER,
        ?User $user = null,
        ?PaymentGatewayLink $link = null,
    ): PaymentRequest {
        if ($link !== null) {
            $method = PaymentRequest::METHOD_GATEWAY;
        }

        $request = DB::transaction(function () use ($group, $method, $user) {
            $reservations = $group->reservations()
                ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed])
                ->lockForUpdate()
                ->get();

            if ($reservations->isEmpty()) {
                throw new InvalidArgumentException('El grupo no tiene reservas vivas que cobrar.');
            }

            $breakdown = [];

            foreach ($reservations as $reservation) {
                $charge = $this->chargeFor($reservation);

                if ($charge > 0) {
                    $breakdown[(string) $reservation->id] = $charge;
                }
            }

            // Experiencias colgadas del grupo: su pendiente viaja en el mismo
            // link. Reparto propio en meta (experience_breakdown) para que al
            // pagarse cada tour reciba su pago y se confirme — el reparto por
            // habitación no se entera.
            $experienceBookings = $group->liveExperienceBookings()->lockForUpdate()->get();
            $experienceBreakdown = [];

            foreach ($experienceBookings as $booking) {
                $charge = round((float) $booking->total - (float) $booking->payments()->sum('amount'), 2);

                if ($charge > 0) {
                    $experienceBreakdown[(string) $booking->id] = $charge;
                }
            }

            $amount = round(array_sum($breakdown) + array_sum($experienceBreakdown), 2);

            if ($amount <= 0) {
                throw new InvalidArgumentException('El grupo no tiene saldo pendiente.');
            }

            $existing = $group->paymentRequests()->active()->latest('id')->first();

            if ($existing && $existing->method === $method && (float) $existing->amount === $amount) {
                return $existing;
            }

            // Un cobro de grupo sustituye a los cobros individuales vivos de
            // sus reservas y sus experiencias Y a cualquier cobro de grupo
            // anterior: nunca dos vías abiertas por el mismo dinero
            // (spec-pagos §6.4).
            PaymentRequest::query()
                ->where('status', PaymentRequest::STATUS_PENDING)
                ->where(fn ($q) => $q
                    ->whereIn('reservation_id', $reservations->pluck('id'))
                    ->orWhereIn('experience_booking_id', $experienceBookings->pluck('id'))
                    ->orWhere('reservation_group_id', $group->id))
                ->update(['status' => PaymentRequest::STATUS_CANCELED, 'updated_at' => now()]);

            return PaymentRequest::create([
                'reservation_group_id' => $group->id,
                'concept' => PaymentRequest::CONCEPT_CUSTOM,
                'amount' => $amount,
                'currency' => Property::query()->first()?->settings['currency'] ?? 'MXN',
                'method' => $method,
                'provider' => null,
                'mode' => 'live',
                'status' => PaymentRequest::STATUS_PENDING,
                'expires_at' => $method === PaymentRequest::METHOD_TRANSFER
                    ? now()->addMinutes(app(\App\Services\ReservationPolicy::class)->transferMinutes())
                    : now()->addMinutes((int) config('payments.gateway_minutes', 120)),
                'requested_by' => $user?->id,
                'meta' => array_filter([
                    'breakdown' => $breakdown,
                    'experience_breakdown' => $experienceBreakdown,
                ]),
            ]);
        });

        if ($link !== null && $request->checkout_url === null) {
            $request->forceFill(['provider' => $link->provider, 'mode' => $link->mode])->save();

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

    /**
     * Lo que ESTA reserva cobraría por su cuenta: anticipo configurado y
     * aún no cubierto, o su pendiente total — misma regla que
     * IssuePaymentRequest::resolveConcept.
     */
    protected function chargeFor(Reservation $reservation): float
    {
        $pending = $reservation->pendingBalance();
        $deposit = (float) $reservation->deposit_amount;

        if ($deposit > 0 && $deposit < (float) $reservation->total_amount && $reservation->payment_status === PaymentStatus::Unpaid) {
            return round(min($deposit, $pending), 2);
        }

        return round($pending, 2);
    }
}
