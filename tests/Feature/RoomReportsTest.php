<?php

use App\Http\Controllers\Tenant\RoomReportsController;
use App\Models\Incident;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomCleaning;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

/**
 * Reportes de /habitaciones/reportes: la habitación vista como activo.
 * Lo que tiene que cuadrar es el USO (rotaciones y noches), el dinero
 * VENDIDO de esas rentas y lo que costó tener el cuarto listo.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Sencilla',
    ]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
    ]);
    // La que nadie renta: el reporte existe para que salte a la vista.
    $this->idleRoom = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '102',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 500,
    ]);

    Permission::findOrCreate('rooms.view', 'web');
    $this->user = User::factory()->create();
    $this->user->givePermissionTo('rooms.view');
});

/** Props del reporte para un rango fijo de septiembre (30 días). */
function propsDelReporteDeHabitaciones(array $query = []): array
{
    $request = Request::create('/habitaciones/reportes', 'GET', $query + [
        'period' => 'custom',
        'from' => '2026-09-01',
        'to' => '2026-09-30',
    ]);
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => test()->user);

    return app(RoomReportsController::class)($request)
        ->toResponse($request)->getData(true)['props'];
}

function estanciaDeReporte(array $overrides = []): Stay
{
    return Stay::create(array_replace([
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Huésped de paso',
        'num_people' => 2,
        'check_in_at' => '2026-09-05 15:00:00',
        'planned_end_at' => '2026-09-06 12:00:00',
        'check_out_at' => '2026-09-06 12:00:00',
        'status' => Stay::STATUS_COMPLETED,
        'amount' => 500,
        'channel' => 'walk_in',
        'created_by' => test()->user->id,
    ], $overrides));
}

it('cuenta rotaciones y noches: tres rentas el mismo día no son tres noches', function () {
    // Motel puro: el mismo cuarto rentado tres veces el 5 de septiembre.
    estanciaDeReporte(['check_in_at' => '2026-09-05 10:00:00', 'check_out_at' => '2026-09-05 13:00:00']);
    estanciaDeReporte(['check_in_at' => '2026-09-05 14:00:00', 'check_out_at' => '2026-09-05 17:00:00']);
    estanciaDeReporte(['check_in_at' => '2026-09-05 18:00:00', 'check_out_at' => '2026-09-05 21:00:00']);

    $props = propsDelReporteDeHabitaciones();
    $fila = collect($props['rooms'])->firstWhere('id', test()->room->id);

    expect($props['summary']['uses'])->toBe(3)
        // Tres rentas, un solo día ocupado.
        ->and($fila['nights'])->toBe(1)
        ->and($fila['uses'])->toBe(3)
        ->and($fila['revenue'])->toEqual(1500)
        // 1 noche de 30 días del periodo.
        ->and($fila['percent'])->toEqual(3.3)
        ->and($fila['avg_stay_label'])->toBe('3 h');
});

it('deja ver la habitación que no se rentó ni una vez', function () {
    estanciaDeReporte();

    $props = propsDelReporteDeHabitaciones();

    expect($props['summary']['idle_rooms'])->toBe(1)
        ->and(collect($props['idle'])->pluck('name'))->toContain('102')
        // Y el uso general cuenta las dos habitaciones: 1 noche de 60.
        ->and($props['summary']['available'])->toBe(60)
        ->and($props['summary']['percent'])->toEqual(1.7);
});

