<?php

use App\Actions\Reservations\CreateWalkInStay;
use App\Actions\Reservations\ExtendStay;
use App\Events\RoomStatusChanged;
use App\Exceptions\NoAvailabilityException;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Facades\Event;

/**
 * Tarifas por horas de Hotel México (2026-10-09).
 *
 * - Personas por tarifa: la Sencilla por horas incluye 2 y admite 3 (la
 *   tercera paga lo del cuarto), mientras su Día completo sigue con 1
 *   incluida y máximo 2. La Doble por horas solo admite 2.
 * - Escalera al extender (opt-in por hotel): 1 → 2 → 3 h y luego la noche
 *   completa, cobrando la diferencia; sin el ajuste se multiplica la tarifa
 *   como siempre.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    // Sencilla: de noche 1 incluida, máximo 2, persona extra $80.
    $this->type = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->type->id,
        'max_occupancy' => 2,
        'included_occupancy' => 1,
        'extra_guest_fee' => 80,
    ]);

    $hourly = fn (int $hours, float $price) => RatePlan::factory()->period('hour', $hours, $price)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->type->id,
        'name' => $hours === 1 ? '1 hora' : "{$hours} horas",
        'included_people' => 2,
        'max_people' => 3,
        'online' => false,
    ]);

    $this->h1 = $hourly(1, 290);
    $this->h2 = $hourly(2, 330);
    $this->h3 = $hourly(3, 350);
    $this->night = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->type->id,
        'name' => 'Día completo',
        'price' => 590,
    ]);
});

function hourlyWalkIn(RatePlan $plan, int $people = 1): \App\Models\Stay
{
    return app(CreateWalkInStay::class)->handle([
        'room_id' => test()->room->id,
        'rate_plan_id' => $plan->id,
        'num_people' => $people,
    ]);
}

function ladderOn(): void
{
    test()->property->update(['settings' => [...(test()->property->settings ?? []), 'extension_rate_ladder' => true]]);
}

it('la tarifa por horas trae sus propias personas: 2 incluidas y la tercera paga', function () {
    expect((float) hourlyWalkIn($this->h3, 2)->amount)->toEqual(350.0);
});

it('la tercera persona por horas paga lo del cuarto', function () {
    expect((float) hourlyWalkIn($this->h3, 3)->amount)->toEqual(430.0);
});

it('el día completo sigue con las reglas del cuarto', function () {
    // 1 incluida: la segunda paga $80, como siempre.
    expect((float) hourlyWalkIn($this->night, 2)->amount)->toEqual(670.0);

    expect(fn () => hourlyWalkIn($this->night, 3))->toThrow(NoAvailabilityException::class);
});

it('una tarifa con máximo propio no deja pasar de ahí aunque el cuarto admita más', function () {
    $this->room->update(['max_occupancy' => 4, 'included_occupancy' => 2]);
    $this->h1->update(['max_people' => 2]);

    expect(fn () => hourlyWalkIn($this->h1, 3))->toThrow(NoAvailabilityException::class);
});

it('sin la escalera, extender multiplica la tarifa como siempre', function () {
    $stay = hourlyWalkIn($this->h1);

    app(ExtendStay::class)->handle($stay, $stay->check_in_at->copy()->addHours(2));

    expect($stay->refresh()->rate_plan_id)->toBe($this->h1->id)
        ->and((float) $stay->amount)->toEqual(580.0);
});

it('con la escalera, 1 hora sube a 2 horas y se cobra la diferencia', function () {
    ladderOn();
    $stay = hourlyWalkIn($this->h1);

    $options = app(ExtendStay::class)->ladderOptions($stay->load('ratePlan', 'room'));

    expect(array_column($options, 'name'))->toBe(['2 horas', '3 horas', 'Día completo'])
        ->and($options[0]['difference'])->toEqual(40.0);

    app(ExtendStay::class)->handle($stay, null, null, $this->h2->id);
    $stay->refresh();

    expect($stay->rate_plan_id)->toBe($this->h2->id)
        ->and((float) $stay->amount)->toEqual(330.0)
        ->and($stay->planned_end_at->equalTo($stay->check_in_at->copy()->addHours(2)))->toBeTrue();
});

it('con solo la hora, la escalera elige el escalón que la cubre', function () {
    ladderOn();
    $stay = hourlyWalkIn($this->h1);

    app(ExtendStay::class)->handle($stay, $stay->check_in_at->copy()->addMinutes(150));

    expect($stay->refresh()->rate_plan_id)->toBe($this->h3->id)
        ->and((float) $stay->amount)->toEqual(350.0);
});

it('cumplidas las 3 horas solo queda la noche completa, con su salida y la diferencia', function () {
    ladderOn();
    $stay = hourlyWalkIn($this->h3);

    $options = app(ExtendStay::class)->ladderOptions($stay->load('ratePlan', 'room'));
    expect(array_column($options, 'kind'))->toBe(['night'])
        ->and($options[0]['difference'])->toEqual(240.0);

    app(ExtendStay::class)->handle($stay, null, null, $this->night->id);
    $stay->refresh();

    expect($stay->rate_plan_id)->toBe($this->night->id)
        ->and((float) $stay->amount)->toEqual(590.0)
        ->and($stay->planned_end_at->equalTo($this->night->suggestedEnd($stay->check_in_at->copy())))->toBeTrue();
});

it('pasar a la noche respeta cuántos caben de noche', function () {
    ladderOn();
    $stay = hourlyWalkIn($this->h3, 3);

    $options = app(ExtendStay::class)->ladderOptions($stay->load('ratePlan', 'room'));
    expect($options[0]['fits'])->toBeFalse();

    expect(fn () => app(ExtendStay::class)->handle($stay, null, null, $this->night->id))
        ->toThrow(InvalidArgumentException::class, 'máximo 2');
});

it('extender una estancia que viene de reserva suma lo extendido a su cuenta (todos los hoteles)', function () {
    $reservation = app(\App\Actions\Reservations\CreateReservation::class)->handle([
        'rate_plan_id' => $this->night->id,
        'room_id' => $this->room->id,
        'guest_name' => 'Con reserva',
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDay()->setTime(12, 0),
        'confirmed' => true,
    ]);
    $stay = app(\App\Actions\Reservations\TransitionReservation::class)->checkIn($reservation->refresh());
    $before = $stay->folio()['lodging_pending'];

    app(ExtendStay::class)->handle($stay, $stay->planned_end_at->copy()->addDay());

    expect((float) $reservation->refresh()->total_amount)->toEqual(1180.0)
        ->and($stay->refresh()->folio()['lodging_pending'])->toEqual($before + 590.0);
});

it('el saldo se cobra con el huésped adentro, sin registrar la salida', function () {
    ladderOn();
    $stay = app(CreateWalkInStay::class)->handle([
        'room_id' => $this->room->id,
        'rate_plan_id' => $this->h1->id,
        'payment_method' => 'cash',
    ]);
    app(ExtendStay::class)->handle($stay, null, null, $this->h2->id);

    expect($stay->refresh()->folio()['lodging_pending'])->toEqual(40.0);

    $request = Illuminate\Http\Request::create('/x', 'POST', ['payment_method' => 'cash']);
    $request->setUserResolver(fn () => \App\Models\User::factory()->create());
    $response = app(\App\Http\Controllers\Tenant\StayController::class)->settle($request, $stay, app(\App\Actions\Reservations\SettleStay::class));

    expect($response->getStatusCode())->toBe(200)
        ->and($stay->refresh()->folio()['lodging_pending'])->toEqual(0.0)
        ->and($stay->status)->toBe('active');
});
