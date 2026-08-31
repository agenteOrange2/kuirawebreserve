<?php

use App\Enums\RoomStatus;
use App\Http\Controllers\Tenant\HousekeepingPageController;
use App\Http\Controllers\Tenant\HousekeepingReportsController;
use App\Models\Housekeeper;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCleaning;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Las dos listas del panel de limpieza y el detalle del reporte: si el orden
 * o los filtros mienten, la recepción entra a la habitación equivocada y el
 * reporte cobra horas que no son.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    // Reloj fijo a media mañana: si la suite corre entre las 00:00 y la 01:00,
    // un `now()->subHour()` cae en el día anterior y "la bitácora de hoy"
    // fallaría sola.
    Carbon::setTestNow(Carbon::create(2026, 8, 20, 10, 0, 0));

    $this->property = Property::factory()->create();
    $this->type = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Sencilla']);
    $this->user = User::factory()->create();
    $this->marta = Housekeeper::create(['name' => 'Marta', 'active' => true]);
    $this->rosa = Housekeeper::create(['name' => 'Rosa', 'active' => true]);
});

function hkRoom(string $number, string $status, ?int $minutesAgo = null): Room
{
    $room = Room::factory()->create([
        'property_id' => test()->property->id,
        'room_type_id' => test()->type->id,
        'number' => $number,
        'status' => $status,
    ]);

    if ($minutesAgo !== null) {
        // created_at no es fillable en RoomStatusLog: se fuerza aparte.
        $log = $room->statusLogs()->create([
            'from_status' => RoomStatus::Occupied->value,
            'to_status' => $status,
        ]);
        $log->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();
    }

    return $room;
}

function hkProps(array $query = []): array
{
    $viewer = test()->user;
    $request = Request::create('/limpieza', 'GET', $query);
    // El paginador resuelve la página del request GLOBAL, no del que recibe
    // el controlador: en la app real son el mismo, aquí hay que atarlo. El
    // resolver de usuario va DESPUÉS: instance('request') dispara el
    // rebinding de auth y lo sobrescribe.
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $viewer);
    $response = app(HousekeepingPageController::class)->index($request);
    $prop = (new ReflectionObject($response))->getProperty('props');
    $prop->setAccessible(true);

    return $prop->getValue($response);
}

function reportProps(array $query = []): array
{
    $request = Request::create('/limpieza/reportes', 'GET', $query);
    app()->instance('request', $request);
    $response = app(HousekeepingReportsController::class)->index($request);
    $prop = (new ReflectionObject($response))->getProperty('props');
    $prop->setAccessible(true);

    return $prop->getValue($response);
}

it('el tablero ordena por espera y pagina', function () {
    hkRoom('101', RoomStatus::Dirty->value, 30);
    hkRoom('102', RoomStatus::Dirty->value, 240);
    hkRoom('103', RoomStatus::Cleaning->value, 10);
    // Sin log de semáforo: se va al final en vez de encabezar.
    hkRoom('104', RoomStatus::Dirty->value);

    $board = hkProps()['rooms'];
    $numbers = $board->getCollection()->pluck('number')->all();

    expect($board->total())->toBe(4)
        ->and($numbers)->toBe(['102', '101', '103', '104'])
        ->and($board->getCollection()->firstWhere('number', '102')['since_minutes'])->toBe(240);
});

it('el tablero filtra por estado y por búsqueda', function () {
    hkRoom('101', RoomStatus::Dirty->value, 30);
    hkRoom('201', RoomStatus::Cleaning->value, 10);

    expect(hkProps(['estado' => 'cleaning'])['rooms']->total())->toBe(1)
        ->and(hkProps(['estado' => 'dirty'])['rooms']->total())->toBe(1)
        ->and(hkProps(['q' => '20'])['rooms']->getCollection()->first()['number'])->toBe('201')
        ->and(hkProps(['q' => 'Sencilla'])['rooms']->total())->toBe(2)
        ->and(hkProps(['orden' => 'numero'])['rooms']->getCollection()->pluck('number')->all())
        ->toBe(['101', '201']);
});

