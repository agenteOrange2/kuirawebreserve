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

/**
 * Crea una reserva (hold pendiente o confirmada directa) con asignación de
 * habitación bajo lock pesimista — el punto anti-doble-reserva del spec §7.
 * La usan el panel, la API externa (fase 6) y los agentes IA (fase 4).
 */
class CreateReservation
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
    public function handle(array $data, ?User $user = null): Reservation
    {
        $ratePlan = RatePlan::findOrFail($data['rate_plan_id']);
        $start = Carbon::parse($data['starts_at']);
        $end = isset($data['ends_at']) && $data['ends_at']
            ? Carbon::parse($data['ends_at'])
            : $ratePlan->suggestedEnd($start);

        // Ventana de reserva (spec-profundidad §2.6.2): aplica a todos los
        // canales; la ocupación inmediata es el walk-in (CreateWalkInStay).
        if ($ratePlan->violatesMinAdvance($start)) {
            throw NoAvailabilityException::minAdvance($ratePlan->minAdvanceLabel());
        }

        $confirmed = (bool) ($data['confirmed'] ?? false);

        return DB::transaction(function () use ($data, $ratePlan, $start, $end, $confirmed, $user) {
            $room = $this->resolveRoom($data, $ratePlan, $start, $end);

            $guest = $this->resolveGuest($data);

            $total = $ratePlan->priceFor($start, $end, $room);

            $adults = max(1, (int) ($data['adults'] ?? $data['num_people'] ?? 1));
            $children = max(0, (int) ($data['children'] ?? 0));

            $reservation = Reservation::create([
                'property_id' => $room->property_id,
                'room_type_id' => $ratePlan->room_type_id,
                'room_id' => $room->id,
                'rate_plan_id' => $ratePlan->id,
                'guest_id' => $guest?->id,
                'guest_name' => $data['guest_name'] ?? $guest?->full_name,
                'num_people' => $adults + $children,
                'adults' => $adults,
                'children' => $children,
                'vehicle_plate' => $data['vehicle_plate'] ?? null,
                'vehicle_desc' => $data['vehicle_desc'] ?? null,
                'eta' => $data['eta'] ?? null,
                'starts_at' => $start,
                'ends_at' => $end,
                'status' => $confirmed ? ReservationStatus::Confirmed : ReservationStatus::Pending,
                'hold_expires_at' => $confirmed ? null : now()->addMinutes(config('reservations.hold_minutes', 30)),
                'source_channel' => $data['source_channel'] ?? 'front_desk',
                'total_amount' => $total,
                // Cobro anticipado (spec §2.6.3): la tarifa manda; el monto
                // manual solo aplica en tarifas sin anticipo configurado.
                'deposit_amount' => $ratePlan->depositAmountFor($total) ?? $data['deposit_amount'] ?? 0,
                'payment_status' => \App\Enums\PaymentStatus::Unpaid,
                'payment_due_at' => $ratePlan->paymentDueAt($start),
                'notes' => $data['notes'] ?? null,
                'guest_notes' => $data['guest_notes'] ?? null,
                'created_by' => $user?->id,
            ]);

            $reservation->forceFill([
                'code' => Reservation::formatCode(
                    $reservation->id,
                    $reservation->created_at,
                ),
            ])->saveQuietly();

            // Semáforo: solo si la llegada es hoy y la habitación está libre.
            if ($confirmed && $start->isToday() && $room->status->getMorphClass() === RoomStatus::Available->value) {
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
    protected function resolveRoom(array $data, RatePlan $ratePlan, Carbon $start, Carbon $end): Room
    {
        if (! empty($data['room_id'])) {
            $room = Room::whereKey($data['room_id'])
                ->where('room_type_id', $ratePlan->room_type_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->availability->isRoomAvailable($room, $start, $end)) {
                throw NoAvailabilityException::forRoom($room->number);
            }

            return $room;
        }

        $room = $this->availability
            ->availableRooms($ratePlan->room_type_id, $start, $end, lock: true)
            ->first();

        if (! $room) {
            throw NoAvailabilityException::forRoomType();
        }

        return $room;
    }

    /**
     * Huésped del CRM: por id (autocompletado del panel), o encontrado/creado
     * por teléfono/email (bots de fase 4 llegan por aquí con el número de WA).
     *
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
