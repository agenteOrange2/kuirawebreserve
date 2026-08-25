<?php

use App\Events\RoomStatusChanged;
use App\Http\Controllers\Tenant\IncidentController;
use App\Models\Incident;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\StaffNotification;
use App\Models\User;
use App\Services\IncidentPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

/**
 * Lo que le faltaba al módulo: que una falla abierta le pese a alguien.
 *
 * Caso real que lo motivó (motellacupula, 2026-08-21): fuga de agua de
 * prioridad alta con 18 días abierta, la habitación vendiéndose y cero
 * avisos.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
        'status' => 'available',
    ]);
    $this->user = User::factory()->create();
});

function overdueRequest(array $data, string $method = 'POST'): Request
{
    return tap(
        Request::create('/x', $method, $data),
        fn (Request $r) => $r->setUserResolver(fn () => test()->user),
    );
}

function openIncident(array $attributes = []): Incident
{
    return Incident::create([
        'room_id' => test()->room->id,
        'title' => 'Fuga en la regadera',
        'priority' => 'high',
        'status' => Incident::STATUS_OPEN,
        ...$attributes,
    ]);
}

it('una incidencia nace con su plazo según la prioridad', function () {
    $alta = openIncident(['priority' => 'high']);
    $baja = openIncident(['priority' => 'low', 'title' => 'Foco fundido']);

    // 4 h para alta, 72 h para baja (valores por defecto).
    expect((int) $alta->created_at->diffInHours($alta->due_at))->toBe(4)
        ->and((int) $baja->created_at->diffInHours($baja->due_at))->toBe(72);
});

it('subir la prioridad adelanta el plazo en vez de arrastrar el viejo', function () {
    $incident = openIncident(['priority' => 'low']);
    $plazoViejo = $incident->due_at;

    $incident->update(['priority' => 'high']);
    $incident->refresh();

    expect($incident->due_at->lt($plazoViejo))->toBeTrue()
        ->and((int) $incident->created_at->diffInHours($incident->due_at))->toBe(4);
});

it('una falla de prioridad alta avisa a la campana al abrirse', function () {
    app(IncidentController::class)->store(
        overdueRequest([
            'room_id' => $this->room->id,
            'title' => 'Fuga en la regadera',
            'priority' => 'high',
        ]),
        app(\App\Actions\Rooms\ChangeRoomStatus::class),
    );

    $aviso = StaffNotification::where('type', StaffNotification::TYPE_INCIDENT)->first();

    expect($aviso)->not->toBeNull()
        ->and($aviso->title)->toBe('Falla de prioridad alta')
        ->and($aviso->body)->toContain('Habitación 101');
});

it('una falla de prioridad media NO satura la campana al abrirse', function () {
    app(IncidentController::class)->store(
        overdueRequest([
            'room_id' => $this->room->id,
            'title' => 'Foco fundido',
            'priority' => 'medium',
        ]),
        app(\App\Actions\Rooms\ChangeRoomStatus::class),
    );

    expect(StaffNotification::where('type', StaffNotification::TYPE_INCIDENT)->count())->toBe(0);
});

it('el comando avisa de las vencidas y no repite en la siguiente corrida', function () {
    $incident = openIncident();
    // Se venció hace rato: el caso de la fuga de dos semanas.
    $incident->forceFill([
        'created_at' => now()->subDays(18),
        'due_at' => now()->subDays(18)->addHours(4),
    ])->saveQuietly();

    $this->artisan('incidents:check-overdue');

    $avisos = StaffNotification::where('type', StaffNotification::TYPE_INCIDENT)->get();

    expect($avisos)->toHaveCount(1)
        ->and($avisos->first()->title)->toBe('Falla sin atender')
        ->and($avisos->first()->body)->toContain('18 días')
        ->and($incident->fresh()->overdue_notified_at)->not->toBeNull();

    // Segunda corrida: el comando pasa cada 15 minutos y una campana
    // repetida se vuelve ruido que se ignora.
    $this->artisan('incidents:check-overdue');

    expect(StaffNotification::where('type', StaffNotification::TYPE_INCIDENT)->count())->toBe(1);
});

it('una incidencia resuelta deja de vencer', function () {
    $incident = openIncident();
    $incident->forceFill([
        'due_at' => now()->subDay(),
        'status' => Incident::STATUS_RESOLVED,
        'resolved_at' => now(),
    ])->saveQuietly();

    $this->artisan('incidents:check-overdue');

    expect($incident->fresh()->isOverdue())->toBeFalse()
        ->and(StaffNotification::where('type', StaffNotification::TYPE_INCIDENT)->count())->toBe(0);
});

it('cambiar los tiempos objetivo recalcula los plazos vigentes', function () {
    $incident = openIncident(['priority' => 'medium']);
    $plazoViejo = $incident->due_at;

    app(IncidentController::class)->updateSla(
        overdueRequest(['high' => 1, 'medium' => 2, 'low' => 8], 'PATCH'),
    );

    $incident->refresh();

    // El ticket abierto adopta la política nueva: conservar el plazo viejo
    // sería mentirle al reporte de vencidas.
    expect((int) $incident->created_at->diffInHours($incident->due_at))->toBe(2)
        ->and($incident->due_at->lt($plazoViejo))->toBeTrue()
        ->and(app(IncidentPolicy::class)->hours()['high'])->toBe(1);
});

it('el dashboard muestra las pendientes con las vencidas primero', function () {
    // El bloque solo se pinta con el módulo `incidencias` activo.
    $tenant = new \App\Models\Tenant;
    $tenant->id = 'hotel-incidencias-test';
    $tenant->plan = 'basic';
    app()->instance(\Stancl\Tenancy\Contracts\Tenant::class, $tenant);
    app()->instance(\App\Models\Tenant::class, $tenant);

    $vieja = openIncident(['priority' => 'medium', 'title' => 'Daño: Toalla']);
    $vieja->forceFill(['due_at' => now()->subDays(5)])->saveQuietly();

    openIncident(['priority' => 'low', 'title' => 'Foco fundido']);

    $request = overdueRequest([], 'GET');
    $response = app(\App\Http\Controllers\Tenant\DashboardController::class)->__invoke($request);
    $props = (new ReflectionObject($response))->getProperty('props');
    $props->setAccessible(true);
    $maintenance = $props->getValue($response)['maintenance'];

    expect($maintenance['open'])->toBe(2)
        ->and($maintenance['overdue'])->toBe(1)
        ->and($maintenance['items'][0]['title'])->toBe('Daño: Toalla')
        ->and($maintenance['items'][0]['overdue'])->toBeTrue();
});

it('el plano marca las habitaciones con falla abierta', function () {
    $incident = openIncident(['priority' => 'high']);
    $incident->forceFill(['due_at' => now()->subDay()])->saveQuietly();

    // Una resuelta NO debe marcar: el cuarto ya se puede vender.
    Incident::create([
        'room_id' => $this->room->id,
        'title' => 'Foco fundido',
        'priority' => 'low',
        'status' => Incident::STATUS_RESOLVED,
        'resolved_at' => now(),
    ]);

    $payload = $this->room->fresh()->load('openIncidents')->toFloorPlanPayload();

    expect($payload['incidents'])->toHaveCount(1)
        ->and($payload['incidents'][0]['title'])->toBe('Fuga en la regadera')
        ->and($payload['incidents'][0]['overdue'])->toBeTrue();
});

it('las fallas del plano vienen con la más urgente primero', function () {
    openIncident(['priority' => 'low', 'title' => 'Foco fundido']);
    openIncident(['priority' => 'high', 'title' => 'Fuga de agua']);
    openIncident(['priority' => 'medium', 'title' => 'TV sin señal']);

    $payload = $this->room->fresh()->load('openIncidents')->toFloorPlanPayload();

    // El plano solo alcanza a mostrar una: tiene que ser la que urge.
    expect(array_column($payload['incidents'], 'title'))
        ->toBe(['Fuga de agua', 'TV sin señal', 'Foco fundido']);
});

it('una habitación sin fallas no carga nada en el plano', function () {
    $payload = $this->room->fresh()->load('openIncidents')->toFloorPlanPayload();

    expect($payload['incidents'])->toBe([]);
});
