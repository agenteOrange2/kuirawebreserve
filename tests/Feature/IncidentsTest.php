<?php

use App\Actions\Rooms\ChangeRoomStatus;
use App\Events\RoomStatusChanged;
use App\Http\Controllers\Tenant\IncidentController;
use App\Models\Incident;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;

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

function incidentRequest(array $data, string $method = 'POST'): Request
{
    $request = Request::create('/api/incidents', $method, $data);
    $request->setUserResolver(fn () => test()->user);

    return $request;
}

it('reportar con "poner en mantenimiento" saca la habitación del plano', function () {
    $response = app(IncidentController::class)->store(
        incidentRequest([
            'room_id' => $this->room->id,
            'title' => 'Fuga en la regadera',
            'priority' => 'high',
            'set_maintenance' => true,
        ]),
        app(ChangeRoomStatus::class),
    );

    $incident = Incident::firstOrFail();

    expect($response->getStatusCode())->toBe(201)
        ->and($incident->status)->toBe(Incident::STATUS_OPEN)
        ->and($incident->reported_by)->toBe($this->user->id)
        ->and($this->room->fresh()->status->getMorphClass())->toBe('maintenance');

    // El movimiento del semáforo queda en la bitácora con la incidencia.
    $log = $this->room->statusLogs()->latest('id')->first();
    expect($log->to_status)->toBe('maintenance')
        ->and($log->context['incident_id'])->toBe($incident->id);
});

it('con responsable asignado la incidencia nace en proceso', function () {
    $tecnico = User::factory()->create();

    app(IncidentController::class)->store(
        incidentRequest([
            'title' => 'Lámpara fundida en pasillo',
            'priority' => 'low',
            'assigned_to' => $tecnico->id,
        ]),
        app(ChangeRoomStatus::class),
    );

    expect(Incident::firstOrFail()->status)->toBe(Incident::STATUS_IN_PROGRESS);
});

it('no manda a mantenimiento una habitación ocupada', function () {
    $this->room->update(['status' => 'occupied']);

    app(IncidentController::class)->store(
        incidentRequest([
            'room_id' => $this->room->id,
            'title' => 'Aire acondicionado ruidoso',
            'priority' => 'medium',
            'set_maintenance' => true,
        ]),
        app(ChangeRoomStatus::class),
    );

    // La incidencia queda, pero al huésped no se le saca la habitación.
    expect(Incident::count())->toBe(1)
        ->and($this->room->fresh()->status->getMorphClass())->toBe('occupied');
});

it('resolver sella la resolución y devuelve la habitación al plano', function () {
    $this->room->update(['status' => 'maintenance']);

    $incident = Incident::create([
        'room_id' => $this->room->id,
        'title' => 'Cambio de minisplit',
        'priority' => 'high',
        'status' => Incident::STATUS_IN_PROGRESS,
    ]);

    app(IncidentController::class)->update(
        incidentRequest([
            'status' => Incident::STATUS_RESOLVED,
            'resolution_notes' => 'Se instaló equipo nuevo.',
            'release_room' => true,
        ], 'PATCH'),
        $incident,
        app(ChangeRoomStatus::class),
    );

    $incident->refresh();

    expect($incident->status)->toBe(Incident::STATUS_RESOLVED)
        ->and($incident->resolved_by)->toBe($this->user->id)
        ->and($incident->resolved_at)->not->toBeNull()
        ->and($incident->resolution_notes)->toBe('Se instaló equipo nuevo.')
        ->and($this->room->fresh()->status->getMorphClass())->toBe('available');
});

it('reabrir una incidencia limpia la resolución', function () {
    $incident = Incident::create([
        'title' => 'Gotea el lavabo',
        'priority' => 'medium',
        'status' => Incident::STATUS_RESOLVED,
        'resolved_by' => $this->user->id,
        'resolved_at' => now(),
        'resolution_notes' => 'Apretado el empaque.',
    ]);

    app(IncidentController::class)->update(
        incidentRequest(['status' => Incident::STATUS_OPEN], 'PATCH'),
        $incident,
        app(ChangeRoomStatus::class),
    );

    $incident->refresh();

    expect($incident->status)->toBe(Incident::STATUS_OPEN)
        ->and($incident->resolved_by)->toBeNull()
        ->and($incident->resolved_at)->toBeNull()
        ->and($incident->resolution_notes)->toBeNull();
});

