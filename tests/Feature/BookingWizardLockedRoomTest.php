<?php

use App\Http\Controllers\Tenant\BookingWizardController;
use App\Http\Controllers\Tenant\WidgetScriptController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();

    // Cabaña que se vende SOLO por bloque y otra SOLO por noche: sirven
    // para comprobar que el embed acotado detecta las modalidades de SU
    // habitación, no las del catálogo entero.
    $this->cabana = RoomType::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Cabaña Luxury',
    ]);
    Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->cabana->id]);
    RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->cabana->id,
    ]);

    $this->otra = RoomType::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Cabaña Real',
    ]);
    Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->otra->id]);
    RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->otra->id,
        'type' => 'night',
        'active' => true,
    ]);
});

function wizardProps(array $query = []): array
{
    $request = Request::create('/reservar', 'GET', $query);
    $request->headers->set('X-Inertia', 'true');

    return app(BookingWizardController::class)($request)
        ->toResponse($request)
        ->getData(true)['props'];
}

it('sin parámetro, el wizard sigue siendo el completo', function () {
    $props = wizardProps();

    expect($props['lockedRoomType'])->toBeNull()
        ->and($props['hasNightRates'])->toBeTrue()
        ->and($props['hasBlockRates'])->toBeTrue();
});

it('con ?habitacion queda acotado a esa habitación', function () {
    $props = wizardProps(['habitacion' => $this->cabana->id]);

    expect($props['lockedRoomType'])->toBe([
        'id' => $this->cabana->id,
        'name' => 'Cabaña Luxury',
    ]);
});

it('las modalidades se detectan de las tarifas de ESA habitación', function () {
    // La cabaña bloqueada solo tiene tarifa por bloque: su embed no debe
    // ofrecer "por noche" aunque otra cabaña del hotel sí la venda.
    $props = wizardProps(['habitacion' => $this->cabana->id]);

    expect($props['hasBlockRates'])->toBeTrue()
        ->and($props['hasNightRates'])->toBeFalse();

    $props = wizardProps(['habitacion' => $this->otra->id]);

    expect($props['hasNightRates'])->toBeTrue()
        ->and($props['hasBlockRates'])->toBeFalse();
});

it('una habitación inactiva o inexistente cae al wizard completo, no rompe la página del hotel', function () {
    $this->cabana->update(['active' => false]);

    expect(wizardProps(['habitacion' => $this->cabana->id])['lockedRoomType'])->toBeNull()
        ->and(wizardProps(['habitacion' => 999999])['lockedRoomType'])->toBeNull()
        ->and(wizardProps(['habitacion' => 'no-es-un-id'])['lockedRoomType'])->toBeNull();
});

it('el loader de widgets pasa la habitación fija al iframe', function () {
    $js = app(WidgetScriptController::class)(Request::create('/widget.js'))->getContent();

    expect($js)->toContain('data-kuira-room')
        ->and($js)->toContain("'&habitacion=' + room");
});
