<?php

namespace App\Actions\Reservations;

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
    ) {}

    /**
     * @throws NoAvailabilityException
     */
    public function confirm(Reservation $reservation, ?User $user = null): Reservation
    {
        $this->assertStatus($reservation, [ReservationStatus::Pending]);

        return DB::transaction(function () use ($reservation, $user) {
            $room = Room::whereKey($reservation->room_id)->lockForUpdate()->firstOrFail();

            // Si el hold venció, alguien más pudo ganar la habitación.
            if (! $this->availability->isRoomAvailable($room, $reservation->starts_at, $reservation->ends_at, $reservation->id)) {
                throw NoAvailabilityException::forRoom($room->number);
            }

            $reservation->update([
                'status' => ReservationStatus::Confirmed,
                'hold_expires_at' => null,
            ]);

            if ($reservation->starts_at->isToday() && $room->status->getMorphClass() === RoomStatus::Available->value) {
                $this->changeRoomStatus->handle($room, RoomStatus::Reserved->value, $user, [
                    'reservation_id' => $reservation->id,
                ]);
            }

            return $reservation;
        });
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
                throw NoAvailabilityException::forRoom($room->number);
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

            $this->changeRoomStatus->handle($room, RoomStatus::Occupied->value, $user, [
                'reservation_id' => $reservation->id,
                'stay_id' => $stay->id,
            ]);

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

            $this->changeRoomStatus->handle($stay->room, RoomStatus::Dirty->value, $user, [
                'stay_id' => $stay->id,
                ...$context,
            ]);

            return $stay;
        });
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