it('el reporte por periodo cuenta reportadas, resueltas y tiempos por habitación', function () {
    // Dos de esta semana (una resuelta en 4 h) y una vieja fuera del rango.
    // created_at no es fillable: se ajusta por query builder.
    $resuelta = Incident::create([
        'room_id' => $this->room->id,
        'title' => 'Fuga',
        'priority' => 'high',
        'status' => Incident::STATUS_RESOLVED,
        'resolved_at' => now(),
    ]);
    Incident::query()->whereKey($resuelta->id)->update(['created_at' => now()->subHours(4)]);

    Incident::create([
        'room_id' => $this->room->id,
        'title' => 'Foco fundido',
        'priority' => 'low',
        'status' => Incident::STATUS_OPEN,
    ]);

    $vieja = Incident::create([
        'title' => 'Vieja',
        'priority' => 'medium',
        'status' => Incident::STATUS_OPEN,
    ]);
    Incident::query()->whereKey($vieja->id)->update(['created_at' => now()->subMonths(2)]);

    $request = Illuminate\Http\Request::create('/incidencias/reportes', 'GET', [
        'period' => 'custom',
        'from' => now()->subDays(7)->toDateString(),
        'to' => now()->toDateString(),
    ]);
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => test()->user);

    $props = app(\App\Http\Controllers\Tenant\IncidentReportsController::class)($request)
        ->toResponse($request)->getData(true)['props'];

    expect($props['kpis']['reported'])->toBe(2)
        ->and($props['kpis']['resolved'])->toBe(1)
        ->and($props['kpis']['pending'])->toBe(1)
        ->and($props['kpis']['high'])->toBe(1)
        ->and($props['kpis']['avg_hours'])->toEqual(4)
        // Pendientes hoy cuenta TODO lo abierto, también lo viejo.
        ->and($props['kpis']['open_now'])->toBe(2)
        ->and($props['byRoom'][0]['name'])->toContain('101')
        ->and($props['byRoom'][0]['total'])->toBe(2);

    // Filtrado por habitación: la incidencia de área general queda fuera.
    $porCuarto = Illuminate\Http\Request::create('/incidencias/reportes', 'GET', [
        'period' => 'year',
        'room' => $this->room->id,
    ]);
    $porCuarto->headers->set('X-Inertia', 'true');
    $porCuarto->setUserResolver(fn () => test()->user);

    $propsCuarto = app(\App\Http\Controllers\Tenant\IncidentReportsController::class)($porCuarto)
        ->toResponse($porCuarto)->getData(true)['props'];

    expect($propsCuarto['kpis']['reported'])->toBe(2)
        ->and($propsCuarto['period']['label'])->toContain('101');
});

it('el detalle arma la línea de tiempo desde el activity log', function () {
    $incident = Incident::create([
        'room_id' => $this->room->id,
        'title' => 'Aire ruidoso',
        'priority' => 'medium',
    ]);

    app(IncidentController::class)->update(
        incidentRequest(['status' => Incident::STATUS_IN_PROGRESS], 'PATCH'),
        $incident,
        app(ChangeRoomStatus::class),
    );

    $request = Illuminate\Http\Request::create("/incidencias/{$incident->id}", 'GET');
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => test()->user);

    $props = app(\App\Http\Controllers\Tenant\IncidentShowController::class)($request, $incident)
        ->toResponse($request)->getData(true)['props'];

    $lines = collect($props['timeline'])->flatMap(fn (array $entry) => $entry['lines']);

    expect($props['incident']['title'])->toBe('Aire ruidoso')
        ->and($lines)->toContain('Incidencia reportada')
        ->and($lines->first(fn (string $line) => str_contains($line, 'En proceso')))->not->toBeNull();
});

it('las fotos se guardan en la incidencia y no se sirven desde otra', function () {
    $incident = Incident::create(['title' => 'Vidrio estrellado', 'priority' => 'medium']);
    $otra = Incident::create(['title' => 'Otra incidencia', 'priority' => 'low']);

    // addMediaFromRequest lee el request GLOBAL: el archivo va ahí.
    $request = tap(request(), function ($r) {
        $r->files->set('file', UploadedFile::fake()->image('evidencia.jpg', 600, 600));
        $r->setUserResolver(fn () => test()->user);
    });

    $response = app(IncidentController::class)->storePhoto($request, $incident);

    expect($response->getStatusCode())->toBe(201)
        ->and($incident->fresh()->getMedia('photos'))->toHaveCount(1);

    // La foto pertenece a SU incidencia: pedirla desde otra responde 404.
    $media = $incident->fresh()->getFirstMedia('photos');

    expect(fn () => app(IncidentController::class)->showPhoto($otra, $media))
        ->toThrow(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});

it('la incidencia guarda tipo de falla y si la reportó un huésped', function () {
    $response = app(IncidentController::class)->store(
        incidentRequest([
            'room_id' => $this->room->id,
            'title' => 'Minisplit no enfría',
            'category' => 'clima',
            'source' => 'guest',
            'priority' => 'medium',
        ]),
        app(ChangeRoomStatus::class),
    );

    $payload = $response->getData(true);
    $incident = Incident::firstOrFail();

    expect($response->getStatusCode())->toBe(201)
        ->and($incident->category)->toBe('clima')
        ->and($incident->isGuestReported())->toBeTrue()
        ->and($payload['category_label'])->toBe('Clima / aire acondicionado')
        ->and($payload['guest_reported'])->toBeTrue();

    // Un tipo fuera del catálogo se rechaza.
    expect(fn () => app(IncidentController::class)->store(
        incidentRequest(['title' => 'Otra', 'category' => 'inventado', 'priority' => 'low']),
        app(ChangeRoomStatus::class),
    ))->toThrow(\Illuminate\Validation\ValidationException::class);
});
