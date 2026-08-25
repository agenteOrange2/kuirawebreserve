<?php

use App\Enums\RoomStatus;
use App\Events\RoomStatusChanged;
use App\Http\Controllers\Tenant\RoomCleaningController;
use App\Models\Housekeeper;
use App\Models\Incident;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCleaning;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

/**
 * Registro del trabajo de limpieza: el módulo que le pone nombre y tiempo a
 * lo que antes solo era un cambio de color en el plano.
 */
function bindCleaningTenant(bool $withIncidents = true): Tenant
{
    $tenant = new Tenant;
    $tenant->id = 'hotel-limpieza-test';
    $tenant->plan = 'basic';

    if (! $withIncidents) {
        \App\Models\Central\TenantModule::create([
            'tenant_id' => $tenant->id,
            'module' => 'incidencias',
            'enabled' => false,
        ]);
    }

    app()->instance(\Stancl\Tenancy\Contracts\Tenant::class, $tenant);
    app()->instance(Tenant::class, $tenant);

    return $tenant;
}

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
        'status' => RoomStatus::Dirty->value,
    ]);
    $this->marta = Housekeeper::create(['name' => 'Marta Chávez', 'active' => true]);
    $this->user = User::factory()->create();
});

function cleaningRequest(array $payload): Request
{
    return tap(Request::create('/x', 'POST', $payload), fn (Request $r) => $r->setUserResolver(fn () => test()->user));
}

it('iniciar limpieza manda la habitación a "en limpieza" y arranca el cronómetro', function () {
    bindCleaningTenant();

    $response = app(RoomCleaningController::class)->store(
        cleaningRequest(['housekeeper_id' => $this->marta->id, 'kind' => RoomCleaning::KIND_CHECKOUT]),
        $this->room,
        app(\App\Actions\Rooms\ChangeRoomStatus::class),
    );

    $cleaning = RoomCleaning::first();

    expect($response->getStatusCode())->toBe(201)
        ->and($this->room->fresh()->status->getMorphClass())->toBe(RoomStatus::Cleaning->value)
        ->and($cleaning->housekeeper_id)->toBe($this->marta->id)
        ->and($cleaning->isOpen())->toBeTrue()
        ->and($cleaning->source)->toBe(RoomCleaning::SOURCE_FLOORPLAN);
});

it('no deja abrir dos limpiezas en la misma habitación', function () {
    bindCleaningTenant();

    $abrir = fn () => app(RoomCleaningController::class)->store(
        cleaningRequest(['housekeeper_id' => $this->marta->id]),
        $this->room->fresh(),
        app(\App\Actions\Rooms\ChangeRoomStatus::class),
    );

    $abrir();
    $segunda = $abrir();

    expect($segunda->getStatusCode())->toBe(422)
        ->and($segunda->getData(true)['message'])->toContain('ya tiene una limpieza en curso')
        ->and(RoomCleaning::count())->toBe(1);
});

it('cerrar sella los minutos, guarda checklist y ropa, y libera la habitación', function () {
    bindCleaningTenant();

    $cleaning = RoomCleaning::create([
        'room_id' => $this->room->id,
        'housekeeper_id' => $this->marta->id,
        'started_at' => now()->subMinutes(25),
        'source' => RoomCleaning::SOURCE_FLOORPLAN,
    ]);
    $this->room->forceFill(['status' => RoomStatus::Cleaning->value])->saveQuietly();

    app(RoomCleaningController::class)->update(
        cleaningRequest([
            'checklist' => ['sabanas', 'bano', 'inventado'],
            'linens' => ['sabanas' => 2, 'toallas' => 0, 'ajeno' => 5],
            'notes' => 'Sin novedad',
        ]),
        $cleaning,
        app(\App\Actions\Rooms\ChangeRoomStatus::class),
    );

    $cleaning->refresh();

    expect($cleaning->minutes)->toBe(25)
        ->and($cleaning->isOpen())->toBeFalse()
        // Las llaves que no están configuradas se descartan, y "0 toallas"
        // no se guarda: ensuciaría el consumo del reporte.
        ->and($cleaning->checklist)->toBe(['sabanas', 'bano'])
        ->and($cleaning->linens)->toBe(['sabanas' => 2])
        ->and($this->room->fresh()->status->getMorphClass())->toBe(RoomStatus::Available->value);
});

it('un desperfecto encontrado al limpiar levanta la incidencia', function () {
    bindCleaningTenant();

    $cleaning = RoomCleaning::create([
        'room_id' => $this->room->id,
        'housekeeper_id' => $this->marta->id,
        'started_at' => now()->subMinutes(10),
    ]);
    $this->room->forceFill(['status' => RoomStatus::Cleaning->value])->saveQuietly();

    app(RoomCleaningController::class)->update(
        cleaningRequest([
            'incident_title' => 'Regadera goteando',
            'incident_priority' => 'high',
            'set_maintenance' => true,
        ]),
        $cleaning,
        app(\App\Actions\Rooms\ChangeRoomStatus::class),
    );

    $incident = Incident::first();

    expect($incident)->not->toBeNull()
        ->and($incident->room_id)->toBe($this->room->id)
        ->and($incident->category)->toBe('limpieza')
        ->and($incident->description)->toContain('Marta Chávez')
        ->and($cleaning->fresh()->incident_id)->toBe($incident->id)
        // Con la habitación fuera de servicio NO se libera encima.
        ->and($this->room->fresh()->status->getMorphClass())->toBe(RoomStatus::Maintenance->value);
});

