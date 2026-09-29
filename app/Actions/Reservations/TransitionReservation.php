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
use App\Services\CouponService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
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
        protected CouponService $coupons,
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

            // El cupón cuenta su uso recién AQUÍ (no en el hold, que puede
            // expirar sin pena). Punto único de salida de Pending → sin
            // doble incremento: ver redeemCoupon().
            $this->redeemCoupon($reservation);

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

        $reservation = DB::transaction(function () use ($reservation, $user, $to, $reason) {
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

        // Fuera de la transacción: avisar a la lista de espera (módulo
        // lista-espera) es cortesía — un transporte caído no debe poder
        // revertir una cancelación ya hecha.
        try {
            app(\App\Services\Channels\WaitlistNotifier::class)->roomFreed($reservation);
        } catch (\Throwable $e) {
            report($e);
        }

        // "No llegó" no es una cancelación: ese lo marca recepción en persona.
        if ($to === ReservationStatus::Cancelled) {
            app(\App\Services\StaffAlerts::class)->reservationCancelled($reservation, $reason);
        }

        return $reservation;
    }

    /**
     * Reabre una reserva cancelada o de "no llegó" con el MISMO código, en
     * sus fechas o en unas nuevas (reagendar).
     *
     * Caso real cabañas 2026-09-10: el apartado venció mientras el huésped
     * depositaba; el personal le dio su código por chat, pero la reserva
     * seguía cancelada y no había manera de revivirla — solo de hacer otra.
     *
     * Vuelve como apartado (Pendiente, con el plazo del hotel) o confirmada.
     * Confirmar pasa por confirm(): la misma puerta de siempre (cupo, cupón,
     * tours, semáforo del día y aviso al huésped). Los pagos ya registrados
     * se conservan; los tours que se cancelaron con ella NO se reviven — su
     * cupo pudo venderse.
     *
     * @param  array{starts_at?: mixed, ends_at?: mixed, room_id?: int|null, confirmed?: bool, hold_minutes?: int|null, notify_guest?: bool}  $data
     *
     * @throws NoAvailabilityException
     * @throws InvalidArgumentException
     */
    public function reopen(Reservation $reservation, ?User $user = null, array $data = []): Reservation
    {
        $this->assertStatus($reservation, [ReservationStatus::Cancelled, ReservationStatus::NoShow]);

        $newDates = ! empty($data['starts_at']);
        $start = $newDates ? Carbon::parse($data['starts_at']) : Carbon::parse($reservation->starts_at);
        $end = match (true) {
            ! empty($data['ends_at']) => Carbon::parse($data['ends_at']),
            $newDates && $reservation->ratePlan !== null => Carbon::parse($reservation->ratePlan->suggestedEnd($start)),
            default => Carbon::parse($reservation->ends_at),
        };

        if ($end->lte($start)) {
            throw new InvalidArgumentException('La salida debe ser después de la llegada.');
        }

        // Una llegada que ya pasó no se revive tal cual: sería una reserva
        // apartando noches que nadie va a dormir.
        if ($start->lt(now()->startOfDay())) {
            throw new InvalidArgumentException('La llegada de esta reserva ya pasó: elige fechas nuevas para reagendarla.');
        }

        $datesChanged = ! $start->equalTo($reservation->starts_at) || ! $end->equalTo($reservation->ends_at);

        $reservation = DB::transaction(function () use ($reservation, $user, $data, $start, $end, $datesChanged) {
            $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            // Dos personas reabriendo la misma a la vez: la segunda se entera.
            $this->assertStatus($reservation, [ReservationStatus::Cancelled, ReservationStatus::NoShow]);

            if ($datesChanged || ! empty($data['room_id'])) {
                // Reagendar es la misma edición del panel: revisa el cupo y
                // recalcula precio, anticipo y fecha límite con las fechas
                // nuevas. Se intenta primero en su misma habitación.
                $payload = [
                    'rate_plan_id' => $reservation->rate_plan_id,
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'guest_id' => $reservation->guest_id,
                ];

                try {
                    app(UpdateReservation::class)->handle($reservation, $payload + ['room_id' => $data['room_id'] ?? $reservation->room_id], $user);
                } catch (NoAvailabilityException $e) {
                    if (! empty($data['room_id'])) {
                        throw $e;
                    }

                    app(UpdateReservation::class)->handle($reservation, $payload + ['room_id' => null], $user);
                }

                $reservation->refresh();
            } else {
                // Mismas fechas: el precio pactado se respeta tal cual; solo
                // hace falta una habitación libre (la suya, si sigue libre).
                $reservation->room_id = $this->reopenRoom($reservation, $start, $end)->id;
            }

            $holdMinutes = (int) ($data['hold_minutes'] ?? app(\App\Services\ReservationPolicy::class)->holdMinutes());

            $reservation->update([
                'room_id' => $reservation->room_id,
                'status' => ReservationStatus::Pending,
                'hold_expires_at' => now()->addMinutes(max(1, $holdMinutes)),
                'cancellation_reason' => null,
            ]);

            activity('reservation')
                ->performedOn($reservation)
                ->causedBy($user)
                ->log($datesChanged ? 'Reserva reabierta y reagendada' : 'Reserva reabierta');

            self::dropOverdueBalanceDeadline($reservation, $user);

            return $reservation;
        });

        if ((bool) ($data['confirmed'] ?? false)) {
            // Reabrirla en silencio es opción del panel: a un huésped cuya
            // reserva se cayó y se reabrió varias veces le llegaba una
            // confirmación (y un contrato por correo) en cada vuelta
            // (cabañas RES-2026-1750, 2026-09-26→29).
            $reservation = $this->confirm($reservation->refresh(), $user, notifyGuest: (bool) ($data['notify_guest'] ?? true));
        }

        return $reservation;
    }

    /**
     * Una reserva que vuelve a la vida (reabierta a mano o revivida por un
     * pago) no hereda una fecha límite del saldo ya vencida: con ella puesta,
     * el barrido de saldos (payments:collect-balance) la cancelaba otra vez a
     * la siguiente hora en punto, y así cada vez que recepción la reabría.
     * Caso real cabañas 2026-09-26→29 (RES-2026-1750): cuatro veces reabierta
     * y cuatro veces cancelada. Reabrirla es la excepción que el hotel decide;
     * el saldo sigue pendiente y se cobra en recepción. Las reservas que nadie
     * reabre siguen con su fecha límite y su cancelación como siempre.
     */
    public static function dropOverdueBalanceDeadline(Reservation $reservation, ?User $user = null): void
    {
        if ($reservation->payment_due_at === null || ! $reservation->payment_due_at->isPast()) {
            return;
        }

        $due = $reservation->payment_due_at->format('d/m/Y H:i');

        $reservation->update(['payment_due_at' => null]);

        activity('reservation')
            ->performedOn($reservation)
            ->causedBy($user)
            ->log("Fecha límite del saldo ({$due}) retirada al reabrir: ya había vencido. El saldo se cobra en recepción.");
    }

    /**
     * Habitación para reabrir en las mismas fechas: la suya si sigue libre;
     * si ya se vendió, otra libre del mismo tipo.
     *
     * @throws NoAvailabilityException
     */
    protected function reopenRoom(Reservation $reservation, CarbonInterface $start, CarbonInterface $end): Room
    {
        if ($reservation->room_id) {
            $room = Room::query()->whereKey($reservation->room_id)->lockForUpdate()->first();

            if ($room && $this->availability->isRoomAvailable($room, $start, $end, $reservation->id)) {
                return $room;
            }
        }

        $room = $this->availability
            ->availableRooms($reservation->room_type_id, $start, $end, $reservation->id, lock: true)
            ->first();

        if (! $room) {
            throw NoAvailabilityException::forRoomType();
        }

        return $room;
    }

    /**
     * @param  array<string, mixed>  $context  Extra para room_status_logs
     *                                         (p. ej. ['auto' => true] del scheduler).
     * @param  string|null  $guaranteeMethod  Método presencial (cash/card) con
     *                                        el que el staff cobró la fianza
     *                                        al registrar la llegada; null =
     *                                        sin fianza (ajuste apagado o
     *                                        check-in automático sin staff).
     * @param  float|null  $guaranteeAmount  Monto capturado a mano en el
     *                                       mostrador; null = el de la
     *                                       política (con su escalón).
     * @param  string|null  $guaranteeReason  Motivo del ajuste — obligatorio
     *                                        si el monto difiere del de la
     *                                        política (ver ChargeGuarantee).
     *
     * @throws NoAvailabilityException
     * @throws \InvalidArgumentException
     */
    public function checkIn(
        Reservation $reservation,
        ?User $user = null,
        array $context = [],
        ?string $guaranteeMethod = null,
        ?float $guaranteeAmount = null,
        ?string $guaranteeReason = null,
        bool $allowEarly = false,
        ?string $guaranteeReference = null,
    ): Stay {
        $this->assertStatus($reservation, [ReservationStatus::Pending, ReservationStatus::Confirmed]);

        // Llegar a las 11:00 cuando la entrada es a las 15:00 es operación
        // normal, no anticipada: solo se pide confirmación cuando la entrada
        // es de OTRO día. Ojo con lo que no se recalcula: la estancia hereda
        // planned_end_at y el importe de la reserva, así que adelantar la
        // entrada regala las noches de más — el panel lo dice antes.
        if (! $allowEarly
            && $reservation->starts_at->isFuture()
            && ! $reservation->starts_at->isToday()) {
            throw NoAvailabilityException::earlyArrival(
                $reservation->displayCode(),
                $reservation->starts_at->format('d/m/Y H:i'),
            );
        }

        $wasPending = $reservation->status === ReservationStatus::Pending;

        return DB::transaction(function () use ($reservation, $user, $context, $guaranteeMethod, $guaranteeAmount, $guaranteeReason, $guaranteeReference, $wasPending) {
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
                // La placa de una reserva también alimenta el registro, pero
                // hasta el check-in: el carro existe cuando llega, no cuando
                // alguien lo teclea en el motor web.
                'vehicle_id' => app(\App\Services\VehicleRegistry::class)->resolve(
                    ['vehicle_plate' => $reservation->vehicle_plate],
                    $reservation->guest,
                )?->id,
                'check_in_at' => now(),
                'planned_end_at' => $reservation->ends_at,
                'status' => Stay::STATUS_ACTIVE,
                'amount' => $reservation->total_amount,
                'channel' => $reservation->source_channel,
                'created_by' => $user?->id,
            ]);

            $reservation->update(['status' => ReservationStatus::CheckedIn, 'hold_expires_at' => null]);

            // Check-in directo desde Pendiente: el cupón cuenta su uso aquí
            // (la reserva jamás pasó por confirm()). Desde Confirmada ya se
            // contó — Pending se abandona exactamente una vez, así que no
            // hay doble incremento posible.
            if ($wasPending) {
                $this->redeemCoupon($reservation);
            }

            // Fianza (depósito en garantía): se cobra al registrar la
            // llegada con método presencial. Va ligada SOLO a la estancia
            // (stay_id, sin reservation_id) para no inflar paidTotal() ni
            // el folio de hospedaje — no es ingreso, es pasivo. El monto lo
            // resuelve ChargeGuarantee con el escalón de la partida.
            app(\App\Actions\Payments\ChargeGuarantee::class)->handle(
                $stay,
                $guaranteeMethod,
                $user,
                $guaranteeAmount,
                $guaranteeReason,
                $reservation->partyRoomCount(),
                $guaranteeReference,
            );

            // Check-in directo desde pendiente: los tours ligados quedan firmes.
            $this->syncLinkedExperiences($reservation, \App\Models\ExperienceBooking::STATUS_CONFIRMED);

            $this->changeRoomStatus->handle($room, RoomStatus::Occupied->value, $user, [
                'reservation_id' => $reservation->id,
                'stay_id' => $stay->id,
                ...$context,
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

        $stay = DB::transaction(function () use ($stay, $user, $context) {
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

        // Fuera de la transacción: agradecer es cortesía, no debe poder
        // revertir un check-out ya hecho. Aplica igual al manual y al
        // automático — ambos pasan por este action.
        $this->sendPostStayThanks($stay);

        return $stay;
    }

    /**
     * Agradecimiento post-estancia (con link de reseñas si el hotel lo
     * capturó en /ajustes/metodos-pago): sale UNA sola vez por estancia —
     * thanks_sent_at es el sello de idempotencia, reclamado de forma
     * atómica — y solo con el interruptor prendido. Nunca revienta el
     * check-out.
     */
    protected function sendPostStayThanks(Stay $stay): void
    {
        try {
            if (! app(\App\Services\ReservationPolicy::class)->postStayThanksEnabled()) {
                return;
            }

            // Sello atómico: si otra corrida ya lo reclamó, esta no manda.
            $claimed = Stay::query()
                ->whereKey($stay->id)
                ->whereNull('thanks_sent_at')
                ->update(['thanks_sent_at' => now()]);

            if ($claimed === 0) {
                return;
            }

            $stay->forceFill(['thanks_sent_at' => now()]);

            app(\App\Services\Payments\PaymentGuestNotifier::class)->postStayThanks($stay);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Cuenta el uso del cupón de la reserva (módulo cupones) de forma
     * atómica. Se llama SOLO al salir del estado Pendiente (confirm() o
     * check-in directo) — ese estado se abandona una única vez, así que el
     * incremento no puede duplicarse. El hold nunca cuenta: si expira, el
     * cupón queda intacto.
     */
    protected function redeemCoupon(Reservation $reservation): void
    {
        $this->coupons->redeem($reservation);
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
