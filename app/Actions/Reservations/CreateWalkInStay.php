<?php

namespace App\Actions\Reservations;

use App\Actions\Rooms\ChangeRoomStatus;
use App\Enums\RoomStatus;
use App\Exceptions\NoAvailabilityException;
use App\Models\Guest;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Walk-in: ocupación inmediata sin reserva previa (flujo motel / mostrador).
 */
class CreateWalkInStay
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
    public function handle(array $data, ?User $user = null): Stay
    {
        $ratePlan = RatePlan::findOrFail($data['rate_plan_id']);
        $start = now();
        $end = isset($data['planned_end_at']) && $data['planned_end_at']
            ? Carbon::parse($data['planned_end_at'])
            : $ratePlan->suggestedEnd($start);

        return DB::transaction(function () use ($data, $ratePlan, $start, $end, $user) {
            $room = Room::whereKey($data['room_id'])
                ->where('room_type_id', $ratePlan->room_type_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($room->status->getMorphClass() !== RoomStatus::Available->value
                || ! $this->availability->isRoomAvailable($room, $start, $end)) {
                throw NoAvailabilityException::forRoom($room->number);
            }

            $guest = null;
            if (! empty($data['guest_id'])) {
                $guest = Guest::findOrFail($data['guest_id']);
            } elseif (! empty($data['guest_phone'])) {
                $guest = Guest::firstOrCreate(
                    ['phone' => $data['guest_phone']],
                    ['first_name' => $data['guest_name'] ?? null],
                );
            }

            // Cargos extra de la ficha: personas sobre las incluidas +
            // cargos opcionales elegidos (mascota, decoración…).
            $extraCharges = $room->extraChargeLines(
                max(1, (int) ($data['num_people'] ?? 1)),
                $ratePlan->unitsFor($start, $end),
                $data['extra_charges'] ?? [],
            );

            $stay = Stay::create([
                'room_id' => $room->id,
                'rate_plan_id' => $ratePlan->id,
                'guest_id' => $guest?->id,
                'guest_name' => $data['guest_name'] ?? $guest?->full_name,
                'num_people' => $data['num_people'] ?? 1,
                'vehicle_plate' => $data['vehicle_plate'] ?? null,
                'vehicle_desc' => $data['vehicle_desc'] ?? null,
                'check_in_at' => $start,
                'planned_end_at' => $end,
                'status' => Stay::STATUS_ACTIVE,
                'amount' => round(
                    $ratePlan->priceFor($start, $end, $room) + array_sum(array_column($extraCharges, 'amount')),
                    2,
                ),
                'extra_charges' => $extraCharges ?: null,
                'channel' => 'walk_in',
                'notes' => $data['notes'] ?? null,
                'created_by' => $user?->id,
            ]);

            $this->changeRoomStatus->handle($room, RoomStatus::Occupied->value, $user, [
                'stay_id' => $stay->id,
            ]);

            return $stay;
        });
    }
}
