<?php

use App\Actions\Payments\IssueGroupPayment;
use App\Actions\Payments\IssuePaymentRequest;
use App\Actions\Reservations\CreateGroupReservation;
use App\Actions\Reservations\CreateReservation;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    Room::factory()->count(3)->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    // El anticipo viene SOLO de la tarifa ("Exigir cobro anticipado" en
    // Catálogo) — no existe ningún porcentaje global del hotel.
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1000,
        'deposit_percent' => 30,
    ]);
});

function reservaConAnticipo(): \App\Models\Reservation
{
    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'starts_at' => now()->addDays(10)->setTime(15, 0),
        'ends_at' => now()->addDays(11)->setTime(12, 0),
        'confirmed' => false,
        'guest_name' => 'Elliot Alderson',
        'guest_phone' => '5511112233',
    ]);
}

it('sin anticipo en la tarifa la reserva no trae anticipo (no hay default global)', function () {
    $this->plan->update(['deposit_percent' => null]);

    expect((float) reservaConAnticipo()->deposit_amount)->toBe(0.0);
});

it('el cobro pide el anticipo de la tarifa, pero el huésped puede elegir pagar todo', function () {
    $reservation = reservaConAnticipo();
    expect((float) $reservation->deposit_amount)->toBe(300.0); // 30% de la tarifa

    $deposit = app(IssuePaymentRequest::class)->handle($reservation);
    expect((float) $deposit->amount)->toBe(300.0)
        ->and($deposit->concept)->toBe(PaymentRequest::CONCEPT_DEPOSIT);

    // Elegir "todo de una vez" cancela el cobro del anticipo y emite el total.
    $full = app(IssuePaymentRequest::class)->handle($reservation->fresh(), preferFull: true);
    expect((float) $full->amount)->toBe(1000.0)
        ->and($full->concept)->toBe(PaymentRequest::CONCEPT_FULL)
        ->and($deposit->fresh()->status)->toBe(PaymentRequest::STATUS_CANCELED);
});

it('el grupo también: anticipos de tarifa o todo de una vez a elección', function () {
    $group = app(CreateGroupReservation::class)->handle([
        'mode' => 'night',
        'starts_at' => now()->addDays(10)->setTime(15, 0),
        'ends_at' => now()->addDays(11)->setTime(12, 0),
        'guest_name' => 'Familia García',
        'guest_phone' => '5544332211',
        'confirmed' => false,
        'lines' => [['room_type_id' => $this->roomType->id, 'rooms' => 2, 'adults' => 2]],
    ]);

    // 2 cuartos x 300 de anticipo (30% de la tarifa).
    $deposit = app(IssueGroupPayment::class)->handle($group);
    expect((float) $deposit->amount)->toBe(600.0);

    // Todo de una vez: 2 x 1000.
    $full = app(IssueGroupPayment::class)->handle($group, preferFull: true);
    expect((float) $full->amount)->toBe(2000.0)
        ->and($deposit->fresh()->status)->toBe(PaymentRequest::STATUS_CANCELED);
});

it('modo "ambos" legacy: sigue activando el pago opcional (hoy es efectivo por default)', function () {
    $this->property->update(['settings' => ['payment_mode' => 'optional']]);
    $this->plan->update(['deposit_percent' => null]);

    $request = \Illuminate\Http\Request::create('/api/booking/holds', 'POST', [
        'mode' => 'night',
        'arrive_date' => now()->addDays(10)->toDateString(),
        'depart_date' => now()->addDays(11)->toDateString(),
        'room_type_id' => $this->roomType->id,
        'adults' => 2,
        'guest_name' => 'Elliot Alderson',
        'guest_phone' => '5511112233',
        'website' => '',
        'rendered_at' => now()->subSeconds(10)->toIso8601String(),
    ]);

    $data = app(\App\Http\Controllers\Tenant\BookingController::class)
        ->holds($request, app(CreateReservation::class))
        ->getData(true);

    expect($data['requires_prepayment'])->toBeTrue()
        ->and($data['payment_optional'])->toBeTrue();
});
