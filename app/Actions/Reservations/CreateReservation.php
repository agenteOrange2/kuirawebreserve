<?php

namespace App\Actions\Reservations;

use App\Actions\Rooms\ChangeRoomStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\Exceptions\NoAvailabilityException;
use App\Models\Guest;
use App\Models\Product;
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
        protected \App\Actions\Experiences\CreateExperienceBooking $createExperienceBooking,
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

            $adults = max(1, (int) ($data['adults'] ?? $data['num_people'] ?? 1));
            $children = max(0, (int) ($data['children'] ?? 0));

            // Techo real de ESTA habitación (override propio o el del tipo,
            // ver Room::effectiveMaxOccupancy) — único punto de verdad para
            // los tres canales que crean reservas (wizard, panel, agente).
            // Antes solo existía en el dato, nunca se hacía cumplir.
            $capacity = $room->effectiveMaxOccupancy();
            if ($capacity !== null && ($adults + $children) > $capacity) {
                throw NoAvailabilityException::exceedsCapacity($room->number, $capacity);
            }

            // Cargos extra de la ficha: personas sobre las incluidas +
            // cargos opcionales elegidos (mascota, decoración…).
            $extraCharges = $room->extraChargeLines(
                $adults + $children,
                $ratePlan->unitsFor($start, $end),
                $data['extra_charges'] ?? [],
            );

            // Extras del wizard (productos reales del inventario/POS,
            // /ajustes/wizard): solo snapshot aquí — el stock se descuenta
            // recién al check-in (ver TransitionReservation::checkIn),
            // nunca en un hold que puede expirar sin confirmarse.
            $productLines = $this->resolveProductLines($data['products'] ?? []);

            // Add-ons del módulo `extras` (decoración, desayuno, late
            // checkout): sin stock ni calendario, solo dinero congelado que
            // suma al total ANTES de emitir cobros — el anticipo % y el
            // saldo los incluyen solos (spec-motor-reservas-web §12.1).
            $extraLines = $this->resolveExtraLines($data['extras'] ?? []);

            // Experiencias como plus de la reserva (módulo `experiencias`):
            // el tour se valida y cotiza aquí (precio del servidor) y suma al
            // total; la reserva EXP- real con su cupo duro se crea después de
            // insertar la reserva, dentro de esta misma transacción.
            $experienceLines = $this->resolveExperienceLines($data['experiences'] ?? []);

            $total = round(
                $ratePlan->priceFor($start, $end, $room)
                    + array_sum(array_column($extraCharges, 'amount'))
                    + array_sum(array_column($productLines, 'total'))
                    + array_sum(array_column($extraLines, 'total'))
                    + array_sum(array_column($experienceLines, 'total')),
                2,
            );

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
                // Duración del apartado configurable por hotel (Métodos de
                // pago); default 30 min como siempre.
                'hold_expires_at' => $confirmed ? null : now()->addMinutes(app(\App\Services\ReservationPolicy::class)->holdMinutes()),
                'source_channel' => $data['source_channel'] ?? 'front_desk',
                'total_amount' => $total,
                'extra_charges' => $extraCharges ?: null,
                'products' => $productLines ?: null,
                'extras' => $extraLines ?: null,
                // Cobro anticipado (spec §2.6.3): la tarifa manda ("Exigir
                // cobro anticipado" en Catálogo); el monto manual solo
                // aplica en tarifas sin anticipo configurado.
                'deposit_amount' => $ratePlan->depositAmountFor($total) ?? $data['deposit_amount'] ?? 0,
                'payment_status' => \App\Enums\PaymentStatus::Unpaid,
                // La tarifa manda; sin anticipación propia aplica el default
                // del hotel, y el interruptor global puede apagar el módulo.
                'payment_due_at' => app(\App\Services\ReservationPolicy::class)->paymentDueAt($ratePlan, $start),
                'notes' => $data['notes'] ?? null,
                'guest_notes' => $data['guest_notes'] ?? null,
                'created_by' => $user?->id,
            ]);

            // Reservas EXP- reales (cupo duro bajo su propio lock): si el
            // cupo se agotó entre mostrar y reservar, la excepción revienta
            // la transacción completa — la habitación tampoco se aparta.
            $experienceLines = $this->createExperienceBookings($reservation, $experienceLines, $guest, $data, $confirmed, $user);

            $reservation->forceFill([
                'code' => Reservation::formatCode(
                    $reservation->id,
                    $reservation->created_at,
                ),
                'experiences' => $experienceLines ?: null,
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
     * Congela nombre/precio de los productos elegidos (extras del wizard).
     * Ignora silenciosamente cualquier id que ya no sea vendible en el
     * wizard — el caller (BookingController) ya filtra contra el mismo
     * criterio antes de ofrecerlos, así que esto es una segunda defensa,
     * no el filtro principal.
     *
     * @param  array<int, array{product_id: int, qty: int|float}>  $lines
     * @return array<int, array{product_id: int, name: string, qty: float, unit_price: float, total: float}>
     */
    protected function resolveProductLines(array $lines): array
    {
        if (empty($lines)) {
            return [];
        }

        $products = Product::query()
            ->whereIn('id', array_column($lines, 'product_id'))
            ->where('active', true)
            ->where('available_in_wizard', true)
            ->get()
            ->keyBy('id');

        $resolved = [];

        foreach ($lines as $line) {
            $product = $products->get($line['product_id'] ?? null);
            $qty = (float) ($line['qty'] ?? 0);

            if (! $product || $qty <= 0) {
                continue;
            }

            $resolved[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'qty' => $qty,
                'unit_price' => (float) $product->price,
                'total' => round($qty * (float) $product->price, 2),
            ];
        }

        return $resolved;
    }

    /**
     * Congela nombre/precio de los add-ons elegidos (módulo `extras`).
     * Igual que los productos: ignora ids que ya no estén activos — el
     * caller filtra antes de ofrecerlos, esto es segunda defensa.
     *
     * @param  array<int, array{extra_id: int, qty: int|float}>  $lines
     * @return array<int, array{extra_id: int, name: string, qty: float, unit_price: float, total: float}>
     */
    protected function resolveExtraLines(array $lines): array
    {
        if (empty($lines)) {
            return [];
        }

        $extras = \App\Models\Extra::query()
            ->whereIn('id', array_column($lines, 'extra_id'))
            ->where('active', true)
            ->get()
            ->keyBy('id');

        $resolved = [];

        foreach ($lines as $line) {
            $extra = $extras->get($line['extra_id'] ?? null);
            $qty = (float) ($line['qty'] ?? 0);

            if (! $extra || $qty <= 0) {
                continue;
            }

            $resolved[] = [
                'extra_id' => $extra->id,
                'name' => $extra->name,
                'qty' => $qty,
                'unit_price' => (float) $extra->price,
                'total' => round($qty * (float) $extra->price, 2),
            ];
        }

        return $resolved;
    }

    /**
     * Valida y cotiza las experiencias pedidas como plus de la reserva —
     * precio SIEMPRE del servidor, misma regla que products/extras. El cupo
     * duro se hace cumplir después, al crear las reservas EXP- bajo lock.
     *
     * @param  array<int, array{session_id: int, people: int}>  $lines
     * @return array<int, array<string, mixed>>
     */
    protected function resolveExperienceLines(array $lines): array
    {
        if (empty($lines)) {
            return [];
        }

        $sessions = \App\Models\ExperienceSession::query()
            ->with('experience')
            ->whereIn('id', array_column($lines, 'session_id'))
            ->get()
            ->keyBy('id');

        $resolved = [];

        foreach ($lines as $line) {
            $session = $sessions->get($line['session_id'] ?? null);
            $people = max(1, (int) ($line['people'] ?? 1));

            if (! $session || ! $session->isBookable() || ! $session->experience?->active) {
                throw new NoAvailabilityException('Una de las experiencias elegidas ya no está disponible; vuelve a consultar los horarios.');
            }

            $experience = $session->experience;

            $resolved[] = [
                'session_id' => $session->id,
                'experience_id' => $experience->id,
                'name' => $experience->name,
                'starts_at' => $session->starts_at->toIso8601String(),
                'people' => $people,
                'pricing_mode' => $experience->pricing_mode,
                'unit_price' => (float) $experience->price,
                'total' => $experience->totalFor($people),
            ];
        }

        return $resolved;
    }

    /**
     * Crea las reservas EXP- ligadas (cupo duro con lock en
     * CreateExperienceBooking) y congela ids/folios en las líneas.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    protected function createExperienceBookings(Reservation $reservation, array $lines, ?Guest $guest, array $data, bool $confirmed, ?User $user): array
    {
        foreach ($lines as $i => $line) {
            $booking = $this->createExperienceBooking->handle([
                'experience_session_id' => $line['session_id'],
                'people' => $line['people'],
                'reservation_id' => $reservation->id,
                'guest_id' => $guest?->id,
                'guest_name' => $data['guest_name'] ?? $guest?->full_name,
                'confirmed' => $confirmed,
            ], $user);

            $lines[$i]['experience_booking_id'] = $booking->id;
            $lines[$i]['code'] = $booking->code;
            // La verdad del monto vive en el booking (misma fórmula).
            $lines[$i]['total'] = (float) $booking->total;
        }

        return $lines;
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
