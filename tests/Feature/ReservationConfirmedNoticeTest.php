<?php

use App\Actions\Reservations\CreateReservation;
use App\Enums\ReservationStatus;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Payments\PaymentGuestNotifier;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '501',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 900,
    ]);
});

/** @return array<string, mixed> */
function confirmedNoticePayload(bool $confirmed): array
{
    return [
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(2)->setTime(12, 0),
        'confirmed' => $confirmed,
        'guest_name' => 'Nace Confirmada',
    ];
}

it('avisa al huésped cuando la reserva nace confirmada de inmediato', function () {
    $this->mock(PaymentGuestNotifier::class)
        ->shouldReceive('reservationConfirmed')
        ->once();

    $reservation = app(CreateReservation::class)->handle(confirmedNoticePayload(true));

    expect($reservation->status)->toBe(ReservationStatus::Confirmed);
});

it('un hold pendiente no avisa al crearse: el aviso sale al confirmar', function () {
    $this->mock(PaymentGuestNotifier::class)
        ->shouldNotReceive('reservationConfirmed');

    $reservation = app(CreateReservation::class)->handle(confirmedNoticePayload(false));

    expect($reservation->status)->toBe(ReservationStatus::Pending);
});

it('con fianza configurada, la confirmación anuncia el depósito y su monto', function () {
    test()->property->update(['settings' => array_merge(test()->property->settings ?? [], [
        'guarantee_enabled' => true,
        'guarantee_amount' => 1500,
    ])]);

    // Sin conversación ligada, el aviso sale directo: ahí se lee el cuerpo.
    $this->mock(\App\Services\Channels\DirectGuestMessenger::class)
        ->shouldReceive('send')
        ->once()
        ->withArgs(fn ($res, string $body) => str_contains($body, 'depósito en garantía de $1,500.00')
            && str_contains($body, 'identificación oficial'));

    app(CreateReservation::class)->handle(confirmedNoticePayload(true));
});

it('sin fianza, la confirmación no menciona ningún depósito', function () {
    $this->mock(\App\Services\Channels\DirectGuestMessenger::class)
        ->shouldReceive('send')
        ->once()
        ->withArgs(fn ($res, string $body) => ! str_contains($body, 'depósito en garantía'));

    app(CreateReservation::class)->handle(confirmedNoticePayload(true));
});

it('si el aviso truena, la reserva confirmada sobrevive igual', function () {
    $this->mock(PaymentGuestNotifier::class)
        ->shouldReceive('reservationConfirmed')
        ->once()
        ->andThrow(new RuntimeException('transporte caído'));

    $reservation = app(CreateReservation::class)->handle(confirmedNoticePayload(true));

    expect($reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->exists)->toBeTrue();
});
