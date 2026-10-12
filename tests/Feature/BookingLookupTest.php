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

it('pide el teléfono completo: los últimos 4 dígitos ya no abren la reserva', function () {
    expect(lookupFind(['code' => $this->reservation->code, 'phone' => '2233'])->getStatusCode())->toBe(404)
        ->and(lookupFind(['code' => $this->reservation->code, 'phone' => '+52 55 1111 2233'])->getStatusCode())->toBe(200);
});

function lookupProof(array $params, ?\Illuminate\Http\UploadedFile $file): \Illuminate\Http\JsonResponse
{
    $request = Request::create('/api/booking/reservation/proof', 'POST', $params, [], $file ? ['file' => $file] : []);

    return app(BookingLookupController::class)->proof($request);
}

it('el huésped sube su comprobante: queda pegado al cobro por transferencia y suena la campana', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    $this->property->update(['settings' => array_replace($this->property->settings ?? [], [
        'bank_accounts' => [['bank' => 'BBVA', 'holder' => 'Hotel', 'clabe' => '012345678901234567', 'active' => true]],
    ])]);

    $before = lookupFind(['code' => $this->reservation->code, 'phone' => '5511112233'])->getData(true);
    expect($before['proof']['can_send'])->toBeTrue()
        ->and($before['proof']['received_at'])->toBeNull();

    $response = lookupProof(
        ['code' => $this->reservation->code, 'phone' => '5511112233'],
        \Illuminate\Http\UploadedFile::fake()->image('comprobante.jpg', 600, 900),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['proof']['received_at'])->not->toBeNull();

    $request = $this->reservation->paymentRequests()
        ->where('method', \App\Models\PaymentRequest::METHOD_TRANSFER)
        ->where('status', \App\Models\PaymentRequest::STATUS_PENDING)
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->getFirstMedia('receipt'))->not->toBeNull()
        ->and(\App\Models\StaffNotification::query()->where('title', 'Comprobante recibido en línea')->exists())->toBeTrue()
        // No da nada por pagado: el hotel lo aprueba en Pagos.
        ->and($this->reservation->refresh()->paidTotal())->toEqual(0);
});

it('sin saldo pendiente no acepta comprobante', function () {
    $this->property->update(['settings' => array_replace($this->property->settings ?? [], [
        'bank_accounts' => [['bank' => 'BBVA', 'holder' => 'Hotel', 'clabe' => '012345678901234567', 'active' => true]],
    ])]);
    app(\App\Actions\Reservations\RegisterReservationPayment::class)
        ->handle($this->reservation, ['amount' => 800, 'method' => 'cash']);

    $response = lookupProof(
        ['code' => $this->reservation->code, 'phone' => '5511112233'],
        \Illuminate\Http\UploadedFile::fake()->image('comprobante.jpg'),
    );

    expect($response->getStatusCode())->toBe(422)
        ->and(lookupFind(['code' => $this->reservation->code, 'phone' => '5511112233'])->getData(true)['payments'])->toHaveCount(1);
});
