<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\PaymentRequest;
use App\Models\Reservation;
use App\Models\User;
use App\Services\CouponService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pone o quita un cupón en una reserva que YA existe.
 *
 * Hasta ahora el cupón solo entraba al crear la reserva (wizard, bot o el
 * formulario de mostrador): si el huésped no lo escribió, o lo escribió mal,
 * el descuento prometido no había forma de aplicarlo — había que cancelar y
 * volver a reservar. Caso real cabañas 2026-09-12: reserva RES-2026-1714, el
 * 30% de PACHEPACHE anunciado en un video, y nadie en el panel podía dárselo.
 *
 * El total se rearma siempre desde el total SIN descuento (total_amount +
 * discount_amount), así que cambiar de cupón dos veces no encoge la cuenta de
 * a poquito. El uso del cupón se cuenta o se devuelve según corresponda.
 */
class ApplyReservationCoupon
{
    public function __construct(protected CouponService $coupons) {}

    /**
     * @param  ?string  $code  null o vacío = retirar el cupón que traiga.
     *
     * @throws InvalidArgumentException
     */
    public function handle(Reservation $reservation, ?string $code, ?User $user = null): Reservation
    {
        return DB::transaction(function () use ($reservation, $code, $user) {
            $reservation = Reservation::query()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Una reserva cerrada (cancelada, no llegó o ya completada) no
            // cambia de precio: su dinero pertenece a un corte que ya pasó.
            if (! in_array($reservation->status, [
                ReservationStatus::Pending,
                ReservationStatus::Confirmed,
                ReservationStatus::CheckedIn,
            ], true)) {
                throw new InvalidArgumentException(
                    'Esta reserva ya está cerrada ('.$reservation->status->label().'): su descuento no se puede cambiar.',
                );
            }

            // Punto de partida: lo que costaría sin ningún cupón. Nunca se
            // descuenta sobre un total ya descontado.
            $gross = round((float) $reservation->total_amount + (float) $reservation->discount_amount, 2);
            $previous = $reservation->coupon_code;

            $coupon = $this->coupons->resolve(
                $code,
                $reservation->guest,
                $reservation->starts_at,
                $reservation->ratePlan?->unitsFor($reservation->starts_at, $reservation->ends_at),
                $reservation->room_type_id,
                $reservation->ends_at,
            );

            if ($coupon !== null && $previous !== null
                && \App\Models\Coupon::keyOf($previous) === \App\Models\Coupon::keyOf($coupon->code)) {
                throw new InvalidArgumentException("Esta reserva ya trae el cupón {$previous}.");
            }

            if ($coupon === null && $previous === null) {
                throw new InvalidArgumentException('Esta reserva no trae ningún cupón que quitar.');
            }

            // El cupón que se va devuelve su uso antes de que el nuevo lo
            // tome: si no, cambiar de cupón dejaría gastado el anterior.
            if ($previous !== null) {
                $this->coupons->release($reservation);
            }

            $discount = $coupon?->discountFor($gross) ?? 0.0;
            $total = round(max(0, $gross - $discount), 2);

            $reservation->forceFill([
                'coupon_code' => $coupon?->code,
                'discount_amount' => $discount,
                'total_amount' => $total,
                // El anticipo % se recalcula sobre lo que de verdad se va a
                // cobrar (igual que al crear la reserva); el monto fijo se
                // topa al total para no pedir más de lo que cuesta.
                'deposit_amount' => ($reservation->ratePlan
                    ? app(\App\Services\ReservationPolicy::class)->depositFor($reservation->ratePlan, $total, $reservation->starts_at, $reservation->created_at)
                    : null)
                    ?? round(min((float) $reservation->deposit_amount, $total), 2),
            ])->save();

            // Ya salió de Pendiente: el uso se cuenta aquí mismo, porque el
            // canje automático (TransitionReservation) no volverá a pasar.
            if ($coupon !== null && $reservation->status !== ReservationStatus::Pending) {
                $this->coupons->redeem($reservation);
            }

            $entry = activity('coupon')->performedOn($reservation)->withProperties([
                'code' => $coupon?->code ?? $previous,
                'discount' => $discount,
            ]);

            if ($user !== null) {
                $entry->causedBy($user);
            }

            $entry->log($coupon !== null
                ? sprintf('Cupón %s aplicado a mano: descuento $%s', $coupon->code, number_format($discount, 2))
                : sprintf('Cupón %s retirado a mano de la reserva', $previous));

            // El total cambió: se re-deriva el estado de pago y se cancela
            // cualquier cobro vivo, que pedía un monto que ya no corresponde
            // (spec-pagos §6.4). El siguiente cobro saldrá con el correcto.
            $reservation->syncPaymentStatus();

            $reservation->paymentRequests()
                ->where('status', PaymentRequest::STATUS_PENDING)
                ->update([
                    'status' => PaymentRequest::STATUS_CANCELED,
                    'updated_at' => now(),
                ]);

            return $reservation;
        });
    }
}
