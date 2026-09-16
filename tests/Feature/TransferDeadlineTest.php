<?php

use App\Actions\Payments\IssuePaymentRequest;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\PropertyController;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\ReservationPolicy;
use Illuminate\Http\Request;

// La vigencia del cobro por transferencia manda sobre el apartado: mientras
// el cobro viva, el hold se estira con él (spec-pagos §6.1). El plazo se
// guarda como valor + unidad, y los MINUTOS tienen que poder guardarse:
// cabañas la tenía en 20 minutos desde el levantamiento y la pantalla de
// Plazos no sabía ni mostrar ni guardar esa unidad — el número que regía no
// era editable por nadie, y de 15 cobros por transferencia expiraron 9.
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1000,
    ]);
});

function guardarPlazos(array $settings): \Illuminate\Http\JsonResponse
{
    $request = Request::create('/api/properties/'.test()->property->id, 'PATCH', ['settings' => $settings]);

    return app(PropertyController::class)->update($request, test()->property);
}

function apartadoPendiente(): Reservation
{
    return Reservation::create([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Quien sí quería pagar',
        'num_people' => 2,
        'starts_at' => now()->addDays(10)->setTime(14, 0),
        'ends_at' => now()->addDays(11)->setTime(11, 0),
        'status' => ReservationStatus::Pending,
        'hold_expires_at' => now()->addMinutes(20),
        'total_amount' => 1000,
    ]);
}

it('el plazo de transferencia se puede guardar en minutos', function () {
    guardarPlazos(['transfer_valid_value' => 45, 'transfer_valid_unit' => 'minute']);

    test()->property->refresh();

    expect(test()->property->settings['transfer_valid_unit'])->toBe('minute')
        ->and(app(ReservationPolicy::class)->transferMinutes())->toBe(45);
});

it('el cobro por transferencia estira el apartado hasta su vigencia', function () {
    guardarPlazos(['transfer_valid_value' => 3, 'transfer_valid_unit' => 'hour']);

    $reservation = apartadoPendiente();

    app(IssuePaymentRequest::class)->handle($reservation, PaymentRequest::METHOD_TRANSFER);

    // El apartado nacía con 20 minutos; el cobro vive 3 horas y se lo lleva
    // consigo, que es lo que le da tiempo al huésped de llegar al banco.
    expect(now()->diffInMinutes($reservation->fresh()->hold_expires_at))
        ->toBeGreaterThan(170);
});

it('un plazo de transferencia más corto que el apartado no lo recorta', function () {
    guardarPlazos(['transfer_valid_value' => 5, 'transfer_valid_unit' => 'minute']);

    $reservation = apartadoPendiente();

    app(IssuePaymentRequest::class)->handle($reservation, PaymentRequest::METHOD_TRANSFER);

    // Solo extiende: un cobro corto nunca le quita minutos a un apartado
    // que ya estaba vivo.
    expect(now()->diffInMinutes($reservation->fresh()->hold_expires_at))
        ->toBeGreaterThan(15);
});
