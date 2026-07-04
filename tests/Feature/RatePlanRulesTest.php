<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\CreateWalkInStay;
use App\Events\RoomStatusChanged;
use App\Exceptions\NoAvailabilityException;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
});

function plan(array $overrides = []): RatePlan
{
    return RatePlan::factory()->create([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
        ...$overrides,
    ]);
}

it('calcula precio por horas, días, semanas y meses', function () {
    $base = now()->startOfHour();

    $porHoras = RatePlan::factory()->period('hour', 3, 250)->create([
        'property_id' => $this->property->id, 'room_type_id' => $this->roomType->id,
    ]);
    // 4 h en periodos de 3 h → 2 periodos.
    expect($porHoras->unitsFor($base, $base->copy()->addHours(4)))->toBe(2)
        ->and($porHoras->priceFor($base, $base->copy()->addHours(4)))->toBe(500.0)
        ->and($porHoras->durationLabel())->toBe('3 horas');

    $porDia = RatePlan::factory()->period('day', 1, 900)->create([
        'property_id' => $this->property->id, 'room_type_id' => $this->roomType->id,
    ]);
    expect($porDia->unitsFor($base, $base->copy()->addDays(2)))->toBe(2)
        ->and($porDia->durationLabel())->toBe('1 día');

    $porSemana = RatePlan::factory()->period('week', 1, 3800)->create([
        'property_id' => $this->property->id, 'room_type_id' => $this->roomType->id,
    ]);
    // 10 días → 2 semanas (techo).
    expect($porSemana->unitsFor($base, $base->copy()->addDays(10)))->toBe(2)
        ->and($porSemana->priceFor($base, $base->copy()->addDays(7)))->toBe(3800.0);

    $porMes = RatePlan::factory()->period('month', 1, 9000)->create([
        'property_id' => $this->property->id, 'room_type_id' => $this->roomType->id,
    ]);
    // Mes CALENDARIO: 5 feb → 5 mar = 1 mes; un día más → 2 meses.
    $inicio = now()->addDays(3)->setTime(14, 0);
    expect($porMes->unitsFor($inicio, $inicio->copy()->addMonth()))->toBe(1)
        ->and($porMes->unitsFor($inicio, $inicio->copy()->addMonth()->addDay()))->toBe(2)
        ->and($porMes->durationLabel())->toBe('1 mes');
});

it('sugiere el fin según la unidad del periodo', function () {
    $porSemana = RatePlan::factory()->period('week', 2, 7000)->create([
        'property_id' => $this->property->id, 'room_type_id' => $this->roomType->id,
    ]);

    $inicio = now()->addDay();

    expect($porSemana->suggestedEnd($inicio)->toDateString())
        ->toBe($inicio->copy()->addWeeks(2)->toDateString());
});

it('rechaza reservas dentro de la ventana de antelación mínima', function () {
    $tarifa = plan(['min_advance_unit' => 'hour', 'min_advance_value' => 4]);

    expect($tarifa->minAdvanceLabel())->toBe('4 horas');

    // Llegada en 5 horas → permitida.
    $ok = app(CreateReservation::class)->handle([
        'rate_plan_id' => $tarifa->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addHours(5),
        'ends_at' => now()->addHours(5)->addDay(),
        'confirmed' => true,
    ]);
    expect($ok->id)->toBeInt();

    // Llegada en 1 hora → rechazada.
    app(CreateReservation::class)->handle([
        'rate_plan_id' => $tarifa->id,
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addDays(3),
        'confirmed' => true,
    ]);
})->throws(NoAvailabilityException::class, 'antelación');

it('la antelación en días y semanas también aplica', function () {
    $tarifa = plan(['min_advance_unit' => 'week', 'min_advance_value' => 1]);

    app(CreateReservation::class)->handle([
        'rate_plan_id' => $tarifa->id,
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(4),
        'confirmed' => true,
    ]);
})->throws(NoAvailabilityException::class);

it('el walk-in ignora la antelación mínima', function () {
    $tarifa = RatePlan::factory()->period('hour', 3, 250)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'min_advance_unit' => 'day',
        'min_advance_value' => 2,
    ]);

    $stay = app(CreateWalkInStay::class)->handle([
        'room_id' => $this->room->id,
        'rate_plan_id' => $tarifa->id,
    ]);

    expect($stay->status)->toBe('active')
        ->and((float) $stay->amount)->toBe(250.0);
});

it('las tarifas viejas en minutos siguen funcionando (compat)', function () {
    $legacy = plan(['type' => 'block', 'duration_minutes' => 180, 'duration_unit' => null, 'duration_value' => null]);

    expect($legacy->unitsFor(now(), now()->addHours(4)))->toBe(2)
        ->and($legacy->durationLabel())->toBe('180 minutos');
});
