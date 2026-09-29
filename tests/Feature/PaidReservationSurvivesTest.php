<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\RegisterReservationPayment;
use App\Actions\Reservations\TransitionReservation;
use App\Enums\ReservationStatus;
use App\Events\RoomStatusChanged;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Facades\Event;

/**
 * Una reserva con dinero encima no la tira un reloj.
 *
 * Caso real cabañas 2026-09-12 (RES-2026-1718, Yazmin Manzo): el bot creó el
 * apartado a las 19:11 con fecha límite de pago del 08-sep —cuatro días ANTES
 * de existir, porque la tarifa la pone "una semana antes de la llegada" y ella
 * reservó con tres días—; a las 19:51 depositó su anticipo y la reserva se
 * confirmó; a las 20:00 el barrido de saldos la canceló por "vencida", y otra
 * vez a las 21:00 después de que recepción la reabrió.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1000,
        'deposit_percent' => 50,
        'payment_due_unit' => 'week',
        'payment_due_value' => 1,
    ]);
});

function reservaDe(int $diasAdelante, bool $confirmada = false): Reservation
{
    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDays($diasAdelante)->setTime(15, 0),
        'ends_at' => now()->addDays($diasAdelante + 1)->setTime(11, 0),
        'guest_name' => 'Huésped Que Pagó',
        'confirmed' => $confirmada,
    ]);
}

it('un apartado vencido con anticipo pagado se confirma en vez de cancelarse', function () {
    $reservation = reservaDe(30);
    app(RegisterReservationPayment::class)->handle($reservation, ['amount' => 500, 'method' => 'cash']);

    // Se le acaba el plazo del apartado con el dinero ya adentro.
    $reservation->update(['hold_expires_at' => now()->subMinutes(5)]);

    $this->artisan('reservations:expire-holds')->assertSuccessful();

    $reservation->refresh();

    expect($reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->cancellation_reason)->toBeNull()
        // "Solo queda como el saldo pendiente": la mitad que falta.
        ->and($reservation->pendingBalance())->toEqual(500.0);
});

it('un apartado vencido sin un peso encima se sigue cancelando', function () {
    $reservation = reservaDe(30);
    $reservation->update(['hold_expires_at' => now()->subMinutes(5)]);

    $this->artisan('reservations:expire-holds')->assertSuccessful();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled)
        ->and($reservation->cancellation_reason)->toBe(Reservation::EXPIRED_HOLD_REASON);
});

it('el barrido de saldos no cancela a quien nunca recibió el aviso', function () {
    $this->property->update(['settings' => ['cancel_on_balance_overdue' => true]]);

    $reservation = reservaDe(3, confirmada: true);
    app(RegisterReservationPayment::class)->handle($reservation, ['amount' => 500, 'method' => 'cash']);
    // Fecha límite ya vencida al nacer, como la de la reserva real.
    $reservation->update(['payment_due_at' => now()->subDays(4)]);

    Conversation::create([
        'channel_id' => Channel::webchat()->id,
        'reservation_id' => $reservation->id,
        'contact_name' => 'Huésped Que Pagó',
        'status' => Conversation::STATUS_OPEN,
        'bot_enabled' => true,
        'last_message_at' => now(),
    ]);

    $this->artisan('payments:collect-balance')->assertSuccessful();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->pendingBalance())->toEqual(500.0);
});

it('el barrido de saldos sí cancela cuando ya se le pidió el saldo', function () {
    $this->property->update(['settings' => ['cancel_on_balance_overdue' => true]]);

    $reservation = reservaDe(3, confirmada: true);
    app(RegisterReservationPayment::class)->handle($reservation, ['amount' => 500, 'method' => 'cash']);
    $reservation->update(['payment_due_at' => now()->subHours(2)]);

    $conversation = Conversation::create([
        'channel_id' => Channel::webchat()->id,
        'reservation_id' => $reservation->id,
        'contact_name' => 'Huésped Que Pagó',
        'status' => Conversation::STATUS_OPEN,
        'bot_enabled' => true,
        'last_message_at' => now(),
    ]);
    // Ya se le pidió el saldo: la política del hotel sigue en pie.
    $conversation->markFollowup('balance_request');

    $this->artisan('payments:collect-balance')->assertSuccessful();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);
});

it('la fecha límite de la tarifa no nace vencida', function () {
    // Tarifa "una semana antes" + llegada en 3 días = plazo cumplido antes de
    // existir: no se pone fecha límite y el saldo se cobra a mano.
    expect(reservaDe(3)->payment_due_at)->toBeNull();

    // Con margen de sobra sí se pone, una semana antes de la llegada.
    $conMargen = reservaDe(30);

    expect($conMargen->payment_due_at)->not->toBeNull()
        ->and($conMargen->payment_due_at->toDateString())
        ->toBe(now()->addDays(23)->toDateString());
});

it('un pago de mostrador sobre una reserva cancelada la revive', function () {
    $reservation = reservaDe(10, confirmada: true);
    app(TransitionReservation::class)->cancel($reservation);

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);

    $payment = app(RegisterReservationPayment::class)
        ->handle($reservation, ['amount' => 500, 'method' => 'cash']);

    $reservation->refresh();

    // Y queda CONFIRMADA, igual que si el pago se hubiera verificado en
    // /pagos: con dinero encima, dejarla pendiente con un hold de minutos
    // solo la exponía a que el barrido la volviera a cancelar (2026-09-15).
    expect((float) $payment->amount)->toEqual(500.0)
        ->and($reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->cancellation_reason)->toBeNull()
        ->and($reservation->paidTotal())->toEqual(500.0);
});

it('si la habitación ya se vendió, el pago de mostrador se rechaza diciendo qué hacer', function () {
    $reservation = reservaDe(10, confirmada: true);
    app(TransitionReservation::class)->cancel($reservation);

    // El único cuarto se le vendió a alguien más en las mismas fechas.
    reservaDe(10, confirmada: true);

    expect(fn () => app(RegisterReservationPayment::class)
        ->handle($reservation->refresh(), ['amount' => 500, 'method' => 'cash']))
        ->toThrow(InvalidArgumentException::class, 'reábrela con fechas nuevas');
});

/**
 * Caso real cabañas 2026-09-26→29 (RES-2026-1750, Iris Zepeda): el barrido la
 * canceló por saldo vencido, recepción la reabrió y a la siguiente hora en
 * punto el barrido la volvió a cancelar — cuatro veces. Reabrir no tocaba la
 * fecha límite ya vencida.
 */
