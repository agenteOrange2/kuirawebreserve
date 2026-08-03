<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\RegisterReservationPayment;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\BookingLookupController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\ReservationPolicy;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1000,
    ]);
});

function hotelPolicy(array $settings): void
{
    $property = Property::firstOrFail();
    $property->update(['settings' => array_merge($property->settings ?? [], $settings)]);
}

function makePaidReservation(int $daysAhead): \App\Models\Reservation
{
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDays($daysAhead)->setTime(15, 0),
        'ends_at' => now()->addDays($daysAhead + 1)->setTime(12, 0),
        'confirmed' => true,
        'guest_name' => 'Laura Cancela',
        'guest_phone' => '5522334455',
    ]);

    app(RegisterReservationPayment::class)->handle($reservation, ['amount' => 400, 'method' => 'transfer']);

    return $reservation->refresh();
}

it('sin política de tarifa ni de hotel no hay reembolso sugerido ni etiqueta', function () {
    $reservation = makePaidReservation(10);

    expect(app(ReservationPolicy::class)->cancellationPolicyLabel($reservation->ratePlan))->toBeNull()
        ->and($reservation->suggestedRefund())->toBeNull();
});

it('la política default del hotel calcula el reembolso: completo dentro de la ventana', function () {
    hotelPolicy([
        'cancel_policy_enabled' => true,
        'cancel_free_value' => 2,
        'cancel_free_unit' => 'day',
        'cancel_penalty_percent' => 100,
    ]);

    // Llega en 10 días, ventana de 2: hoy sigue dentro — se devuelve todo.
    expect(makePaidReservation(10)->suggestedRefund())->toEqual(400.0);
});

it('fuera de la ventana del hotel se aplica la retención configurada', function () {
    hotelPolicy([
        'cancel_policy_enabled' => true,
        'cancel_free_value' => 5,
        'cancel_free_unit' => 'day',
        'cancel_penalty_percent' => 70, // se retiene 70%: se devuelve 30%
    ]);

    // Llega en 2 días con ventana de 5: ya está fuera.
    expect(makePaidReservation(2)->suggestedRefund())->toEqual(120.0);
});

it('la política propia de la tarifa manda sobre la default del hotel', function () {
    hotelPolicy([
        'cancel_policy_enabled' => true,
        'cancel_free_value' => 2,
        'cancel_free_unit' => 'day',
        'cancel_penalty_percent' => 100,
    ]);

    // La tarifa es más estricta: ventana de 15 días y retiene 50%.
    test()->plan->update([
        'cancel_free_unit' => \App\Enums\RateDurationUnit::Day,
        'cancel_free_value' => 15,
        'cancel_penalty_percent' => 50,
    ]);

    // Llega en 10 días: dentro de la ventana del HOTEL pero fuera de la de
    // la tarifa — la tarifa manda: se devuelve el 50%.
    expect(makePaidReservation(10)->suggestedRefund())->toEqual(200.0);
});

it('la etiqueta de la política del hotel se redacta como la de tarifa', function () {
    hotelPolicy([
        'cancel_policy_enabled' => true,
        'cancel_free_value' => 1,
        'cancel_free_unit' => 'day',
        'cancel_penalty_percent' => 100,
    ]);

    expect(app(ReservationPolicy::class)->cancellationPolicyLabel())
        ->toBe('Cancelación sin costo hasta 1 día antes de la llegada; después no hay reembolso.');

    hotelPolicy(['cancel_penalty_percent' => 30]);

    expect(app(ReservationPolicy::class)->cancellationPolicyLabel())
        ->toBe('Cancelación sin costo hasta 1 día antes de la llegada; después se retiene el 30% de lo pagado.');
});

it('el huésped sí puede cancelar con pagos dentro de la ventana default del hotel', function () {
    hotelPolicy([
        'cancel_policy_enabled' => true,
        'cancel_free_value' => 2,
        'cancel_free_unit' => 'day',
        'cancel_penalty_percent' => 100,
    ]);

    $reservation = makePaidReservation(10);

    $response = app(BookingLookupController::class)->cancel(
        Request::create('/api/booking/reservation/cancel', 'POST', [
            'code' => $reservation->code,
            'phone' => '5522334455',
        ]),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);
});

it('fuera de la ventana del hotel el huésped no cancela solo, pero ve la política y la estimación', function () {
    hotelPolicy([
        'cancel_policy_enabled' => true,
        'cancel_free_value' => 5,
        'cancel_free_unit' => 'day',
        'cancel_penalty_percent' => 70,
        'cancel_policy_text' => 'Los reembolsos tardan 7 días hábiles.',
    ]);

    $reservation = makePaidReservation(2);

    $cancel = app(BookingLookupController::class)->cancel(
        Request::create('/api/booking/reservation/cancel', 'POST', [
            'code' => $reservation->code,
            'phone' => '5522334455',
        ]),
    );

    expect($cancel->getStatusCode())->toBe(422)
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Confirmed);

    $find = app(BookingLookupController::class)->find(
        Request::create('/api/booking/reservation', 'GET', [
            'code' => $reservation->code,
            'phone' => '5522334455',
        ]),
    )->getData(true);

    expect($find['can_cancel'])->toBeFalse()
        ->and($find['cancellation_policy'])->toContain('se retiene el 70%')
        ->and($find['cancellation_policy_text'])->toBe('Los reembolsos tardan 7 días hábiles.')
        ->and($find['cancel_refund_estimate'])->toEqual(120.0);
});
