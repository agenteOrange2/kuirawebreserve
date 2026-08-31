<?php

use App\Actions\Reservations\CreateWalkInStay;
use App\Events\RoomStatusChanged;
use App\Http\Controllers\Tenant\StayController;
use App\Models\Guest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\Vehicle;
use App\Services\VehicleRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '701',
    ]);
    $this->plan = RatePlan::factory()->block(720, 1300)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
});

function registerPlate(array $payload)
{
    $request = Request::create('/api/stays', 'POST', array_merge([
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
    ], $payload));
    $request->setUserResolver(fn () => null);

    return app(StayController::class)->store($request, app(CreateWalkInStay::class));
}

it('normaliza la placa: da igual cómo la teclee el cajero', function () {
    expect(Vehicle::normalizePlate('abc-123-d'))->toBe('ABC123D')
        ->and(Vehicle::normalizePlate('ABC 123 D'))->toBe('ABC123D')
        ->and(Vehicle::normalizePlate('  abc123d '))->toBe('ABC123D');
});

it('descarta lo que no puede ser una placa en vez de crear fichas basura', function () {
    // Menos de 4 caracteres útiles: "N/A", "-", "SIN", "ABC" del histórico real.
    expect(Vehicle::normalizePlate('N/A'))->toBeNull()
        ->and(Vehicle::normalizePlate('-'))->toBeNull()
        ->and(Vehicle::normalizePlate('ABC'))->toBeNull()
        ->and(Vehicle::normalizePlate(null))->toBeNull();
});

it('el registro exprés crea la ficha del vehículo y liga la estancia', function () {
    registerPlate([
        'vehicle_plate' => 'xyz-987-b',
        'vehicle_brand' => 'Nissan',
        'vehicle_model' => 'Versa',
        'vehicle_color' => 'Gris',
        'payment_method' => 'cash',
    ]);

    $vehicle = Vehicle::firstOrFail();
    $stay = Stay::firstOrFail();

    expect(Vehicle::count())->toBe(1)
        ->and($vehicle->plate_normalized)->toBe('XYZ987B')
        // Se muestra en mayúsculas pero conserva los guiones tecleados.
        ->and($vehicle->plate)->toBe('XYZ-987-B')
        ->and($vehicle->label())->toBe('Nissan Versa Gris')
        ->and($stay->vehicle_id)->toBe($vehicle->id)
        // El sello histórico de la estancia se conserva aparte de la ficha.
        ->and($stay->vehicle_plate)->toBe('XYZ-987-B');
});

it('la segunda visita reusa la ficha y suma historial', function () {
    registerPlate(['vehicle_plate' => 'XYZ-987-B', 'vehicle_brand' => 'Nissan']);

    // El mismo carro vuelve otro día, normalmente a otro cuarto (el primero
    // quedó ocupado y su estado no salta directo a disponible: pasa por
    // limpieza, como en la vida real).
    $otherRoom = Room::factory()->create([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
        'number' => '702',
    ]);

    $request = Request::create('/api/stays', 'POST', [
        'room_id' => $otherRoom->id,
        'rate_plan_id' => test()->plan->id,
        'vehicle_plate' => 'xyz 987 b',
    ]);
    $request->setUserResolver(fn () => null);
    $second = app(StayController::class)->store($request, app(CreateWalkInStay::class));

    expect($second->getStatusCode())->toBe(201)
        ->and(Vehicle::count())->toBe(1)
        ->and(Vehicle::first()->stays()->count())->toBe(2);
});

it('enriquece la ficha sin pisar lo ya capturado', function () {
    $registry = app(VehicleRegistry::class);

    $registry->resolve([
        'vehicle_plate' => 'ABC-1234',
        'vehicle_brand' => 'Nissan',
        'vehicle_model' => 'Versa',
    ]);

    // Una visita posterior manda otra marca: la primera captura manda, pero
    // el color que faltaba sí se rellena.
    $registry->resolve([
        'vehicle_plate' => 'ABC-1234',
        'vehicle_brand' => 'Chevrolet',
        'vehicle_color' => 'Rojo',
    ]);

    $vehicle = Vehicle::firstOrFail();

    expect($vehicle->brand)->toBe('Nissan')
        ->and($vehicle->model)->toBe('Versa')
        ->and($vehicle->color)->toBe('Rojo');
});

it('una placa inválida no crea ficha y la estancia queda sin vínculo', function () {
    registerPlate(['vehicle_plate' => 'N/A']);

    expect(Vehicle::count())->toBe(0)
        ->and(Stay::firstOrFail()->vehicle_id)->toBeNull();
});

