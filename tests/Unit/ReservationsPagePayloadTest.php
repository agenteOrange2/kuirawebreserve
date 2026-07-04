<?php

use App\Actions\Reservations\CreateReservation;
use App\Http\Controllers\Tenant\ReservationsPageController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\TenantRolesSeeder;
use Illuminate\Http\Request;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--path' => 'database/migrations/tenant']);
    $this->seed(TenantRolesSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('owner');

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

it('builds the reservations page payload with prefill data and reservation timeline', function () {
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->ratePlan->id,
        'room_id' => $this->room->id,
        'guest_name' => 'Huésped Demo',
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(2)->setTime(12, 0),
        'confirmed' => true,
    ], $this->user);

    $request = Request::create('/reservas', 'GET', [
        'intent' => 'walkin',
        'room' => $this->room->id,
        'reservation' => $reservation->id,
    ]);
    $request->setUserResolver(fn () => $this->user);

    $response = app(ReservationsPageController::class)->__invoke($request);

    $reflection = new ReflectionProperty($response, 'props');
    $props = $reflection->getValue($response);
    $resolved = $response->resolveProperties($request, $props);

    expect($resolved['prefill']['intent'])->toBe('walkin')
        ->and($resolved['prefill']['room']['id'])->toBe($this->room->id)
        ->and($resolved['prefill']['room']['number'])->toBe('201')
        ->and($resolved['prefill']['room']['rate_plan_id'])->toBe($this->ratePlan->id)
        ->and($resolved['focusReservationId'])->toBe($reservation->id)
        ->and($resolved['reservations'])->toHaveCount(1)
        ->and($resolved['reservations'][0]['code'])->toBe($reservation->displayCode())
        ->and($resolved['reservations'][0]['room_id'])->toBe($this->room->id)
        ->and($resolved['reservations'][0]['rate_plan_id'])->toBe($this->ratePlan->id)
        ->and($resolved['reservations'][0]['guest_name'])->toBe('Huésped Demo')
        ->and($resolved['reservations'][0]['starts_at_input'])->toBe($reservation->starts_at->format('Y-m-d\TH:i'))
        ->and($resolved['reservations'][0]['ends_at_input'])->toBe($reservation->ends_at->format('Y-m-d\TH:i'))
        ->and($resolved['reservations'][0]['timeline'])->not->toBeEmpty()
        ->and($resolved['reservations'][0]['timeline'][0]['message'])->toBe('Reserva creada');
});
