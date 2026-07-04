<?php

use App\Actions\Reservations\CreateReservation;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->travelTo(now()->startOfDay()->addHours(10));
    $this->artisan('migrate:fresh', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create([
        'property_id' => $this->property->id,
    ]);
    $this->roomA = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
    ]);
    $this->roomB = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '102',
    ]);
    $this->ratePlan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 700,
    ]);
});

it('generates folio codes when creating reservations', function () {
    $first = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->ratePlan->id,
        'room_id' => $this->roomA->id,
        'guest_name' => 'Primer huésped',
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(2)->setTime(12, 0),
        'confirmed' => true,
    ]);

    $second = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->ratePlan->id,
        'room_id' => $this->roomB->id,
        'guest_name' => 'Segundo huésped',
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(2)->setTime(12, 0),
        'confirmed' => true,
    ]);

    expect($first->refresh()->code)->toBe('RES-2026-0001')
        ->and($second->refresh()->code)->toBe('RES-2026-0002');
});