it('la placa corta no tira marca, modelo y color: quedan en la ficha CRM del huésped', function () {
    registerPlate([
        'guest_name' => 'Mauricio Belmont',
        'guest_phone' => '7775502704',
        'vehicle_plate' => 'ABC',
        'vehicle_brand' => 'Nissan',
        'vehicle_model' => 'Versa',
        'vehicle_color' => 'Blanco',
    ]);

    $guest = Guest::firstOrFail();

    // Sin ficha en el registro (la placa no alcanza), pero lo estructurado
    // aparece en los campos del editor del huésped, no perdido en una línea.
    expect(Vehicle::count())->toBe(0)
        ->and($guest->vehicle())->toMatchArray([
            'plate' => 'ABC',
            'brand' => 'Nissan',
            'model' => 'Versa',
            'color' => 'Blanco',
        ]);
});

it('el mostrador no pisa el vehículo que el CRM ya tiene capturado', function () {
    $guest = Guest::create([
        'first_name' => 'Mauricio',
        'phone' => '7775502704',
        'meta' => ['vehicle' => ['plate' => 'XYZ-987-B', 'brand' => 'Chevrolet']],
    ]);

    registerPlate([
        'guest_id' => $guest->id,
        'vehicle_plate' => 'XYZ-987-B',
        'vehicle_brand' => 'Nissan',
        'vehicle_color' => 'Rojo',
    ]);

    // La primera captura manda; solo el hueco (color) se rellena.
    expect($guest->fresh()->vehicle())->toMatchArray([
        'plate' => 'XYZ-987-B',
        'brand' => 'Chevrolet',
        'color' => 'Rojo',
    ]);
});

it('el backfill levanta el registro con lo ya capturado, sin duplicar', function () {
    // Estancias viejas con placa suelta, como las que existen en producción.
    Stay::create([
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Cliente viejo',
        'num_people' => 1,
        'vehicle_plate' => 'DEF-555-C',
        'vehicle_desc' => 'Sedán gris',
        'check_in_at' => now()->subDays(3),
        'planned_end_at' => now()->subDays(3)->addHours(12),
        'status' => Stay::STATUS_COMPLETED,
        'amount' => 650,
        'channel' => 'walk_in',
    ]);
    Stay::create([
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Mismo carro',
        'num_people' => 1,
        'vehicle_plate' => 'def555c',
        'check_in_at' => now()->subDay(),
        'planned_end_at' => now()->subDay()->addHours(12),
        'status' => Stay::STATUS_COMPLETED,
        'amount' => 650,
        'channel' => 'walk_in',
    ]);
    // Y un vehículo del CRM, que sí viene estructurado.
    Guest::create([
        'first_name' => 'Ana',
        'phone' => '6141112233',
        'meta' => ['vehicle' => ['plate' => 'GHI-777', 'brand' => 'Mazda', 'model' => '3']],
    ]);

    $created = app(VehicleRegistry::class)->backfill();

    expect($created)->toBe(2)
        ->and(Vehicle::count())->toBe(2)
        // Las dos estancias de la misma placa cuelgan de una sola ficha.
        ->and(Vehicle::where('plate_normalized', 'DEF555C')->firstOrFail()->stays()->count())->toBe(2)
        // La descripción libre se conserva como nota, sin intentar partirla.
        ->and(Vehicle::where('plate_normalized', 'DEF555C')->firstOrFail()->notes)
        ->toContain('Sedán gris')
        ->and(Vehicle::where('plate_normalized', 'GHI777')->firstOrFail()->label())
        ->toBe('Mazda 3');
});

it('reservar desde el plano guarda la ficha del carro con marca, modelo y color', function () {
    $request = Request::create('/api/reservations', 'POST', [
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDay()->setTime(15, 0)->format('Y-m-d H:i'),
        'ends_at' => now()->addDay()->setTime(23, 0)->format('Y-m-d H:i'),
        'confirmed' => true,
        'guest_name' => 'Reserva Con Carro',
        'guest_phone' => '6149998877',
        'vehicle_plate' => 'JKL-456-M',
        'vehicle_desc' => 'Nissan Versa · blanco',
        'vehicle_brand' => 'Nissan',
        'vehicle_model' => 'Versa',
        'vehicle_color' => 'blanco',
    ]);
    $request->setUserResolver(fn () => null);

    app(\App\Http\Controllers\Tenant\ReservationController::class)
        ->store($request, app(\App\Actions\Reservations\CreateReservation::class));

    $vehicle = Vehicle::where('plate_normalized', 'JKL456M')->firstOrFail();

    // La reserva guarda placa y descripción; lo estructurado, la ficha.
    expect($vehicle->brand)->toBe('Nissan')
        ->and($vehicle->model)->toBe('Versa')
        ->and($vehicle->color)->toBe('blanco')
        // Y queda ligada al huésped que reservó, para reconocerlo al llegar.
        ->and($vehicle->guest_id)->not->toBeNull();
});