it('suma lo que costó tener la habitación: incidencias, días fuera y limpiezas', function () {
    estanciaDeReporte();

    $incidencia = Incident::create([
        'room_id' => test()->room->id,
        'title' => 'No enfría el clima',
        'category' => 'clima',
        'priority' => 'high',
        'status' => Incident::STATUS_RESOLVED,
        'cost' => 1200,
        'resolved_at' => '2026-09-10 13:00:00',
        'reported_by' => test()->user->id,
    ]);
    // created_at no es fillable: se pone aparte para que la incidencia
    // caiga dentro del periodo y el tiempo de resolución sea el real.
    $incidencia->forceFill(['created_at' => '2026-09-10 09:00:00'])->saveQuietly();

    RoomBlock::create([
        'room_id' => test()->idleRoom->id,
        'starts_at' => '2026-09-08',
        'ends_at' => '2026-09-10',
        'reason' => 'Pintura',
        'created_by' => test()->user->id,
    ]);

    RoomCleaning::create([
        'room_id' => test()->room->id,
        'kind' => 'salida',
        'started_at' => '2026-09-06 12:30:00',
        'ended_at' => '2026-09-06 13:00:00',
        'minutes' => 30,
        'recorded_by' => test()->user->id,
    ]);

    $props = propsDelReporteDeHabitaciones();
    $conFalla = collect($props['rooms'])->firstWhere('id', test()->room->id);
    $bloqueada = collect($props['rooms'])->firstWhere('id', test()->idleRoom->id);

    expect($props['summary']['incidents'])->toBe(1)
        ->and($props['summary']['incident_cost_label'])->toBe('$1,200.00')
        ->and($props['maintenance']['categories'][0]['label'])->toBe('Clima / aire acondicionado')
        ->and($props['maintenance']['avg_resolution_label'])->toBe('4 h')
        // Tres días de bloqueo dentro del periodo (8, 9 y 10).
        ->and($bloqueada['out_of_service_days'])->toBe(3)
        ->and($conFalla['cleanings'])->toBe(1)
        ->and($props['summary']['avg_cleaning_label'])->toBe('30 min');
});

it('dice qué día de la semana se llena', function () {
    // 2026-09-05 fue sábado; 2026-09-12 y 2026-09-19 también.
    estanciaDeReporte(['check_in_at' => '2026-09-05 15:00:00', 'check_out_at' => '2026-09-05 23:00:00']);
    estanciaDeReporte(['check_in_at' => '2026-09-12 15:00:00', 'check_out_at' => '2026-09-12 23:00:00']);
    // Y un martes cualquiera.
    estanciaDeReporte(['check_in_at' => '2026-09-08 15:00:00', 'check_out_at' => '2026-09-08 23:00:00']);

    $semana = collect(propsDelReporteDeHabitaciones()['weekdays'])->keyBy('label');

    expect($semana['Sáb']['uses'])->toBe(2)
        ->and($semana['Mar']['uses'])->toBe(1)
        ->and($semana['Dom']['uses'])->toBe(0)
        // Dos sábados ocupados de los cuatro que trae septiembre.
        ->and($semana['Sáb']['occupied'])->toBe(2);
});

it('el PDF se arma con los mismos números de la pantalla', function () {
    estanciaDeReporte();

    $request = Request::create('/habitaciones/reportes/pdf', 'GET', [
        'period' => 'custom',
        'from' => '2026-09-01',
        'to' => '2026-09-30',
    ]);
    $request->setUserResolver(fn () => test()->user);

    $pdf = app(RoomReportsController::class)->pdf($request);

    expect($pdf->headers->get('content-type'))->toContain('application/pdf')
        ->and($pdf->headers->get('content-disposition'))
        ->toContain('reporte-habitaciones-2026-09-01-a-2026-09-30.pdf')
        // %PDF: que de verdad salga el archivo, no una excepción del blade.
        ->and(substr($pdf->getContent(), 0, 4))->toBe('%PDF');
});

it('el filtro por habitación deja el reporte en esa sola', function () {
    estanciaDeReporte();
    estanciaDeReporte(['room_id' => test()->idleRoom->id, 'amount' => 900]);

    $props = propsDelReporteDeHabitaciones(['room' => test()->idleRoom->id]);

    expect($props['rooms'])->toHaveCount(1)
        ->and($props['rooms'][0]['id'])->toBe(test()->idleRoom->id)
        ->and($props['summary']['revenue'])->toEqual(900)
        // Una sola habitación por 30 días.
        ->and($props['summary']['available'])->toBe(30);
});
