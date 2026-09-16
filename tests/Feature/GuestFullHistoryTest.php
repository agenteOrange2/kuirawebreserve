<?php

use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\ReservationHistoryPageController;
use App\Models\Guest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

// Caso real cabañas 2026-09-15 (huésped 1166): la ficha ofrecía "Ver todo"
// y llevaba a /reservas/historial?q=<nombre completo>, que solo lista lo
// que ya salió del flujo. Su única reserva estaba vigente, así que la
// página salía vacía. Y el nombre como llave mezcla personas: en el mismo
// hotel hay una "DAMARIS GOMEZ" que no tiene nada que ver con ella.

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 3000,
    ]);

    Permission::findOrCreate('reservations.manage', 'web');
    $this->user = User::factory()->create();
    $this->user->givePermissionTo('reservations.manage');
});

function reservaDelHuesped(Guest $guest, array $overrides = []): Reservation
{
    return Reservation::create(array_replace([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_id' => $guest->id,
        'guest_name' => $guest->full_name,
        'num_people' => 2,
        'starts_at' => now()->addDays(3)->setTime(14, 0),
        'ends_at' => now()->addDays(4)->setTime(11, 0),
        'status' => ReservationStatus::Pending,
        'total_amount' => 3000,
        'source_channel' => 'agent',
        'created_by' => test()->user->id,
    ], $overrides));
}

/** Props Inertia del historial, como las recibe la página. */
function propsDelHistorial(array $query = []): array
{
    $request = Request::create('/reservas/historial', 'GET', $query);
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => test()->user);

    return app(ReservationHistoryPageController::class)($request)
        ->toResponse($request)
        ->getData(true)['props'];
}

it('con el huésped a la vista salen también sus reservas vigentes', function () {
    $damaris = Guest::create([
        'first_name' => 'Damaris Michelle Emiliano Crispín',
        'phone' => '5216562025344',
    ]);
    $reserva = reservaDelHuesped($damaris);

    $props = propsDelHistorial(['guest' => $damaris->id]);

    expect($props['reservations']['data'])->toHaveCount(1)
        ->and($props['reservations']['data'][0]['code'])->toBe($reserva->displayCode())
        ->and($props['guest']['full_name'])->toBe('Damaris Michelle Emiliano Crispín')
        ->and($props['filters']['guest'])->toBe($damaris->id);
});

it('sin huésped a la vista el archivo sigue siendo archivo', function () {
    $damaris = Guest::create(['first_name' => 'Damaris Michelle Emiliano Crispín']);
    reservaDelHuesped($damaris);

    // Lo que hacía el botón viejo: buscar su nombre en el archivo.
    $props = propsDelHistorial(['q' => 'Damaris Michelle Emiliano Crispín']);

    expect($props['reservations']['data'])->toBeEmpty()
        ->and($props['guest'])->toBeNull();
});

it('no mezcla a dos huéspedes que se llaman parecido', function () {
    $damaris = Guest::create(['first_name' => 'Damaris Michelle Emiliano Crispín']);
    $otra = Guest::create(['first_name' => 'DAMARIS GOMEZ']);

    reservaDelHuesped($damaris);
    reservaDelHuesped($otra, ['status' => ReservationStatus::Completed]);

    $props = propsDelHistorial(['guest' => $damaris->id]);

    expect($props['reservations']['data'])->toHaveCount(1)
        ->and($props['reservations']['data'][0]['guest_name'])->toBe('Damaris Michelle Emiliano Crispín');
});

it('el archivo del hotel no cambia: ahí solo vive lo que ya terminó', function () {
    $damaris = Guest::create(['first_name' => 'Damaris Michelle Emiliano Crispín']);
    reservaDelHuesped($damaris);
    reservaDelHuesped($damaris, ['status' => ReservationStatus::Cancelled]);

    $completo = propsDelHistorial();
    $delHuesped = propsDelHistorial(['guest' => $damaris->id]);

    expect($completo['reservations']['data'])->toHaveCount(1)
        ->and($delHuesped['reservations']['data'])->toHaveCount(2);
});