it('la bitácora arranca en hoy y se abre con el rango', function () {
    $room = hkRoom('101', RoomStatus::Dirty->value, 30);

    RoomCleaning::create([
        'room_id' => $room->id, 'housekeeper_id' => $this->marta->id,
        'kind' => 'salida', 'started_at' => now()->subHour(), 'ended_at' => now(),
        'minutes' => 45, 'source' => 'plano',
    ]);
    RoomCleaning::create([
        'room_id' => $room->id, 'housekeeper_id' => $this->rosa->id,
        'kind' => 'retoque', 'started_at' => now()->subDays(10), 'ended_at' => now()->subDays(10)->addMinutes(90),
        'minutes' => 90, 'source' => 'manual',
    ]);

    expect(hkProps()['cleanings']->total())->toBe(1)
        ->and(hkProps(['rango' => 'todo'])['cleanings']->total())->toBe(2)
        ->and(hkProps(['rango' => 'todo', 'camarista' => $this->rosa->id])['cleanings']->total())->toBe(1)
        ->and(hkProps(['rango' => 'todo', 'tipo' => 'retoque'])['cleanings']->total())->toBe(1)
        ->and(hkProps(['rango' => 'todo', 'lorden' => 'duracion'])['cleanings']
            ->getCollection()->first()['minutes'])->toBe(90)
        ->and(hkProps(['rango' => 'todo'])['cleanings']->getCollection()->first())
        ->toHaveKey('started_day');
});

it('la bitácora separa abiertas de cerradas', function () {
    $room = hkRoom('101', RoomStatus::Cleaning->value, 10);

    RoomCleaning::create([
        'room_id' => $room->id, 'housekeeper_id' => $this->marta->id,
        'kind' => 'salida', 'started_at' => now()->subMinutes(20), 'source' => 'plano',
    ]);

    expect(hkProps(['situacion' => 'abiertas'])['cleanings']->total())->toBe(1)
        ->and(hkProps(['situacion' => 'cerradas'])['cleanings']->total())->toBe(0);
});

it('las dos tablas paginan por separado', function () {
    foreach (range(1, 20) as $i) {
        hkRoom('1'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), RoomStatus::Dirty->value, $i);
    }

    $page1 = hkProps()['rooms'];
    $page2 = hkProps(['pagina' => 2])['rooms'];

    expect($page1->total())->toBe(20)
        ->and($page1->count())->toBe(15)
        ->and($page2->count())->toBe(5)
        ->and($page2->currentPage())->toBe(2);
});

it('el reporte filtra por tipo y trae el detalle paginado', function () {
    $room = hkRoom('101', RoomStatus::Dirty->value, 30);

    foreach (range(1, 3) as $i) {
        RoomCleaning::create([
            'room_id' => $room->id, 'housekeeper_id' => $this->marta->id,
            'kind' => 'salida', 'started_at' => now()->subHours($i), 'ended_at' => now()->subHours($i)->addMinutes(30),
            'minutes' => 30, 'source' => 'plano',
        ]);
    }
    RoomCleaning::create([
        'room_id' => $room->id, 'housekeeper_id' => $this->rosa->id,
        'kind' => 'retoque', 'started_at' => now()->subHour(), 'ended_at' => now(),
        'minutes' => 120, 'source' => 'manual',
    ]);

    $todos = reportProps();
    $soloRetoque = reportProps(['kind' => 'retoque']);
    $porDuracion = reportProps(['orden' => 'duracion']);

    expect($todos['detail']->total())->toBe(4)
        ->and($todos['kpis']['rooms'])->toBe(4)
        ->and($soloRetoque['detail']->total())->toBe(1)
        // El filtro de tipo acota TODO el reporte, no solo el detalle.
        ->and($soloRetoque['kpis']['rooms'])->toBe(1)
        ->and($porDuracion['detail']->getCollection()->first()['minutes'])->toBe(120)
        ->and($todos['detail']->getCollection()->first())->toHaveKeys(['room', 'housekeeper', 'started_day', 'source']);
});

it('el pdf no arma el detalle paginado', function () {
    $method = (new ReflectionClass(HousekeepingReportsController::class))->getMethod('reportData');
    $method->setAccessible(true);

    $data = $method->invoke(
        app(HousekeepingReportsController::class),
        Request::create('/limpieza/reportes/pdf', 'GET'),
        false,
    );

    expect($data['detail'])->toBeNull()
        ->and($data)->toHaveKeys(['kpis', 'byHousekeeper', 'linens', 'byKind', 'filters']);
});
