<?php

namespace App\Actions\Reservations;

use App\Actions\Inventory\CreateOrder;
use App\Actions\Rooms\ChangeRoomStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\Exceptions\NoAvailabilityException;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Ciclo de vida de la reserva: confirmar, cancelar, check-in (crea la
 * estancia y ocupa la habitación) y check-out (libera a "sucia").
 */
class TransitionReservation
{
    public function __construct(
        protected AvailabilityService $availability,
        protected ChangeRoomStatus $changeRoomStatus,
        protected CreateOrder $createOrder,
    ) {}

    /**
     * @param  bool  $notifyGuest  false cuando quien confirma ya avisa por su
     *                             cuenta (el registro de pago manda su propio
     *                             "recibimos tu pago... está confirmada").
     *
     * @throws NoAvailabilityException
     */
    public function confirm(Reservation $reservation, ?User $user = null, bool $notifyGuest = true): Reservation
    {
        $this->assertStatus($reservation, [ReservationStatus::Pending]);

        $reservation = DB::transaction(function () use ($reservation, $user) {
            $room = Room::whereKey($reservation->room_id)->lockForUpdate()->firstOrFail();

            // Si el hold venció, alguien más pudo ganar la habitación.
            if (! $this->availability->isRoomAvailable($room, $reservation->starts_at, $reservation->ends_at, $reservation->id)) {
                throw NoAvailabilityException::forRoom($room->number);
            }

            $reservation->update([
                'status' => ReservationStatus::Confirmed,
                'hold_expires_at' => null,
            ]);

            // Los tours comprados como plus siguen la suerte de la reserva:
            // su dinero viaja en el total de ella.
            $this->syncLinkedExperiences($reservation, \App\Models\ExperienceBooking::STATUS_CONFIRMED);

            if ($reservation->starts_at->isToday() && $room->status->getMorphClass() === RoomStatus::Available->value) {
                $this->changeRoomStatus->handle($room, RoomStatus::Reserved->value, $user, [
                    'reservation_id' => $reservation->id,
                ]);
            }

            return $reservation;
        });

        // Fuera de la transacción: avisar es cortesía, no debe poder
        // revertir una confirmación ya hecha.
        if ($notifyGuest) {
            try {
                app(\App\Services\Payments\PaymentGuestNotifier::class)->reservationConfirmed($reservation);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $reservation;
    }

    public function cancel(Reservation $reservation, ?User $user = null, ReservationStatus $to = ReservationStatus::Cancelled, ?string $reason = null): Reservation
    {
        $this->assertStatus($reservation, [ReservationStatus::Pending, ReservationStatus::Confirmed]);

        return DB::transaction(function () use ($reservation, $user, $to, $reason) {
            $reservation->update([
                'status' => $to,
                'hold_expires_at' => null,
                'cancellation_reason' => $reason,
            ]);

            // Cancelar la reserva libera también el cupo de sus tours.
            $this->syncLinkedExperiences($reservation, \App\Models\ExperienceBooking::STATUS_CANCELLED);

            // Libera el semáforo si esta reserva lo tenía apartado.
            $room = $reservation->room;
            if ($room && $room->status->getMorphClass() === RoomStatus::Reserved->value) {
                $this->changeRoomStatus->handle($room, RoomStatus::Available->value, $user, [
                    'reservation_id' => $reservation->id,
                ]);
            }

            return $reservation;
        });
    }

    /**
     * @throws NoAvailabilityException
     */
    public function checkIn(Reservation $reservation, ?User $user = null): Stay
    {
        $this->assertStatus($reservation, [ReservationStatus::Pending, ReservationStatus::Confirmed]);

        return DB::transaction(function () use ($reservation, $user) {
            $room = Room::whereKey($reservation->room_id)->lockForUpdate()->firstOrFail();

            $roomState = $room->status->getMorphClass();
            if (! in_array($roomState, [RoomStatus::Available->value, RoomStatus::Reserved->value], true)) {
                throw NoAvailabilityException::forRoomState($room->number, $room->status->label());
            }

            $stay = Stay::create([
                'room_id' => $room->id,
                'reservation_id' => $reservation->id,
                'rate_plan_id' => $reservation->rate_plan_id,
                'guest_id' => $reservation->guest_id,
                'guest_name' => $reservation->guest_name,
                'num_people' => $reservation->num_people,
                'vehicle_plate' => $reservation->vehicle_plate,
                'vehicle_desc' => $reservation->vehicle_desc,
                'check_in_at' => now(),
                'planned_end_at' => $reservation->ends_at,
                'status' => Stay::STATUS_ACTIVE,
                'amount' => $reservation->total_amount,
                'channel' => $reservation->source_channel,
                'created_by' => $user?->id,
            ]);

            $reservation->update(['status' => ReservationStatus::CheckedIn, 'hold_expires_at' => null]);

            // Check-in directo desde pendiente: los tours ligados quedan firmes.
            $this->syncLinkedExperiences($reservation, \App\Models\ExperienceBooking::STATUS_CONFIRMED);

            $this->changeRoomStatus->handle($room, RoomStatus::Occupied->value, $user, [
                'reservation_id' => $reservation->id,
                'stay_id' => $stay->id,
            ]);

            // Extras del wizard (spec: /ajustes/wizard): recién AHORA se
            // materializan en una Order real — es el momento correcto para
            // descontar stock de verdad, porque el huésped ya llegó. Un
            // hold que hubiera expirado con productos elegidos nunca pasa
            // por aquí, así que nunca tocó inventario.
            if (! empty($reservation->products)) {
                $extrasOrder = $this->createOrder->handle([
                    'property_id' => $room->property_id,
                    'stay_id' => $stay->id,
                    'notes' => 'Extras elegidos al reservar en línea ('.$reservation->displayCode().')',
                    'lines' => collect($reservation->products)
                        ->map(fn (array $line) => ['product_id' => $line['product_id'], 'qty' => $line['qty']])
                        ->all(),
                ], $user);

                // CreateOrder la deja como cargo a habitación (payment_method
                // 'room'), pero su costo YA está adentro de
                // reservation.total_amount desde que se creó el hold — el
                // folio (Stay::folio()) lo cuenta vía lodging_pending. Sin
                // este sello, Stay::folio() la sumaría OTRA VEZ en
                // consumption_pending y el huésped pagaría sus extras dos
                // veces al hacer check-out.
                $extrasOrder->update(['settled_at' => now(), 'settled_by' => $user?->id]);
            }

            return $stay;
        });
    }

    /**
     * @param  array<string, mixed>  $context  Extra para room_status_logs
     *                                         (p. ej. ['auto' => true] del scheduler).
     */
    public function checkOut(Stay $stay, ?User $user = null, array $context = []): Stay
    {
        if ($stay->status !== Stay::STATUS_ACTIVE) {
            throw new InvalidArgumentException('La estancia ya fue cerrada.');
        }

        return DB::transaction(function () use ($stay, $user, $context) {
            $stay->update([
                'status' => Stay::STATUS_COMPLETED,
                'check_out_at' => now(),
            ]);

            $stay->reservation?->update(['status' => ReservationStatus::Completed]);

            // Solo mueve el semáforo si la habitación sigue ocupada: si
            // alguien ya lo movió a mano (sucia/limpieza/disponible), el
            // check-out cierra la estancia sin pelearse con ese estado.
            $room = $stay->room;
            if ($room && $room->status->getMorphClass() === RoomStatus::Occupied->value) {
                $this->changeRoomStatus->handle($room, RoomStatus::Dirty->value, $user, [
                    'stay_id' => $stay->id,
                    ...$context,
                ]);
            }

            return $stay;
        });
    }

    /**
     * Los tours comprados como plus de la reserva (líneas `experiences`)
     * siguen su ciclo de vida: confirmar la reserva los confirma, cancelarla
     * los cancela (libera el cupo de la sesión). Solo toca los VIVOS — una
     * experiencia ya cancelada a mano no se revive.
     */
    protected function syncLinkedExperiences(Reservation $reservation, string $status): void
    {
        $reservation->experienceBookings()
            ->whereIn('status', $status === \App\Models\ExperienceBooking::STATUS_CONFIRMED
                ? [\App\Models\ExperienceBooking::STATUS_PENDING]
                : [\App\Models\ExperienceBooking::STATUS_PENDING, \App\Models\ExperienceBooking::STATUS_CONFIRMED])
            ->update(['status' => $status, 'updated_at' => now()]);
    }

    /**
     * @param  array<int, ReservationStatus>  $allowed
     */
    protected function assertStatus(Reservation $reservation, array $allowed): void
    {
        if (! in_array($reservation->status, $allowed, true)) {
            $labels = implode(' / ', array_map(fn (ReservationStatus $s) => $s->label(), $allowed));

            throw new InvalidArgumentException(
                "La reserva está \"{$reservation->status->label()}\"; esta acción requiere: {$labels}.",
            );
        }
    }
}
