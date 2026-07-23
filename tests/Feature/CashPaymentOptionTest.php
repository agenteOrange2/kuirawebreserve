<?php

use App\Actions\Reservations\CreateReservation;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Payments\PaymentMethodGate;
use App\Services\ReservationPolicy;

// "Pago en el hotel (efectivo)": doble llave — la plataforma permite el
// método (PaymentMethodGate) y el hotel lo activa en /ajustes/metodos-pago
// (settings.cash_payment_enabled). Activo, el paso de pago del wizard
// ofrece también "pagar en el hotel" (payment_optional).
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    Room::factory()->count(2)->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1000,
        'deposit_percent' => 30,
    ]);
});

function holdDelWizard(): array
{
    $request = \Illuminate\Http\Request::create('/api/booking/holds', 'POST', [
        'mode' => 'night',
        'arrive_date' => now()->addDays(10)->toDateString(),
        'depart_date' => now()->addDays(11)->toDateString(),
        'room_type_id' => test()->roomType->id,
        'adults' => 2,
        'guest_name' => 'Elliot Alderson',
        'guest_phone' => '5511112233',
        'website' => '',
        'rendered_at' => now()->subSeconds(10)->toIso8601String(),
    ]);

    return app(\App\Http\Controllers\Tenant\BookingController::class)
        ->holds($request, app(CreateReservation::class))
        ->getData(true);
}

it('sin activarlo, el pago exigido no es opcional (comportamiento de siempre)', function () {
    expect(app(ReservationPolicy::class)->cashPaymentEnabled())->toBeFalse();

    $data = holdDelWizard();
    expect($data['requires_prepayment'])->toBeTrue()
        ->and($data['payment_optional'])->toBeFalse();
});

it('con efectivo activo, el paso de pago ofrece pagar en el hotel', function () {
    $this->property->update(['settings' => ['cash_payment_enabled' => true]]);

    $data = holdDelWizard();
    expect($data['requires_prepayment'])->toBeTrue()
        ->and($data['payment_optional'])->toBeTrue();
});

it('el interruptor de plataforma manda: apagado ahí, el opt-in del hotel no surte efecto', function () {
    $this->property->update(['settings' => ['cash_payment_enabled' => true]]);
    app(PaymentMethodGate::class)->set(null, 'cash', false);

    expect(app(ReservationPolicy::class)->cashPaymentEnabled())->toBeFalse()
        ->and(holdDelWizard()['payment_optional'])->toBeFalse();
});

it('apagarlo explícitamente gana sobre el default legacy del modo "ambos"', function () {
    // Hotel que venía del modo "ambos" pero ya apagó el efectivo nuevo:
    // el paso de pago se sigue mostrando (optional legacy = always), pero
    // sin la opción de pagar en el hotel.
    $this->property->update(['settings' => ['payment_mode' => 'optional', 'cash_payment_enabled' => false]]);

    $data = holdDelWizard();
    expect($data['requires_prepayment'])->toBeTrue()
        ->and($data['payment_optional'])->toBeFalse();
});

// ---- Plazo para pagar en el hotel (reloj propio, default 24 h) ----

it('el plazo de efectivo es perilla propia con default de 24 h', function () {
    expect(app(ReservationPolicy::class)->cashDeadlineMinutes())->toBe(1440);

    $this->property->update(['settings' => ['cash_deadline_value' => 6, 'cash_deadline_unit' => 'hour']]);
    expect(app(ReservationPolicy::class)->cashDeadlineMinutes())->toBe(360);

    $this->property->update(['settings' => ['cash_deadline_value' => 45, 'cash_deadline_unit' => 'minute']]);
    expect(app(ReservationPolicy::class)->cashDeadlineMinutes())->toBe(45);
});

it('elegir pagar en el hotel extiende el apartado al plazo de efectivo', function () {
    $this->property->update(['settings' => ['cash_payment_enabled' => true]]);

    $code = holdDelWizard()['code'];
    $data = app(\App\Http\Controllers\Tenant\BookingController::class)
        ->payLater($code)
        ->getData(true);

    $expiresAt = \Illuminate\Support\Carbon::parse($data['hold_expires_at']);
    expect($expiresAt->gt(now()->addHours(23)))->toBeTrue()
        ->and($expiresAt->lte(now()->addHours(24)))->toBeTrue();

    // La elección queda visible para recepción.
    $reservation = \App\Models\Reservation::query()->latest('id')->first();
    expect($reservation->notes)->toContain('pagar en el hotel');
});

it('el plazo nunca pasa de la hora de llegada', function () {
    $this->property->update(['settings' => ['cash_payment_enabled' => true]]);

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'starts_at' => now()->addHours(2),
        'ends_at' => now()->addHours(26),
        'confirmed' => false,
        'guest_name' => 'Elliot Alderson',
        'guest_phone' => '5511112233',
    ]);

    $data = app(\App\Http\Controllers\Tenant\BookingController::class)
        ->payLater($reservation->code)
        ->getData(true);

    expect(\Illuminate\Support\Carbon::parse($data['hold_expires_at'])->equalTo($reservation->starts_at))->toBeTrue();
});

it('solo extiende, nunca recorta: un plazo de 1 minuto no encoge el hold vivo', function () {
    $this->property->update(['settings' => [
        'cash_payment_enabled' => true,
        'cash_deadline_value' => 1,
        'cash_deadline_unit' => 'minute',
    ]]);

    $code = holdDelWizard()['code'];
    $data = app(\App\Http\Controllers\Tenant\BookingController::class)
        ->payLater($code)
        ->getData(true);

    // El hold de 30 min sigue intacto (extensión menor se ignora).
    expect(\Illuminate\Support\Carbon::parse($data['hold_expires_at'])->gt(now()->addMinutes(25)))->toBeTrue();
});

it('sin efectivo activo el endpoint no extiende nada', function () {
    $code = holdDelWizard()['code'];

    $response = app(\App\Http\Controllers\Tenant\BookingController::class)->payLater($code);
    expect($response->getStatusCode())->toBe(422);
});

it('en grupos extiende todas las reservas del folio', function () {
    $this->property->update(['settings' => ['cash_payment_enabled' => true]]);

    $group = app(\App\Actions\Reservations\CreateGroupReservation::class)->handle([
        'mode' => 'night',
        'starts_at' => now()->addDays(10)->setTime(15, 0),
        'ends_at' => now()->addDays(11)->setTime(12, 0),
        'guest_name' => 'Familia García',
        'guest_phone' => '5544332211',
        'confirmed' => false,
        'lines' => [['room_type_id' => $this->roomType->id, 'rooms' => 2, 'adults' => 2]],
    ]);

    $data = app(\App\Http\Controllers\Tenant\GroupWizardController::class)
        ->payLater($group->code)
        ->getData(true);

    expect(\Illuminate\Support\Carbon::parse($data['hold_expires_at'])->gt(now()->addHours(23)))->toBeTrue();

    $holds = \App\Models\Reservation::query()
        ->where('reservation_group_id', $group->id)
        ->pluck('hold_expires_at');
    expect($holds)->toHaveCount(2)
        ->and($holds->every(fn ($h) => $h->gt(now()->addHours(23))))->toBeTrue();
});
