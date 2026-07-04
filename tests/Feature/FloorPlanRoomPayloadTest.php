<?php

use App\Enums\ReservationStatus;
use App\Models\Order;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomStatusLog;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Models\Zone;

beforeEach(function () {
    $this->travelTo(now()->startOfDay()->addHours(10));

    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->zone = Zone::factory()->create(['property_id' => $this->property->id, 'name' => 'Planta baja']);
    $this->roomType = RoomType::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Suite Deluxe',
        'capacity' => 4,
        'base_price' => 1200,
        'amenities' => ['wifi', 'jacuzzi'],
    ]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'zone_id' => $this->zone->id,
        'room_type_id' => $this->roomType->id,
        'number' => '204',
        'status' => 'occupied',
        'notes' => 'Vista al jardín',
    ]);
    $this->nightPlan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'name' => 'Noche suite',
        'price' => 1200,
    ]);
    $this->blockPlan = RatePlan::factory()->block(180, 350)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'name' => 'Rato 3 horas',
    ]);
    $this->user = User::factory()->create(['name' => 'Recepción']);
});

it('serializes floor plan payload with rates, active stay, upcoming reservation and today history', function () {
    $guest = \App\Models\Guest::create([
        'first_name' => 'Ana',
        'last_name' => 'García',
        'phone' => '+525511112233',
    ]);

    $stay = Stay::create([
        'room_id' => $this->room->id,
        'rate_plan_id' => $this->blockPlan->id,
        'guest_id' => $guest->id,
        'guest_name' => $guest->full_name,
        'check_in_at' => now()->subHour(),
        'planned_end_at' => now()->addHours(2),
        'status' => Stay::STATUS_ACTIVE,
        'amount' => 350,
        'channel' => 'walk_in',
        'created_by' => $this->user->id,
    ]);

    Order::create([
        'property_id' => $this->property->id,
        'stay_id' => $stay->id,
        'status' => Order::STATUS_COMPLETED,
        'total' => 180,
        'total_cost' => 75,
        'created_by' => $this->user->id,
    ]);

    Order::create([
        'property_id' => $this->property->id,
        'stay_id' => $stay->id,
        'status' => Order::STATUS_VOID,
        'total' => 999,
        'total_cost' => 0,
        'created_by' => $this->user->id,
    ]);

    Reservation::create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'room_id' => $this->room->id,
        'rate_plan_id' => $this->nightPlan->id,
        'guest_id' => $guest->id,
        'guest_name' => $guest->full_name,
        'starts_at' => now()->addHours(5),
        'ends_at' => now()->addDay()->setTime(12, 0),
        'status' => ReservationStatus::Confirmed,
        'source_channel' => 'web',
        'total_amount' => 1200,
        'created_by' => $this->user->id,
    ]);

    $reservedLog = RoomStatusLog::create([
        'room_id' => $this->room->id,
        'from_status' => 'available',
        'to_status' => 'reserved',
        'changed_by' => $this->user->id,
    ]);

    RoomStatusLog::query()
        ->whereKey($reservedLog->id)
        ->update(['created_at' => now()->subHours(4)]);

    $occupiedLog = RoomStatusLog::create([
        'room_id' => $this->room->id,
        'from_status' => 'reserved',
        'to_status' => 'occupied',
        'changed_by' => $this->user->id,
    ]);

    RoomStatusLog::query()
        ->whereKey($occupiedLog->id)
        ->update(['created_at' => now()->subMinutes(50)]);

    $oldLog = RoomStatusLog::create([
        'room_id' => $this->room->id,
        'from_status' => 'dirty',
        'to_status' => 'cleaning',
        'changed_by' => $this->user->id,
    ]);

    RoomStatusLog::query()
        ->whereKey($oldLog->id)
        ->update(['created_at' => now()->subDay()]);

    $payload = Room::query()
        ->whereKey($this->room->id)
        ->with([
            'zone:id,name',
            'roomType:id,name,capacity,base_price,amenities',
            'roomType.ratePlans' => fn ($query) => $query
                ->select(['id', 'room_type_id', 'name', 'type', 'price', 'duration_minutes', 'active'])
                ->where('active', true)
                ->orderBy('price'),
            'activeStay' => fn ($query) => $query
                ->with(['guest:id,first_name,last_name', 'ratePlan:id,name'])
                ->withSum(
                    ['orders as consumos_total' => fn ($orderQuery) => $orderQuery->where('status', Order::STATUS_COMPLETED)],
                    'total',
                ),
            'upcomingReservation' => fn ($query) => $query
                ->with(['guest:id,first_name,last_name', 'ratePlan:id,name']),
            'statusLogs' => fn ($query) => $query
                ->where('created_at', '>=', now()->startOfDay())
                ->latest('created_at')
                ->limit(8)
                ->with('changedBy:id,name'),
        ])
        ->firstOrFail()
        ->toFloorPlanPayload();

    expect($payload['zone'])->toBe('Planta baja')
        ->and($payload['room_type'])->toBe('Suite Deluxe')
        ->and($payload['amenities'])->toBe(['wifi', 'jacuzzi'])
        ->and($payload['rate_plans'])->toHaveCount(2)
        ->and($payload['rate_plans'][0]['name'])->toBe('Rato 3 horas')
        ->and($payload['active_stay']['guest_name'])->toBe('Ana García')
        ->and($payload['active_stay']['rate_plan'])->toBe('Rato 3 horas')
        ->and($payload['active_stay']['consumos_total'])->toBe(180.0)
        ->and($payload['active_stay']['total_due'])->toBe(530.0)
        ->and($payload['active_stay']['is_overdue'])->toBeFalse()
        ->and($payload['upcoming_reservation']['status'])->toBe(ReservationStatus::Confirmed->value)
        ->and($payload['upcoming_reservation']['code'])->toBe('RES-2026-0001')
        ->and($payload['upcoming_reservation']['guest_name'])->toBe('Ana García')
        ->and($payload['upcoming_reservation']['starts_today'])->toBeTrue()
        ->and($payload['today_history'])->toHaveCount(2)
        ->and($payload['today_history'][0]['to_label'])->toBe('Ocupada');
});
