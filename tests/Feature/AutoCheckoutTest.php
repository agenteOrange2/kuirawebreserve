<?php

use App\Actions\Reservations\CreateWalkInStay;
use App\Enums\RoomStatus;
use App\Events\RoomStatusChanged;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
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
    ]);
    $this->blockPlan = RatePlan::factory()->block(180, 250)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
});

function makeOverdueStay(int $minutesPast): Stay
{
    $stay = app(CreateWalkInStay::class)->handle([
        'rate_plan_id' => test()->blockPlan->id,
        'room_id' => test()->room->id,
        'guest_name' => 'Rato Vencido',
    ]);

    // Simula que el bloque terminó hace N minutos.
    $stay->forceFill(['planned_end_at' => now()->subMinutes($minutesPast)])->saveQuietly();

    return $stay->refresh();
}

it('cierra la estancia vencida y manda la habitación a sucia', function () {
    $stay = makeOverdueStay(30); // gracia default: 15 min

    $this->artisan('stays:auto-checkout')->assertSuccessful();

    $stay->refresh();
    expect($stay->status)->toBe(Stay::STATUS_COMPLETED)
        ->and($stay->check_out_at)->not->toBeNull()
        ->and(test()->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Dirty->value);

    // El log del semáforo marca que fue automático (visible en el plano).
    $log = test()->room->statusLogs()->latest('id')->first();
    expect($log->to_status)->toBe(RoomStatus::Dirty->value)
        ->and($log->context['auto'] ?? false)->toBeTrue()
        ->and($log->changed_by)->toBeNull();
});

it('respeta el periodo de gracia antes de cerrar', function () {
    $stay = makeOverdueStay(5); // vencida hace 5 min < 15 de gracia

    $this->artisan('stays:auto-checkout')->assertSuccessful();

    expect($stay->refresh()->status)->toBe(Stay::STATUS_ACTIVE)
        ->and(test()->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Occupied->value);
});

it('no hace nada si el auto-checkout está deshabilitado', function () {
    config(['reservations.auto_checkout.enabled' => false]);
    $stay = makeOverdueStay(60);

    $this->artisan('stays:auto-checkout')->assertSuccessful();

    expect($stay->refresh()->status)->toBe(Stay::STATUS_ACTIVE);
});

it('aplica el price_modifier de la habitación al total', function () {
    test()->room->update(['price_modifier' => 50]);

    $stay = app(CreateWalkInStay::class)->handle([
        'rate_plan_id' => $this->blockPlan->id,
        'room_id' => $this->room->id,
        'guest_name' => 'Con Vista',
    ]);

    // 1 bloque de 3h: 250 + 50 de ajuste.
    expect((float) $stay->amount)->toBe(300.0);
});