it('sin el módulo de incidencias el desperfecto no truena, solo no crea ticket', function () {
    bindCleaningTenant(withIncidents: false);

    $cleaning = RoomCleaning::create([
        'room_id' => $this->room->id,
        'housekeeper_id' => $this->marta->id,
        'started_at' => now()->subMinutes(5),
    ]);
    $this->room->forceFill(['status' => RoomStatus::Cleaning->value])->saveQuietly();

    app(RoomCleaningController::class)->update(
        cleaningRequest(['incident_title' => 'Foco fundido']),
        $cleaning,
        app(\App\Actions\Rooms\ChangeRoomStatus::class),
    );

    expect(Incident::count())->toBe(0)
        ->and($cleaning->fresh()->isOpen())->toBeFalse();
});

it('la captura manual asienta lo que ya pasó sin mover el semáforo', function () {
    bindCleaningTenant();

    $response = app(RoomCleaningController::class)->storeManual(cleaningRequest([
        'room_id' => $this->room->id,
        'housekeeper_id' => $this->marta->id,
        'started_at' => now()->subHours(3)->toDateTimeString(),
        'ended_at' => now()->subHours(3)->addMinutes(40)->toDateTimeString(),
    ]));

    $cleaning = RoomCleaning::first();

    expect($response->getStatusCode())->toBe(201)
        ->and($cleaning->minutes)->toBe(40)
        ->and($cleaning->source)->toBe(RoomCleaning::SOURCE_MANUAL)
        // La habitación sigue sucia: la captura no toca el estado.
        ->and($this->room->fresh()->status->getMorphClass())->toBe(RoomStatus::Dirty->value);
});

it('rechaza una captura con la entrada en el futuro', function () {
    bindCleaningTenant();

    $response = app(RoomCleaningController::class)->storeManual(cleaningRequest([
        'room_id' => $this->room->id,
        'housekeeper_id' => $this->marta->id,
        'started_at' => now()->addHour()->toDateTimeString(),
        'ended_at' => now()->addHours(2)->toDateTimeString(),
    ]));

    expect($response->getStatusCode())->toBe(422)
        ->and(RoomCleaning::count())->toBe(0);
});

it('el reloj automático NO inventa registros de limpieza', function () {
    bindCleaningTenant();

    // El comando mueve el semáforo sin que nadie capture: la habitación
    // queda limpia pero sin dueño, y eso debe verse como "sin registrar"
    // en vez de aparecer como trabajo de alguien.
    app(\App\Actions\Rooms\ChangeRoomStatus::class)->handle($this->room, RoomStatus::Cleaning->value, null, ['auto' => true]);
    app(\App\Actions\Rooms\ChangeRoomStatus::class)->handle($this->room->fresh(), RoomStatus::Available->value, null, ['auto' => true]);

    expect(RoomCleaning::count())->toBe(0);
});

it('el panel carga con habitaciones que ya tienen historial de semáforo', function () {
    bindCleaningTenant();

    // Bug real (cabanasrealdelasierra, 2026-08-21): pedir columnas sueltas
    // en latestStatusLog dejaba `room_id` ambiguo contra el subquery del
    // latestOfMany y la pantalla tronaba con SQL 1052.
    $this->room->statusLogs()->create([
        'from_status' => \App\Enums\RoomStatus::Occupied->value,
        'to_status' => \App\Enums\RoomStatus::Dirty->value,
    ]);

    $otra = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '102',
        'status' => \App\Enums\RoomStatus::Dirty->value,
    ]);
    $otra->statusLogs()->create([
        'from_status' => \App\Enums\RoomStatus::Occupied->value,
        'to_status' => \App\Enums\RoomStatus::Dirty->value,
    ]);

    $response = app(\App\Http\Controllers\Tenant\HousekeepingPageController::class)->index();
    $props = (new ReflectionObject($response))->getProperty('props');
    $props->setAccessible(true);
    $rooms = $props->getValue($response)['rooms'];

    expect($rooms)->toHaveCount(2)
        // Y el dato que se pedía con esas columnas sigue llegando.
        ->and($rooms->firstWhere('number', '101')['since_minutes'])->not->toBeNull();
});

it('dar de baja a una camarista con historial la archiva en vez de borrarla', function () {
    bindCleaningTenant();

    RoomCleaning::create([
        'room_id' => $this->room->id,
        'housekeeper_id' => $this->marta->id,
        'started_at' => now()->subHour(),
    ]);

    app(\App\Http\Controllers\Tenant\HousekeeperController::class)->destroy($this->marta);

    expect(Housekeeper::count())->toBe(1)
        ->and($this->marta->fresh()->active)->toBeFalse();
});
