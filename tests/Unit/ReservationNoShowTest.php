<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\TransitionReservation;
use App\Enums\ReservationStatus;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
    ]);
    $this->ratePlan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 500,
    ]);
});

it('marks a reservation as no-show and releases the room for future reservations', function () {
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->ratePlan->id,
        'room_id' => $this->room->id,
        'guest_name' => 'Huésped Test',
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(2)->setTime(12, 0),
        'confirmed' => true,
    ]);

    app(TransitionReservation::class)->cancel($reservation, null, ReservationStatus::NoShow);

    expect($reservation->refresh()->status)->toBe(ReservationStatus::NoShow);

    $replacement = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->ratePlan->id,
        'room_id' => $this->room->id,
        'guest_name' => 'Nuevo huésped',
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(2)->setTime(12, 0),
        'confirmed' => true,
    ]);

    expect($replacement->room_id)->toBe($this->room->id);
});
