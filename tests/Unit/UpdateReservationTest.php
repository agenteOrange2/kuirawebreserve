<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\UpdateReservation;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Suite',
    ]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '201',
    ]);
    $this->ratePlan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'name' => 'Noche suite',
        'price' => 1400,
    ]);
});

it('updates a reservation while ignoring itself during availability validation', function () {
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->ratePlan->id,
        'room_id' => $this->room->id,
        'guest_name' => 'Huésped Demo',
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(2)->setTime(12, 0),
        'confirmed' => true,
        'notes' => 'Ventana alta',
    ]);

    $updated = app(UpdateReservation::class)->handle($reservation, [
        'rate_plan_id' => $this->ratePlan->id,
        'starts_at' => $reservation->starts_at->format('Y-m-d H:i:s'),
        'ends_at' => $reservation->ends_at->format('Y-m-d H:i:s'),
        'guest_name' => 'Huésped Editado',
        'num_people' => 3,
        'notes' => 'Llega tarde',
    ]);

    expect($updated->refresh()->room_id)->toBe($this->room->id)
        ->and($updated->guest_name)->toBe('Huésped Editado')
        ->and($updated->num_people)->toBe(3)
        ->and($updated->notes)->toBe('Llega tarde');
});
