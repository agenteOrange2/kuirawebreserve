<?php

use App\Enums\RateDurationUnit;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use Carbon\Carbon;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create([
        'settings' => ['check_in_time' => '12:00', 'check_out_time' => '12:00'],
    ]);
    $this->type = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->type->id,
        'price' => 590,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

it('sin corte de madrugada, llegar a las 5 AM sale al día siguiente como siempre', function () {
    $end = $this->plan->fresh()->suggestedEnd(Carbon::parse('2026-10-05 05:00'));

    expect($end->toDateTimeString())->toBe('2026-10-06 12:00:00');
});

it('con corte a las 7, antes de esa hora sale hoy a las 12 y después sale mañana', function () {
    $this->property->update(['settings' => [
        'check_in_time' => '12:00', 'check_out_time' => '12:00', 'night_cutoff_time' => '07:00',
    ]]);
    $plan = $this->plan->fresh();

    expect($plan->suggestedEnd(Carbon::parse('2026-10-05 05:00'))->toDateTimeString())->toBe('2026-10-05 12:00:00')
        ->and($plan->suggestedEnd(Carbon::parse('2026-10-05 06:59'))->toDateTimeString())->toBe('2026-10-05 12:00:00')
        ->and($plan->suggestedEnd(Carbon::parse('2026-10-05 07:00'))->toDateTimeString())->toBe('2026-10-06 12:00:00')
        ->and($plan->suggestedEnd(Carbon::parse('2026-10-05 20:00'))->toDateTimeString())->toBe('2026-10-06 12:00:00')
        // La madrugada se cobra como una noche.
        ->and($plan->unitsFor(Carbon::parse('2026-10-05 05:00'), Carbon::parse('2026-10-05 12:00')))->toBe(1);
});

it('la antelación sin días marcados aplica toda la semana, como hasta ahora', function () {
    Carbon::setTestNow('2026-10-05 09:00'); // lunes
    $this->plan->update(['min_advance_unit' => RateDurationUnit::Day, 'min_advance_value' => 1]);
    $plan = $this->plan->fresh();

    expect($plan->violatesMinAdvance(Carbon::parse('2026-10-05 12:00')))->toBeTrue()
        ->and($plan->minAdvanceLabel())->toBe('1 día');
});

it('con días marcados, entre semana se reserva el mismo día y el fin de semana pide 1 día', function () {
    $this->plan->update([
        'min_advance_unit' => RateDurationUnit::Day,
        'min_advance_value' => 1,
        'min_advance_weekdays' => [5, 6, 0],
    ]);
    $plan = $this->plan->fresh();

    Carbon::setTestNow('2026-10-05 09:00'); // lunes
    expect($plan->violatesMinAdvance(Carbon::parse('2026-10-05 12:00')))->toBeFalse();

    Carbon::setTestNow('2026-10-09 09:00'); // viernes
    expect($plan->violatesMinAdvance(Carbon::parse('2026-10-09 12:00')))->toBeTrue()
        ->and($plan->violatesMinAdvance(Carbon::parse('2026-10-10 12:00')))->toBeFalse();

    Carbon::setTestNow('2026-10-11 09:00'); // domingo
    expect($plan->violatesMinAdvance(Carbon::parse('2026-10-11 12:00')))->toBeTrue()
        ->and($plan->violatesMinAdvance(Carbon::parse('2026-10-12 12:00')))->toBeFalse();

    expect($plan->minAdvanceLabel())->toBe('1 día (llegadas en viernes, sábado y domingo)')
        ->and($plan->minAdvanceMessage())->toBe('Para llegar en viernes, sábado o domingo hay que reservar con al menos 1 día de antelación.');
});
