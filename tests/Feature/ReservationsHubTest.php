<?php

use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\PendingReservationsPageController;
use App\Http\Controllers\Tenant\ReservationsHubController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Sencilla']);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1000,
    ]);

    Permission::findOrCreate('reservations.manage', 'web');
    $this->user = User::factory()->create();
    $this->user->givePermissionTo('reservations.manage');
});

/** Reserva de las que cuenta el tablero (futura y viva). */
function reservaDelTablero(array $overrides = []): Reservation
{
    return Reservation::create(array_replace([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Huésped Tablero',
        'num_people' => 2,
        'starts_at' => now()->addDays(2)->setTime(15, 0),
        'ends_at' => now()->addDays(3)->setTime(12, 0),
        'status' => ReservationStatus::Confirmed,
        'total_amount' => 1000,
        'source_channel' => 'web',
        'created_by' => test()->user->id,
    ], $overrides));
}

/** Estancia cerrada con saldo: una cuenta por cobrar. */
function estanciaConSaldo(array $overrides = []): Stay
{
    return Stay::create(array_replace([
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Se Fue Sin Pagar',
        'num_people' => 2,
        'check_in_at' => now()->subDays(2),
        'planned_end_at' => now()->subDay(),
        'check_out_at' => now()->subDay(),
        'auto_closed_at' => now()->subDay(),
        'status' => Stay::STATUS_COMPLETED,
        'amount' => 1000,
        'channel' => 'walk_in',
        'created_by' => test()->user->id,
    ], $overrides));
}

/** Props Inertia de un page-controller invocado como petición X-Inertia. */
function propsDelTablero(string $controller, array $query = []): array
{
    $request = Request::create('/pagina', 'GET', $query);
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => test()->user);

    return app($controller)($request)->toResponse($request)->getData(true)['props'];
}

it('el tablero cuenta los cuatro accesos sin traer ni una fila', function () {
    // Próximas: una llega hoy, otra en dos días.
    reservaDelTablero(['starts_at' => now()->addHours(3), 'ends_at' => now()->addDay()]);
    reservaDelTablero();
    // Apartado por confirmar, a punto de vencerse.
    reservaDelTablero([
        'status' => ReservationStatus::Pending,
        'hold_expires_at' => now()->addMinutes(10),
    ]);
    // Confirmada cuya llegada ya pasó y nadie registró: trabajo atorado.
    // Llega de AYER a propósito, para no contarla también como llegada de hoy.
    reservaDelTablero([
        'starts_at' => now()->subDay()->setTime(15, 0),
        'ends_at' => now()->addDay(),
    ]);
    // Historial.
    reservaDelTablero(['status' => ReservationStatus::Cancelled]);
    // En casa, con la salida vencida.
    Stay::create([
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Alojado Vencido',
        'num_people' => 1,
        'check_in_at' => now()->subDay(),
        'planned_end_at' => now()->subHour(),
        'status' => Stay::STATUS_ACTIVE,
        'amount' => 1000,
        'channel' => 'walk_in',
        'created_by' => test()->user->id,
    ]);
    estanciaConSaldo();

    $props = propsDelTablero(ReservationsHubController::class);

    expect($props['upcoming']['total'])->toBe(4)
        ->and($props['upcoming']['today'])->toBe(1)
        ->and($props['upcoming']['arrival_pending'])->toBe(1)
        ->and($props['inHouse']['total'])->toBe(1)
        ->and($props['inHouse']['overdue'])->toBe(1)
        ->and($props['pending']['total'])->toBe(1)
        ->and($props['pending']['expiring'])->toBe(1)
        ->and($props['pending']['settlements'])->toBe(1)
        ->and($props['history']['total'])->toBe(1)
        // El tablero solo cuenta: si algún día manda filas, esto lo caza.
        ->and($props)->not->toHaveKey('reservations');
});

it('las recién llegadas se etiquetan hoy/ayer, se caen al tercer día y apuntan a su área', function () {
    $creadaHoy = reservaDelTablero(['guest_name' => 'Entró Hoy', 'status' => ReservationStatus::Pending]);

    $ayer = reservaDelTablero(['guest_name' => 'Entró Ayer']);
    $ayer->created_at = now()->subDay();
    $ayer->save();

    $enCasa = reservaDelTablero(['guest_name' => 'Ya Llegó', 'status' => ReservationStatus::CheckedIn]);

    $vieja = reservaDelTablero(['guest_name' => 'De Hace Tres Días']);
    $vieja->created_at = now()->subDays(3);
    $vieja->save();

    $props = propsDelTablero(ReservationsHubController::class);
    $filas = collect($props['fresh']['rows'])->keyBy('guest_name');

    expect($filas)->toHaveCount(3)
        // La de hace tres días ya no estorba en el tablero.
        ->and($filas->has('De Hace Tres Días'))->toBeFalse()
        ->and($filas['Entró Hoy']['freshness'])->toBe('today')
        ->and($filas['Entró Ayer']['freshness'])->toBe('yesterday')
        // Cada una manda a donde se trabaja, según su estado.
        ->and($filas['Entró Hoy']['area'])->toBe('pending')
        ->and($filas['Entró Ayer']['area'])->toBe('upcoming')
        ->and($filas['Ya Llegó']['area'])->toBe('in-house')
        ->and($props['fresh']['today'])->toBe(2)
        ->and($props['fresh']['total'])->toBe(3)
        ->and($filas['Entró Hoy']['code'])->toBe($creadaHoy->displayCode())
        ->and($filas['Ya Llegó']['id'])->toBe($enCasa->id);
});

it('el tablero reenvía a la operación los enlaces que traen parámetros', function () {
    $reserva = reservaDelTablero();

    $request = Request::create('/reservas', 'GET', ['reservation' => $reserva->id]);
    $request->setUserResolver(fn () => test()->user);

    $response = app(ReservationsHubController::class)($request);

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getTargetUrl())->toContain('/reservas/operacion')
        ->and($response->getTargetUrl())->toContain('reservation='.$reserva->id);

    // Sin parámetros no reenvía: ese es el tablero.
    $limpia = Request::create('/reservas', 'GET');
    $limpia->headers->set('X-Inertia', 'true');
    $limpia->setUserResolver(fn () => test()->user);

    expect(app(ReservationsHubController::class)($limpia))->not->toBeInstanceOf(RedirectResponse::class);
});

it('pendientes pagina los apartados de diez en diez y asoma las cuentas sin cobrar', function () {
    foreach (range(1, 12) as $i) {
        reservaDelTablero([
            'guest_name' => "Apartado {$i}",
            'status' => ReservationStatus::Pending,
            'hold_expires_at' => now()->addMinutes($i),
        ]);
    }

    // Una confirmada NO es un pendiente por confirmar.
    reservaDelTablero(['guest_name' => 'Ya Confirmada']);
    estanciaConSaldo();

    $props = propsDelTablero(PendingReservationsPageController::class);

    expect($props['reservations']['total'])->toBe(12)
        ->and($props['reservations']['data'])->toHaveCount(10)
        // Primero lo que se vence antes: es lo que se pierde solo.
        ->and($props['reservations']['data'][0]['guest_name'])->toBe('Apartado 1')
        ->and(collect($props['reservations']['data'])->pluck('guest_name'))
        ->not->toContain('Ya Confirmada')
        ->and($props['settlements'])->toHaveCount(1)
        ->and($props['settlements'][0]['pending'])->toEqual(1000.0)
        ->and($props['settlements'][0]['auto_closed'])->toBeTrue()
        ->and($props['settlementsTotal'])->toBe(1);
});