it('una reserva reabierta tras cancelarse por saldo no vuelve a caer en el barrido', function () {
    $this->property->update(['settings' => ['cancel_on_balance_overdue' => true]]);

    $reservation = reservaDe(4, confirmada: true);
    app(RegisterReservationPayment::class)->handle($reservation, ['amount' => 500, 'method' => 'cash']);
    $reservation->update(['payment_due_at' => now()->subDays(3)]);

    Conversation::create([
        'channel_id' => Channel::webchat()->id,
        'reservation_id' => $reservation->id,
        'contact_name' => 'Huésped Que Pagó',
        'status' => Conversation::STATUS_OPEN,
        'bot_enabled' => true,
        'last_message_at' => now(),
    ])->markFollowup('balance_request');

    // La política del hotel se cumple la primera vez.
    $this->artisan('payments:collect-balance')->assertSuccessful();
    expect($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);

    // Recepción hace la excepción y la reabre confirmada.
    app(TransitionReservation::class)->reopen($reservation, null, ['confirmed' => true]);

    $this->artisan('payments:collect-balance')->assertSuccessful();

    $reservation->refresh();

    expect($reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->payment_due_at)->toBeNull()
        // El saldo no se perdona: sigue pendiente para recepción.
        ->and($reservation->pendingBalance())->toEqual(500.0);
});

it('reabrir conserva una fecha límite que todavía no vence', function () {
    $reservation = reservaDe(30, confirmada: true);
    $due = $reservation->payment_due_at;

    app(TransitionReservation::class)->cancel($reservation, null, reason: 'Prueba');
    app(TransitionReservation::class)->reopen($reservation->refresh(), null, ['confirmed' => true]);

    expect($reservation->refresh()->payment_due_at?->equalTo($due))->toBeTrue();
});

it('revivir por un pago tardío tampoco hereda la fecha límite vencida', function () {
    $reservation = reservaDe(4, confirmada: true);
    $reservation->update(['payment_due_at' => now()->subDays(3)]);
    app(TransitionReservation::class)->cancel($reservation, null, reason: 'Saldo no cubierto');

    app(RegisterReservationPayment::class)->handle($reservation->refresh(), ['amount' => 500, 'method' => 'cash']);

    expect($reservation->refresh()->status)->not->toBe(ReservationStatus::Cancelled)
        ->and($reservation->payment_due_at)->toBeNull();
});

it('editar la reserva sin mover la llegada no le devuelve una fecha límite vencida', function () {
    // Reservó dentro del plazo: nació sin fecha límite.
    $reservation = reservaDe(3, confirmada: true);
    expect($reservation->payment_due_at)->toBeNull();

    app(\App\Actions\Reservations\UpdateReservation::class)->handle($reservation, [
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => $reservation->starts_at,
        'ends_at' => $reservation->ends_at,
        'notes' => 'Llega tarde',
    ]);

    expect($reservation->refresh()->payment_due_at)->toBeNull();

    // Moverla a un mes sí le pone su fecha límite normal.
    app(\App\Actions\Reservations\UpdateReservation::class)->handle($reservation, [
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(30)->setTime(15, 0),
        'ends_at' => now()->addDays(31)->setTime(11, 0),
    ]);

    expect($reservation->refresh()->payment_due_at?->toDateString())->toBe(now()->addDays(23)->toDateString());
});
