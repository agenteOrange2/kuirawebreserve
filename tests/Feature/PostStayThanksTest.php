<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\TransitionReservation;
use App\Models\Guest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Services\Channels\DirectGuestMessenger;
use App\Services\Payments\PaymentGuestNotifier;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '701',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 850,
    ]);
});

function thanksSettings(array $settings): void
{
    $property = Property::firstOrFail();
    $property->update(['settings' => array_merge($property->settings ?? [], $settings)]);
}

/** Estancia activa nacida de una reserva con contacto del huésped. */
function makeActiveStay(): Stay
{
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(2)->setTime(12, 0),
        'confirmed' => true,
        'guest_name' => 'Sale Contento',
        'guest_phone' => '5522334455',
    ]);

    return app(TransitionReservation::class)->checkIn($reservation);
}

it('al hacer check-out se agradece una sola vez y se sella thanks_sent_at', function () {
    $this->mock(PaymentGuestNotifier::class, function ($mock) {
        $mock->shouldIgnoreMissing();
        $mock->shouldReceive('postStayThanks')->once();
    });

    $stay = makeActiveStay();

    app(TransitionReservation::class)->checkOut($stay);

    expect($stay->refresh()->status)->toBe(Stay::STATUS_COMPLETED)
        ->and($stay->thanks_sent_at)->not->toBeNull();

    // Repetir el check-out no duplica el agradecimiento: la estancia ya
    // está cerrada (el once() del mock lo verifica al cerrar el test).
    expect(fn () => app(TransitionReservation::class)->checkOut($stay))
        ->toThrow(InvalidArgumentException::class);
});

it('una estancia ya sellada no vuelve a agradecer', function () {
    $this->mock(PaymentGuestNotifier::class, function ($mock) {
        $mock->shouldIgnoreMissing();
        $mock->shouldNotReceive('postStayThanks');
    });

    $stay = makeActiveStay();
    Stay::query()->whereKey($stay->id)->update(['thanks_sent_at' => now()]);

    app(TransitionReservation::class)->checkOut($stay->refresh());

    expect($stay->refresh()->status)->toBe(Stay::STATUS_COMPLETED);
});

it('con el interruptor apagado no se envía ni se sella', function () {
    thanksSettings(['post_stay_thanks_enabled' => false]);

    $this->mock(PaymentGuestNotifier::class, function ($mock) {
        $mock->shouldIgnoreMissing();
        $mock->shouldNotReceive('postStayThanks');
    });

    $stay = makeActiveStay();

    app(TransitionReservation::class)->checkOut($stay);

    expect($stay->refresh()->status)->toBe(Stay::STATUS_COMPLETED)
        ->and($stay->thanks_sent_at)->toBeNull();
});

it('un walk-in sin contacto no truena y el check-out cierra normal', function () {
    $stay = Stay::create([
        'room_id' => $this->room->id,
        'rate_plan_id' => $this->plan->id,
        'guest_name' => 'Sin Contacto',
        'num_people' => 1,
        'check_in_at' => now()->subHours(3),
        'planned_end_at' => now()->addHours(3),
        'status' => Stay::STATUS_ACTIVE,
        'amount' => 500,
        'channel' => 'walk_in',
    ]);

    // Notifier real: sin reserva ni guest con contacto simplemente no
    // manda nada, y el check-out no debe reventar por eso.
    app(TransitionReservation::class)->checkOut($stay);

    expect($stay->refresh()->status)->toBe(Stay::STATUS_COMPLETED)
        ->and($stay->thanks_sent_at)->not->toBeNull();
});

it('el agradecimiento del walk-in con contacto sale directo e incluye el link de reseñas', function () {
    thanksSettings(['review_url' => 'https://g.page/r/demo-hotel/review']);

    $this->mock(DirectGuestMessenger::class, function ($mock) {
        $mock->shouldIgnoreMissing();
        $mock->shouldReceive('sendToGuestFull')
            ->once()
            ->withArgs(fn (Guest $guest, string $subject, string $body) => $subject === 'Gracias por tu visita'
                && str_contains($body, 'https://g.page/r/demo-hotel/review'));
    });

    $guest = Guest::create(['first_name' => 'Rosa', 'phone' => '5599887766']);

    $stay = Stay::create([
        'room_id' => $this->room->id,
        'rate_plan_id' => $this->plan->id,
        'guest_id' => $guest->id,
        'guest_name' => 'Rosa',
        'num_people' => 1,
        'check_in_at' => now()->subHours(3),
        'planned_end_at' => now()->addHours(3),
        'status' => Stay::STATUS_ACTIVE,
        'amount' => 500,
        'channel' => 'walk_in',
    ]);

    app(TransitionReservation::class)->checkOut($stay);

    expect($stay->refresh()->thanks_sent_at)->not->toBeNull();
});
