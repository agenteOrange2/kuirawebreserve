<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\CreateWalkInStay;
use App\Actions\Reservations\RegisterReservationPayment;
use App\Events\RoomStatusChanged;
use App\Http\Controllers\Tenant\ReservationController;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\ReservationPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '401',
    ]);
    $this->blockPlan = RatePlan::factory()->block(720, 1300)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
});

it('por default el walk-in no cobra al llegar: la cuenta queda para la salida', function () {
    expect(app(ReservationPolicy::class)->walkinChargeOnCheckIn())->toBeFalse();

    $stay = app(CreateWalkInStay::class)->handle([
        'rate_plan_id' => test()->blockPlan->id,
        'room_id' => test()->room->id,
        'guest_name' => 'Paga Al Salir',
    ]);

    expect($stay->payments()->count())->toBe(0)
        ->and($stay->folio()['lodging_pending'])->toEqual((float) $stay->amount);
});

it('con cobro al llegar, el hospedaje queda pagado desde el registro', function () {
    $stay = app(CreateWalkInStay::class)->handle([
        'rate_plan_id' => test()->blockPlan->id,
        'room_id' => test()->room->id,
        'guest_name' => 'Paga Al Llegar',
        'payment_method' => 'card',
        'payment_reference' => 'VOUCHER-77',
    ]);

    $payment = $stay->payments()->first();

    expect($payment)->not->toBeNull()
        ->and($payment->kind)->toBe(Payment::KIND_LODGING)
        ->and($payment->method)->toBe('card')
        ->and($payment->reference)->toBe('VOUCHER-77')
        ->and((float) $payment->amount)->toEqual((float) $stay->amount)
        ->and($stay->folio()['lodging_pending'])->toEqual(0.0);
});

it('el ajuste walkin_charge=checkin se lee desde la política', function () {
    $property = Property::firstOrFail();
    $property->update(['settings' => array_merge($property->settings ?? [], ['walkin_charge' => 'checkin'])]);

    expect(app(ReservationPolicy::class)->walkinChargeOnCheckIn())->toBeTrue();
});

it('el panel rechaza registrar una transferencia directa; efectivo sí pasa', function () {
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->blockPlan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDay()->setTime(23, 0),
        'confirmed' => true,
        'guest_name' => 'Paga Remoto',
    ]);

    $transfer = Request::create('/api/reservations/x/payments', 'POST', [
        'amount' => 200,
        'method' => 'transfer',
    ]);

    expect(fn () => app(ReservationController::class)->registerPayment($transfer, $reservation, app(RegisterReservationPayment::class)))
        ->toThrow(ValidationException::class);

    $cash = Request::create('/api/reservations/x/payments', 'POST', [
        'amount' => 200,
        'method' => 'cash',
    ]);

    $response = app(ReservationController::class)->registerPayment($cash, $reservation, app(RegisterReservationPayment::class));

    expect($response->getStatusCode())->toBe(200)
        ->and($reservation->refresh()->paidTotal())->toEqual(200.0);
});
