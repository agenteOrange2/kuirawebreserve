<?php

use App\Enums\RoomStatus;
use App\Http\Controllers\Tenant\HousekeepingReportsController;
use App\Models\Housekeeper;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCleaning;
use App\Models\RoomType;
use Illuminate\Http\Request;

/**
 * El reporte es lo que el hotel compra con este módulo: si los agregados
 * mienten, no sirve de nada haber registrado.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
    ]);

    $this->marta = Housekeeper::create(['name' => 'Marta', 'active' => true]);
    $this->rosa = Housekeeper::create(['name' => 'Rosa', 'active' => true]);
});

function reportFor(array $query = []): array
{
    $response = app(HousekeepingReportsController::class)->index(
        Request::create('/limpieza/reportes', 'GET', $query),
    );

    $property = (new ReflectionObject($response))->getProperty('props');
    $property->setAccessible(true);

    return $property->getValue($response);
}

function cleaning(Housekeeper $who, int $minutes, array $extra = []): RoomCleaning
{
    $started = $extra['started_at'] ?? now()->subHours(2);

    return RoomCleaning::create([
        'room_id' => test()->room->id,
        'housekeeper_id' => $who->id,
        'kind' => $extra['kind'] ?? RoomCleaning::KIND_CHECKOUT,
        'started_at' => $started,
        'ended_at' => $started->copy()->addMinutes($minutes),
        'minutes' => $minutes,
        'linens' => $extra['linens'] ?? null,
    ]);
}

it('agrupa por camarista con promedio, más rápida y más lenta', function () {
    cleaning($this->marta, 20);
    cleaning($this->marta, 40);
    cleaning($this->rosa, 30);

    $rows = collect(reportFor()['byHousekeeper']);
    $marta = $rows->firstWhere('name', 'Marta');

    expect($rows)->toHaveCount(2)
        // Ordenadas por volumen: quien más hizo, primero.
        ->and($rows->first()['name'])->toBe('Marta')
        ->and($marta['rooms'])->toBe(2)
        ->and($marta['avg_minutes'])->toBe(30)
        ->and($marta['fastest'])->toBe(20)
        ->and($marta['slowest'])->toBe(40)
        ->and($marta['total_minutes'])->toBe(60);
});

it('una limpieza en curso no ensucia el promedio', function () {
    cleaning($this->marta, 30);

    // Abierta desde hace horas: sin cerrar, todavía no dice cuánto tardó.
    RoomCleaning::create([
        'room_id' => $this->room->id,
        'housekeeper_id' => $this->marta->id,
        'started_at' => now()->subHours(5),
    ]);

    $report = reportFor();

    expect($report['kpis']['rooms'])->toBe(1)
        ->and($report['kpis']['in_progress'])->toBe(1)
        ->and($report['kpis']['avg_minutes'])->toBe(30);
});

it('suma la ropa consumida por insumo y por persona', function () {
    cleaning($this->marta, 25, ['linens' => ['sabanas' => 2, 'toallas' => 3]]);
    cleaning($this->marta, 25, ['linens' => ['sabanas' => 1]]);
    cleaning($this->rosa, 25, ['linens' => ['toallas' => 4]]);

    $report = reportFor();
    $totals = collect($report['linens'])->pluck('total', 'key');
    $marta = collect($report['byHousekeeper'])->firstWhere('name', 'Marta');

    expect($totals['sabanas'])->toBe(3)
        ->and($totals['toallas'])->toBe(7)
        // Y el desglose por persona cuadra con el total.
        ->and(collect($marta['linens'])->pluck('total', 'key')['sabanas'])->toBe(3);
});

it('el filtro por camarista acota el reporte', function () {
    cleaning($this->marta, 20);
    cleaning($this->rosa, 50);

    $solo = reportFor(['housekeeper' => $this->rosa->id]);

    expect($solo['byHousekeeper'])->toHaveCount(1)
        ->and($solo['byHousekeeper'][0]['name'])->toBe('Rosa')
        ->and($solo['kpis']['rooms'])->toBe(1);
});

it('deja fuera lo que no cae en el periodo', function () {
    cleaning($this->marta, 30, ['started_at' => now()->subMonths(2)]);
    cleaning($this->rosa, 30);

    expect(reportFor(['period' => 'month'])['kpis']['rooms'])->toBe(1)
        ->and(reportFor(['period' => 'year'])['kpis']['rooms'])->toBe(2);
});

it('el tiempo de vuelta a vendible sale del semáforo, no de los registros', function () {
    // Ciclo real: se ensucia, espera 20 min, la limpian 30 y se libera.
    // Nadie registró nada, y aun así el turnaround debe medirse.
    $this->room->statusLogs()->create([
        'from_status' => RoomStatus::Occupied->value,
        'to_status' => RoomStatus::Dirty->value,
    ])->forceFill(['created_at' => now()->subMinutes(50)])->saveQuietly();

    $this->room->statusLogs()->create([
        'from_status' => RoomStatus::Dirty->value,
        'to_status' => RoomStatus::Cleaning->value,
    ])->forceFill(['created_at' => now()->subMinutes(30)])->saveQuietly();

    $this->room->statusLogs()->create([
        'from_status' => RoomStatus::Cleaning->value,
        'to_status' => RoomStatus::Available->value,
    ])->forceFill(['created_at' => now()])->saveQuietly();

    $turnaround = reportFor(['period' => 'day'])['kpis']['turnaround'];

    expect($turnaround['samples'])->toBe(1)
        ->and($turnaround['avg_wait'])->toBe(20)
        ->and($turnaround['avg_total'])->toBe(50);
});

it('cuenta las limpiezas por tipo', function () {
    cleaning($this->marta, 30, ['kind' => RoomCleaning::KIND_CHECKOUT]);
    cleaning($this->marta, 10, ['kind' => RoomCleaning::KIND_TOUCHUP]);
    cleaning($this->rosa, 10, ['kind' => RoomCleaning::KIND_TOUCHUP]);

    $kinds = collect(reportFor()['byKind'])->pluck('count', 'key');

    expect($kinds[RoomCleaning::KIND_CHECKOUT])->toBe(1)
        ->and($kinds[RoomCleaning::KIND_TOUCHUP])->toBe(2)
        ->and($kinds[RoomCleaning::KIND_DEEP])->toBe(0);
});
