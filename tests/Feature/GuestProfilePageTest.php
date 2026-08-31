<?php

use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\GuestsPageController;
use App\Models\Guest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '104',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1500,
    ]);

    foreach (['guests.manage', 'guests.view-documents'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $this->user = User::factory()->create();
    $this->user->givePermissionTo(['guests.manage', 'guests.view-documents']);
    $this->guest = Guest::create(['first_name' => 'Rosaura Quintero', 'phone' => '6563119864']);
});

function reservaDeHuesped(array $overrides = []): Reservation
{
    return Reservation::create(array_replace([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_id' => test()->guest->id,
        'guest_name' => test()->guest->full_name,
        'num_people' => 2,
        'starts_at' => now()->startOfYear()->addMonths(3)->setTime(14, 0),
        'ends_at' => now()->startOfYear()->addMonths(3)->addDay()->setTime(11, 0),
        'status' => ReservationStatus::Completed,
        'total_amount' => 3000,
        'source_channel' => 'web',
    ], $overrides));
}

/** Props Inertia de las páginas de huéspedes. */
function propsDeHuespedes(string $method, array $query = [], ?Guest $guest = null): array
{
    $request = Request::create('/huespedes', 'GET', $query);
    $request->headers->set('X-Inertia', 'true');
    app()->instance('request', $request);
    $request->setUserResolver(fn () => test()->user);

    $controller = app(GuestsPageController::class);
    $response = $guest ? $controller->{$method}($request, $guest) : $controller->{$method}($request);

    return $response->toResponse($request)->getData(true)['props'];
}

it('el directorio cuenta las visitas del historial migrado y avisa la próxima llegada', function () {
    // Historial del sitio anterior: completada, sin estancia registrada.
    reservaDeHuesped();
    // Y algo por venir.
    reservaDeHuesped([
        'status' => ReservationStatus::Confirmed,
        'starts_at' => now()->addDays(9)->setTime(14, 0),
        'ends_at' => now()->addDays(10)->setTime(11, 0),
    ]);

    $fila = collect(propsDeHuespedes('index')['guests']['data'])->firstWhere('id', $this->guest->id);

    expect($fila['visits'])->toBe(1)
        ->and($fila['next_arrival'])->toBe(now()->addDays(9)->format('d/m/Y'));
});

it('la ficha funde la estancia en su reserva y agrupa lo pasado por año', function () {
    $conEstancia = reservaDeHuesped(['total_amount' => 3000]);
    Stay::create([
        'room_id' => $this->room->id,
        'reservation_id' => $conEstancia->id,
        'rate_plan_id' => $this->plan->id,
        'guest_id' => $this->guest->id,
        'guest_name' => $this->guest->full_name,
        'check_in_at' => $conEstancia->starts_at,
        'planned_end_at' => $conEstancia->ends_at,
        'check_out_at' => $conEstancia->ends_at,
        'status' => Stay::STATUS_COMPLETED,
        'amount' => 3000,
    ]);

    // Llegó sin reserva: fila propia, no cuelga de ninguna reserva.
    Stay::create([
        'room_id' => $this->room->id,
        'rate_plan_id' => $this->plan->id,
        'guest_id' => $this->guest->id,
        'guest_name' => $this->guest->full_name,
        'check_in_at' => now()->startOfYear()->addMonths(5),
        'planned_end_at' => now()->startOfYear()->addMonths(5)->addDay(),
        'check_out_at' => now()->startOfYear()->addMonths(5)->addDay(),
        'status' => Stay::STATUS_COMPLETED,
        'amount' => 1500,
    ]);

    $porVenir = reservaDeHuesped([
        'status' => ReservationStatus::Confirmed,
        'starts_at' => now()->addDays(5)->setTime(14, 0),
        'ends_at' => now()->addDays(6)->setTime(11, 0),
        'total_amount' => 4500,
    ]);

    $history = propsDeHuespedes('show', [], $this->guest)['history'];

    // Lo que viene, aparte de lo que ya pasó.
    expect($history['upcoming'])->toHaveCount(1)
        ->and($history['upcoming'][0]['key'])->toBe('r'.$porVenir->id);

    $anio = collect($history['years'])->firstWhere('year', (int) now()->format('Y'));

    // Dos filas: la visita con estancia (UNA sola, no repetida) y el walk-in.
    expect($anio['visits'])->toBe(2)
        ->and($anio['total'])->toEqual(4500)
        ->and(collect($anio['rows'])->pluck('key')->all())
        ->toBe(['s'.Stay::query()->whereNull('reservation_id')->value('id'), 'r'.$conEstancia->id]);

    $merged = collect($anio['rows'])->firstWhere('key', 'r'.$conEstancia->id);

    expect($merged['room'])->toBe('104')
        ->and($merged['checked_in_at'])->toBe('14:00')
        ->and($merged['kind'])->toBe('reservation');

    // El contador mira todo el historial, no solo lo que se pinta.
    expect($history['total'])->toBe(3);
});
