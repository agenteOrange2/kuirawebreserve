<?php

namespace App\Actions\Reservations;

use App\Actions\Rooms\ChangeRoomStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\Exceptions\NoAvailabilityException;
use App\Models\Guest;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class UpdateReservation
{
    public function __construct(
        protected AvailabilityService $availability,
        protected ChangeRoomStatus $changeRoomStatus,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws NoAvailabilityException
     */
    public function handle(Reservation $reservation, array $data, ?User $user = null): Reservation
    {
        return DB::transaction(function () use ($reservation, $data, $user) {
            $reservation = Reservation::query()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $ratePlan = RatePlan::findOrFail($data['rate_plan_id']);
            $start = Carbon::parse($data['starts_at']);
            $end = isset($data['ends_at']) && $data['ends_at']
                ? Carbon::parse($data['ends_at'])
                : $ratePlan->suggestedEnd($start);

            // La antelación mínima aplica solo si se MUEVE la llegada (editar
            // notas de una reserva ya dentro de la ventana no debe fallar).
            if (! $start->equalTo($reservation->starts_at) && $ratePlan->violatesMinAdvance($start)) {
                throw NoAvailabilityException::minAdvance($ratePlan->minAdvanceLabel());
            }

            $oldRoom = Room::query()->whereKey($reservation->room_id)->lockForUpdate()->first();
            $room = $this->resolveRoom($reservation, $data, $ratePlan, $start, $end);
            $guest = $this->resolveGuest($data);

            $wasReservedToday = $reservation->status === ReservationStatus::Confirmed
                && $reservation->starts_at->isToday()
                && $oldRoom?->status->getMorphClass() === RoomStatus::Reserved->value;

            if ($wasReservedToday && $oldRoom && $oldRoom->id !== $room->id) {
                $this->changeRoomStatus->handle($oldRoom, RoomStatus::Available->value, $user, [
                    'reservation_id' => $reservation->id,
                ]);
            }

            $total = $ratePlan->priceFor($start, $end, $room);

            // num_people sin desglose (API vieja) cuenta como adultos.
            $adults = max(1, (int) ($data['adults'] ?? $data['num_people'] ?? $reservation->adults ?? $reservation->num_people ?? 1));
            $children = max(0, (int) ($data['children'] ?? $reservation->children ?? 0));

            $reservation->update([
                'property_id' => $room->property_id,
                'room_type_id' => $ratePlan->room_type_id,
                'room_id' => $room->id,
                'rate_plan_id' => $ratePlan->id,
                'guest_id' => $guest?->id,
                'guest_name' => array_key_exists('guest_name', $data)
                    ? ($data['guest_name'] ?: $guest?->full_name)
                    : $reservation->guest_name,
                'num_people' => $adults + $children,
                'adults' => $adults,
                'children' => $children,
                'vehicle_plate' => array_key_exists('vehicle_plate', $data) ? $data['vehicle_plate'] : $reservation->vehicle_plate,
                'vehicle_desc' => array_key_exists('vehicle_desc', $data) ? $data['vehicle_desc'] : $reservation->vehicle_desc,
                'eta' => array_key_exists('eta', $data) ? $data['eta'] : $reservation->eta,
                'starts_at' => $start,
                'ends_at' => $end,
                'source_channel' => $data['source_channel'] ?? $reservation->source_channel,
                'total_amount' => $total,
                // Cambiar tarifa/fechas recalcula anticipo y fecha límite.
                'deposit_amount' => $ratePlan->depositAmountFor($total)
                    ?? $data['deposit_amount']
                    ?? $reservation->deposit_amount,
                'payment_due_at' => $ratePlan->paymentDueAt($start),
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $reservation->notes,
                'guest_notes' => array_key_exists('guest_notes', $data) ? $data['guest_notes'] : $reservation->guest_notes,
            ]);

            // El total pudo cambiar: re-deriva el estado de pago.
            $reservation->syncPaymentStatus();

            $shouldReserveToday = $reservation->status === ReservationStatus::Confirmed && $start->isToday();

            if ($wasReservedToday && ! $shouldReserveToday && $oldRoom && $oldRoom->id === $room->id) {
                $this->changeRoomStatus->handle($oldRoom, RoomStatus::Available->value, $user, [
                    'reservation_id' => $reservation->id,
                ]);
            }

            if ($shouldReserveToday && $room->status->getMorphClass() === RoomStatus::Available->value) {
                $this->changeRoomStatus->handle($room, RoomStatus::Reserved->value, $user, [
                    'reservation_id' => $reservation->id,
                ]);
            }

            return $reservation;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws NoAvailabilityException
     */
    protected function resolveRoom(
        Reservation $reservation,
        array $data,
        RatePlan $ratePlan,
        Carbon $start,
        Carbon $end,
    ): Room {
        if (! empty($data['room_id'])) {
            $room = Room::query()
                ->whereKey($data['room_id'])
                ->where('room_type_id', $ratePlan->room_type_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->availability->isRoomAvailable($room, $start, $end, $reservation->id)) {
                throw NoAvailabilityException::forRoom($room->number);
            }

            return $room;
        }

        $room = $this->availability
            ->availableRooms($ratePlan->room_type_id, $start, $end, $reservation->id, lock: true)
            ->first();

        if (! $room) {
            throw NoAvailabilityException::forRoomType();
        }

        return $room;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveGuest(array $data): ?Guest
    {
        if (! empty($data['guest_id'])) {
            return Guest::findOrFail($data['guest_id']);
        }

        $phone = $data['guest_phone'] ?? null;
        $email = $data['guest_email'] ?? null;

        if (! $phone && ! $email) {
            return null;
        }

        return Guest::firstOrCreate(
            $phone ? ['phone' => $phone] : ['email' => $email],
            ['first_name' => $data['guest_name'] ?? null, 'email' => $email, 'phone' => $phone],
        );
    }
}
