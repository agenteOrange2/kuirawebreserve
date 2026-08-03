<?php

use App\Http\Controllers\Tenant\GuestController;
use App\Models\Guest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    $this->property = Property::factory()->create();
});

function reservaPara(Guest $guest, Property $property): Reservation
{
    $roomType = RoomType::factory()->create(['property_id' => $property->id]);
    $room = Room::factory()->create(['property_id' => $property->id, 'room_type_id' => $roomType->id]);
    $plan = RatePlan::factory()->create(['property_id' => $property->id, 'room_type_id' => $roomType->id, 'price' => 1000]);

    return app(\App\Actions\Reservations\CreateReservation::class)->handle([
        'rate_plan_id' => $plan->id,
        'room_id' => $room->id,
        'starts_at' => now()->addDays(10)->setTime(15, 0),
        'ends_at' => now()->addDays(11)->setTime(12, 0),
        'confirmed' => true,
        'guest_id' => $guest->id,
    ]);
}

it('elimina definitivamente al huésped sin historial', function () {
    $guest = Guest::create(['first_name' => 'Sin historial', 'phone' => '5510000001']);

    $response = app(GuestController::class)->destroy($guest);

    expect($response->getStatusCode())->toBe(204)
        ->and(Guest::withTrashed()->whereKey($guest->id)->exists())->toBeFalse();
});

it('archiva al huésped con historial y su reserva lo sigue mostrando', function () {
    $guest = Guest::create(['first_name' => 'Con historial', 'phone' => '5510000002']);
    $reservation = reservaPara($guest, $this->property);

    $response = app(GuestController::class)->destroy($guest);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['archived'])->toBeTrue()
        // Fuera del directorio y del autocompletado...
        ->and(Guest::whereKey($guest->id)->exists())->toBeFalse()
        ->and(Guest::query()->search('Con historial')->exists())->toBeFalse()
        // ...pero recuperable y visible desde su historial (withTrashed).
        ->and(Guest::onlyTrashed()->whereKey($guest->id)->exists())->toBeTrue()
        ->and($reservation->fresh()->guest?->id)->toBe($guest->id);
});

it('restaura al huésped archivado al directorio', function () {
    $guest = Guest::create(['first_name' => 'Archivado', 'phone' => '5510000003']);
    reservaPara($guest, $this->property);
    app(GuestController::class)->destroy($guest);

    app(GuestController::class)->restore($guest->refresh());

    expect(Guest::whereKey($guest->id)->exists())->toBeTrue();
});
