<?php

use App\Actions\Reservations\CreateReservation;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\BookingLookupController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Sencilla', 'capacity' => 2]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 800,
    ]);

    $this->reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(10)->setTime(15, 0),
        'ends_at' => now()->addDays(11)->setTime(12, 0),
        'confirmed' => true,
        'guest_name' => 'Ana García',
        'guest_phone' => '5511112233',
        'guest_email' => 'ana@example.com',
    ]);
});

function lookupFind(array $params): \Illuminate\Http\JsonResponse
{
    return app(BookingLookupController::class)->find(Request::create('/api/booking/reservation', 'GET', $params));
}

function lookupCancel(array $params): \Illuminate\Http\JsonResponse
{
    return app(BookingLookupController::class)->cancel(Request::create('/api/booking/reservation/cancel', 'POST', $params));
}

it('encuentra la reserva con código y teléfono correctos, aun sin prefijo RES-', function () {
    $response = lookupFind([
        'code' => str_replace('RES-', '', strtolower($this->reservation->code)),
        'phone' => '11112233', // últimos 8 dígitos bastan
    ]);

    expect($response->getStatusCode())->toBe(200);

    $data = $response->getData(true);
    expect($data['code'])->toBe($this->reservation->displayCode())
        ->and($data['room_type'])->toBe('Sencilla')
        ->and($data['total'])->toEqual(800)
        ->and($data['pending_balance'])->toEqual(800)
        ->and($data['can_cancel'])->toBeTrue();
});

it('rechaza con 404 genérico el teléfono equivocado y el código inexistente', function () {
    expect(lookupFind(['code' => $this->reservation->code, 'phone' => '0000000000'])->getStatusCode())->toBe(404)
        ->and(lookupFind(['code' => 'RES-2099-9999', 'phone' => '11112233'])->getStatusCode())->toBe(404);
});

it('el huésped cancela su reserva sin pagos directamente', function () {
    $response = lookupCancel(['code' => $this->reservation->code, 'phone' => '5511112233']);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['status'])->toBe('cancelled')
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);
});

it('con dinero pagado y sin ventana sin costo vigente, la cancelación pide contactar al hotel', function () {
    app(\App\Actions\Reservations\RegisterReservationPayment::class)
        ->handle($this->reservation, ['amount' => 300, 'method' => 'transfer']);

    $response = lookupCancel(['code' => $this->reservation->code, 'phone' => '5511112233']);

    expect($response->getStatusCode())->toBe(422)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Confirmed);
});

it('con dinero pagado pero dentro de la ventana sin costo, sí se puede cancelar', function () {
    $this->plan->update(['cancel_free_unit' => 'day', 'cancel_free_value' => 2]);
    app(\App\Actions\Reservations\RegisterReservationPayment::class)
        ->handle($this->reservation, ['amount' => 300, 'method' => 'transfer']);

    $response = lookupCancel(['code' => $this->reservation->code, 'phone' => '5511112233']);

    expect($response->getStatusCode())->toBe(200)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);
});
