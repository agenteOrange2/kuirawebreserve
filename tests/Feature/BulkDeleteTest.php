<?php

use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\GuestController;
use App\Http\Controllers\Tenant\RoomController;
use App\Http\Controllers\Tenant\UserController;
use App\Models\Guest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    $this->property = Property::factory()->create();
});

function bulkDelete(string $controller, array $ids, ?User $actor = null): array
{
    $request = Request::create('/api', 'DELETE', ['ids' => $ids]);
    if ($actor) {
        $request->setUserResolver(fn () => $actor);
    }

    return app($controller)->destroyBulk($request)->getData(true);
}

it('huéspedes: borra los sin historial y conserva los que tienen reservas', function () {
    $roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $roomType->id]);
    $plan = RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $roomType->id, 'price' => 1000]);

    $libre = Guest::create(['first_name' => 'Libre', 'phone' => '5510000001']);
    $conHistorial = Guest::create(['first_name' => 'Con historial', 'phone' => '5510000002']);

    app(\App\Actions\Reservations\CreateReservation::class)->handle([
        'rate_plan_id' => $plan->id,
        'room_id' => $room->id,
        'starts_at' => now()->addDays(10)->setTime(15, 0),
        'ends_at' => now()->addDays(11)->setTime(12, 0),
        'confirmed' => true,
        'guest_id' => $conHistorial->id,
    ]);

    $result = bulkDelete(GuestController::class, [$libre->id, $conHistorial->id]);

    expect($result)->toBe(['deleted' => 1, 'skipped' => 1])
        ->and(Guest::whereKey($libre->id)->exists())->toBeFalse()
        ->and(Guest::whereKey($conHistorial->id)->exists())->toBeTrue();
});

it('habitaciones: borra las libres y conserva las con reservas próximas', function () {
    $roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $plan = RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $roomType->id, 'price' => 1000]);
    $libre = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $roomType->id]);
    $comprometida = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $roomType->id]);

    app(\App\Actions\Reservations\CreateReservation::class)->handle([
        'rate_plan_id' => $plan->id,
        'room_id' => $comprometida->id,
        'starts_at' => now()->addDays(10)->setTime(15, 0),
        'ends_at' => now()->addDays(11)->setTime(12, 0),
        'confirmed' => true,
        'guest_name' => 'Reserva próxima',
        'guest_phone' => '5599887766',
    ]);

    $result = bulkDelete(RoomController::class, [$libre->id, $comprometida->id]);

    expect($result)->toBe(['deleted' => 1, 'skipped' => 1])
        ->and(Room::whereKey($libre->id)->exists())->toBeFalse()
        ->and(Room::whereKey($comprometida->id)->exists())->toBeTrue();
});

it('usuarios: conserva el propio, el último owner y borra el resto sin actividad', function () {
    foreach (['owner', 'front-desk'] as $r) {
        Role::findOrCreate($r, 'web');
    }

    $me = User::factory()->create();
    $me->assignRole('owner');
    $owner2 = User::factory()->create();
    $owner2->assignRole('owner'); // ya no es el último owner
    $staff = User::factory()->create();
    $staff->assignRole('front-desk');

    // El último-owner solo dispara si queda uno; con 2 owners, ambos borrables
    // salvo el propio. Borramos: yo (skip: self), owner2 (ok), staff (ok).
    $result = bulkDelete(UserController::class, [$me->id, $owner2->id, $staff->id], $me);

    expect($result['deleted'])->toBe(2)
        ->and($result['skipped'])->toBe(1)
        ->and(User::whereKey($me->id)->exists())->toBeTrue()
        ->and(User::whereKey($staff->id)->exists())->toBeFalse();
});
