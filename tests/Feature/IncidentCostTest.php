<?php

use App\Events\RoomStatusChanged;
use App\Http\Controllers\Tenant\IncidentController;
use App\Http\Controllers\Tenant\IncidentReportsController;
use App\Http\Controllers\Tenant\TechnicianController;
use App\Models\Incident;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

/**
 * El dinero de mantenimiento: cuánto costó reparar, quién lo hizo y
 * cuánto de eso se le alcanzó a cobrar al huésped que lo rompió. Si los
 * agregados mienten, el reporte no sirve para decidir nada.
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

    $this->chuy = Technician::create(['name' => 'Don Chuy', 'external' => false, 'active' => true]);
    $this->plomeria = Technician::create(['name' => 'Plomería del Valle', 'external' => true, 'active' => true]);
});

function costRequest(array $data, string $method = 'POST'): Request
{
    return tap(
        Request::create('/x', $method, $data),
        fn (Request $r) => $r->setUserResolver(fn () => test()->user),
    );
}

function resolveIncident(Incident $incident, array $data): Incident
{
    app(IncidentController::class)->update(
        costRequest(['status' => 'resolved', ...$data], 'PATCH'),
        $incident,
        app(\App\Actions\Rooms\ChangeRoomStatus::class),
    );

    return $incident->fresh();
}

function incidentsReport(array $query = []): array
{
    $response = app(IncidentReportsController::class)(
        Request::create('/incidencias/reportes', 'GET', $query),
    );

    $props = (new ReflectionObject($response))->getProperty('props');
    $props->setAccessible(true);

    return $props->getValue($response);
}

it('al resolver se guardan el costo y quién reparó', function () {
    $incident = Incident::create([
        'room_id' => $this->room->id,
        'title' => 'Fuga en la regadera',
        'priority' => 'high',
        'status' => Incident::STATUS_OPEN,
    ]);

    $resuelta = resolveIncident($incident, [
        'cost' => 850.50,
        'technician_id' => $this->plomeria->id,
    ]);

    expect((float) $resuelta->cost)->toBe(850.50)
        ->and($resuelta->technician_id)->toBe($this->plomeria->id)
        ->and($resuelta->technician->kindLabel())->toBe('Proveedor externo');
});

it('reabrir borra el costo: lo que costó la vez pasada ya no aplica', function () {
    $incident = Incident::create([
        'room_id' => $this->room->id,
        'title' => 'Fuga en la regadera',
        'priority' => 'high',
        'status' => Incident::STATUS_OPEN,
    ]);
    resolveIncident($incident, ['cost' => 850, 'technician_id' => $this->chuy->id]);

    app(IncidentController::class)->update(
        costRequest(['status' => 'open'], 'PATCH'),
        $incident->fresh(),
        app(\App\Actions\Rooms\ChangeRoomStatus::class),
    );

    $reabierta = $incident->fresh();

    expect($reabierta->cost)->toBeNull()
        ->and($reabierta->technician_id)->toBeNull();
});

it('el daño del check-out queda ligado a la estancia y lee lo que se cobró', function () {
    $plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);

    $stay = Stay::create([
        'room_id' => $this->room->id,
        'rate_plan_id' => $plan->id,
        'guest_name' => 'Quien rompió el espejo',
        'check_in_at' => now()->subHours(3),
        'planned_end_at' => now()->addHour(),
        'status' => Stay::STATUS_ACTIVE,
        'amount' => 1200,
        'channel' => 'walk_in',
        'created_by' => $this->user->id,
        'extra_charges' => [
            ['concept' => 'Espejo roto', 'amount' => 600, 'kind' => 'damage'],
        ],
    ]);

    app(IncidentController::class)->store(
        costRequest([
            'room_id' => $this->room->id,
            'stay_id' => $stay->id,
            'title' => 'Daño: Espejo roto',
            'category' => 'mobiliario',
            'priority' => 'medium',
            'source' => 'guest',
        ]),
        app(\App\Actions\Rooms\ChangeRoomStatus::class),
    );

    $incident = Incident::query()->latest('id')->first();

    expect($incident->stay_id)->toBe($stay->id)
        ->and($incident->chargedToGuest())->toBe(600.0);
});

it('sin estancia ligada no hay a quién cobrarle', function () {
    $incident = Incident::create([
        'room_id' => $this->room->id,
        'title' => 'Daño: Espejo roto',
        'priority' => 'medium',
        'status' => Incident::STATUS_OPEN,
    ]);

    expect($incident->chargedToGuest())->toBeNull();
});

it('el reporte suma el gasto por habitación y por quién reparó', function () {
    $otra = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '102',
    ]);

    foreach ([
        [$this->room, 800, $this->plomeria],
        [$this->room, 200, $this->chuy],
        [$otra, 150, $this->chuy],
    ] as [$room, $cost, $who]) {
        $incident = Incident::create([
            'room_id' => $room->id,
            'title' => 'Falla',
            'priority' => 'medium',
            'status' => Incident::STATUS_OPEN,
        ]);
        resolveIncident($incident, ['cost' => $cost, 'technician_id' => $who->id]);
    }

    $costs = incidentsReport()['costs'];
    $rooms = collect($costs['byRoom']);
    $people = collect($costs['byTechnician']);

    expect($costs['total'])->toBe(1150.0)
        ->and($costs['jobs'])->toBe(3)
        // Ordenado por costo: el cuarto caro primero, que es la pregunta.
        ->and($rooms->first()['name'])->toBe('101')
        ->and($rooms->first()['cost'])->toBe(1000.0)
        ->and($people->firstWhere('name', 'Don Chuy')['cost'])->toBe(350.0)
        ->and($people->firstWhere('name', 'Plomería del Valle')['jobs'])->toBe(1);
});

it('el reporte compara lo gastado contra lo que se le cobró al huésped', function () {
    $plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);

    $stay = Stay::create([
        'room_id' => $this->room->id,
        'rate_plan_id' => $plan->id,
        'guest_name' => 'Quien rompió el espejo',
        'check_in_at' => now()->subHours(3),
        'planned_end_at' => now()->addHour(),
        'status' => Stay::STATUS_ACTIVE,
        'amount' => 1200,
        'channel' => 'walk_in',
        'created_by' => $this->user->id,
        'extra_charges' => [
            ['concept' => 'Espejo roto', 'amount' => 400, 'kind' => 'damage'],
        ],
    ]);

    $incident = Incident::create([
        'room_id' => $this->room->id,
        'stay_id' => $stay->id,
        'title' => 'Daño: Espejo roto',
        'priority' => 'medium',
        'status' => Incident::STATUS_OPEN,
    ]);
    resolveIncident($incident, ['cost' => 700, 'technician_id' => $this->chuy->id]);

    $costs = incidentsReport()['costs'];

    // 700 de reparación contra 400 cobrados: la casa puso 300.
    expect($costs['total'])->toBe(700.0)
        ->and($costs['charged'])->toBe(400.0)
        ->and($costs['charged_jobs'])->toBe(1);
});

it('quien ya reparó algo se archiva en vez de borrarse', function () {
    $incident = Incident::create([
        'room_id' => $this->room->id,
        'title' => 'Falla',
        'priority' => 'medium',
        'status' => Incident::STATUS_OPEN,
    ]);
    resolveIncident($incident, ['cost' => 500, 'technician_id' => $this->chuy->id]);

    $response = app(TechnicianController::class)->destroy($this->chuy);

    expect($response->getData()->archived)->toBeTrue()
        ->and($this->chuy->fresh()->active)->toBeFalse()
        // El historial del reporte sigue cuadrando.
        ->and($incident->fresh()->technician_id)->toBe($this->chuy->id);
});

it('quien no ha reparado nada sí se borra', function () {
    app(TechnicianController::class)->destroy($this->plomeria);

    expect(Technician::find($this->plomeria->id))->toBeNull();
});

/**
 * Seguimiento del ticket sin moverle el estado: la bitácora y el contexto
 * que la ficha necesita para no ser una lista de campos.
 */
