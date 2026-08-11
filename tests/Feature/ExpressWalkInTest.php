<?php

use App\Actions\Reservations\CreateWalkInStay;
use App\Events\RoomStatusChanged;
use App\Http\Controllers\Tenant\StayController;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '501',
    ]);
    $this->blockPlan = RatePlan::factory()->block(720, 1300)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
});

function expressStore(array $payload)
{
    $request = Request::create('/api/stays', 'POST', array_merge([
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->blockPlan->id,
    ], $payload));
    $request->setUserResolver(fn () => null);

    return app(StayController::class)->store($request, app(CreateWalkInStay::class));
}

it('registra al huésped a pie con identificación cifrada y cobra en la llegada', function () {
    $response = expressStore([
        'id_document_type' => 'ine',
        'id_document_number' => 'IDMEX-1234567890',
        'payment_method' => 'cash',
    ]);

    expect($response->getStatusCode())->toBe(201);

    $stay = \App\Models\Stay::firstOrFail();

    // El accessor descifra; la columna cruda NO guarda el número en claro.
    $raw = DB::table('stays')->where('id', $stay->id)->value('id_document_number');
    expect($stay->id_document_type)->toBe('ine')
        ->and($stay->id_document_number)->toBe('IDMEX-1234567890')
        ->and($raw)->not->toBe('IDMEX-1234567890');

    $payment = $stay->payments()->first();
    expect($payment->kind)->toBe(Payment::KIND_LODGING)
        ->and((float) $payment->amount)->toEqual((float) $stay->amount);
});

it('con teléfono, el documento y el correo enriquecen la ficha del CRM sin pisarla', function () {
    expressStore([
        'guest_name' => 'Cliente Frecuente',
        'guest_phone' => '6561112233',
        'guest_email' => 'cliente@correo.com',
        'id_document_type' => 'licencia',
        'id_document_number' => 'LIC-777',
        'payment_method' => 'cash',
    ]);

    $guest = Guest::firstOrFail();

    expect($guest->email)->toBe('cliente@correo.com')
        ->and($guest->id_document_type)->toBe('licencia')
        ->and($guest->id_document_number)->toBe('LIC-777');
});

it('no pisa el documento que el CRM ya tenía', function () {
    $guest = Guest::create([
        'first_name' => 'Ya Registrado',
        'phone' => '6560000001',
        'id_document_type' => 'pasaporte',
        'id_document_number' => 'PAS-ORIGINAL',
    ]);

    expressStore([
        'guest_id' => $guest->id,
        'id_document_type' => 'ine',
        'id_document_number' => 'INE-NUEVA',
        'payment_method' => 'cash',
    ]);

    expect($guest->refresh()->id_document_number)->toBe('PAS-ORIGINAL')
        ->and($guest->id_document_type)->toBe('pasaporte');
});

it('una tarifa de otro tipo de habitación responde 422 con mensaje, no 404', function () {
    $otherType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 4]);
    $foreignPlan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $otherType->id,
    ]);

    $response = expressStore(['rate_plan_id' => $foreignPlan->id]);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['message'])->toContain('tipo de esta habitación');
});

it('respeta la capacidad real de la habitación', function () {
    $response = expressStore(['num_people' => 5, 'payment_method' => 'cash']);

    // El tipo admite 2: el walk-in ya no puede meter 5 (antes sí podía).
    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['message'])->toContain('admite hasta 2');
});

it('el huésped a pie queda registrado con su nombre completo', function () {
    expressStore([
        'guest_name' => 'Pedro Paramo Vega',
        'id_document_type' => 'ine',
        'id_document_number' => 'INE-990',
        'payment_method' => 'cash',
    ]);

    $stay = \App\Models\Stay::firstOrFail();

    expect($stay->guest_name)->toBe('Pedro Paramo Vega')
        ->and($stay->guest_id)->toBeNull();
});

it('el registro con placa sigue siendo válido sin ningún dato de contacto', function () {
    $response = expressStore([
        'vehicle_plate' => 'ABC-123-D',
        'vehicle_desc' => 'Sedán gris',
        'payment_method' => 'card',
        'payment_reference' => 'TPV-9',
    ]);

    expect($response->getStatusCode())->toBe(201);

    $stay = \App\Models\Stay::firstOrFail();
    expect($stay->vehicle_plate)->toBe('ABC-123-D')
        ->and($stay->guest_id)->toBeNull()
        ->and($stay->payments()->where('kind', Payment::KIND_LODGING)->exists())->toBeTrue();
});