it('una nota de seguimiento entra a la línea de tiempo sin tocar el estado', function () {
    $incident = Incident::create([
        'room_id' => $this->room->id,
        'title' => 'Fuga en el lavabo',
        'category' => 'plomeria',
        'priority' => 'high',
        'status' => Incident::STATUS_OPEN,
        'reported_by' => $this->user->id,
    ]);

    $request = Request::create("/api/incidents/{$incident->id}/notes", 'POST', [
        'note' => 'Pedí la refacción, llega el jueves.',
    ]);
    $request->setUserResolver(fn () => $this->user);

    $response = app(IncidentController::class)->addNote($request, $incident);

    expect($response->getStatusCode())->toBe(201)
        ->and($incident->refresh()->status)->toBe(Incident::STATUS_OPEN);

    $showRequest = Request::create("/incidencias/{$incident->id}", 'GET');
    $showRequest->setUserResolver(fn () => $this->user);

    $props = app(\App\Http\Controllers\Tenant\IncidentShowController::class)($showRequest, $incident)
        ->toResponse($showRequest)->getOriginalContent()->getData()['page']['props'];

    $note = collect($props['timeline'])->firstWhere('kind', 'note');

    expect($note)->not->toBeNull()
        ->and($note['lines'][0])->toBe('Pedí la refacción, llega el jueves.')
        ->and($note['by'])->toBe($this->user->name);
});

it('la ficha trae el reloj del ticket y las fallas previas del cuarto', function () {
    // Dos fallas anteriores del mismo cuarto, una del mismo tipo.
    Incident::create([
        'room_id' => $this->room->id,
        'title' => 'Fuga anterior',
        'category' => 'plomeria',
        'priority' => 'medium',
        'status' => Incident::STATUS_RESOLVED,
        'reported_by' => $this->user->id,
        'cost' => 400,
    ]);
    Incident::create([
        'room_id' => $this->room->id,
        'title' => 'Foco fundido',
        'category' => 'electricidad',
        'priority' => 'low',
        'status' => Incident::STATUS_OPEN,
        'reported_by' => $this->user->id,
    ]);

    $incident = Incident::create([
        'room_id' => $this->room->id,
        'title' => 'Otra fuga',
        'category' => 'plomeria',
        'priority' => 'high',
        'status' => Incident::STATUS_OPEN,
        'reported_by' => $this->user->id,
    ]);

    $request = Request::create("/incidencias/{$incident->id}", 'GET');
    $request->setUserResolver(fn () => $this->user);

    $props = app(\App\Http\Controllers\Tenant\IncidentShowController::class)($request, $incident)
        ->toResponse($request)->getOriginalContent()->getData()['page']['props'];

    // Prioridad alta = 4 horas objetivo (default de IncidentPolicy).
    expect($props['sla']['target_hours'])->toBe(4)
        ->and($props['sla']['percent'])->toBeLessThanOrEqual(100)
        ->and($props['roomHistory']['total'])->toBe(2)
        ->and($props['roomHistory']['same_category'])->toBe(1)
        ->and($props['roomHistory']['open'])->toBe(1)
        ->and($props['roomHistory']['spent'])->toBe(400.0)
        ->and($props['categories'])->toHaveCount(9);
});
