<?php

use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\ReservationsPageController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
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

function upcomingReservation(array $overrides = []): Reservation
{
    return Reservation::create(array_replace([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Huésped Bitácora',
        'num_people' => 2,
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(2)->setTime(12, 0),
        'status' => ReservationStatus::Confirmed,
        'total_amount' => 1000,
        'source_channel' => 'front_desk',
        'created_by' => test()->user->id,
    ], $overrides));
}

/** Props de /reservas con el usuario del test como autor de los cambios. */
function reservationProps(): array
{
    $request = Request::create('/reservas', 'GET');
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => test()->user);

    return app(ReservationsPageController::class)($request)
        ->toResponse($request)->getData(true)['props'];
}

it('la bitácora de la reserva muestra las últimas 8 líneas, más recientes primero', function () {
    $this->actingAs($this->user); // para que los cambios queden a su nombre

    $reservation = upcomingReservation();

    // 15 cambios registrables (total_amount sí va a la bitácora): debe
    // quedarse con los 8 más recientes.
    foreach (range(1, 15) as $i) {
        $reservation->update(['total_amount' => 1000 + $i]);
    }

    $timeline = collect(reservationProps()['reservations'])
        ->firstWhere('id', $reservation->id)['timeline'];

    expect($timeline)->toHaveCount(8)
        ->and($timeline[0]['at'])->not->toBeNull()
        // El autor viaja resuelto (se carga junto, no fila por fila).
        ->and($timeline[0]['by'])->toBe(test()->user->name);
});

it('cada reserva conserva su propia bitácora, no la de las demás', function () {
    $first = upcomingReservation(['guest_name' => 'Primera']);
    $second = upcomingReservation(['guest_name' => 'Segunda']);

    foreach (range(1, 10) as $i) {
        $first->update(['total_amount' => 1000 + $i]);
    }
    $second->update(['total_amount' => 2500]);

    $rows = collect(reservationProps()['reservations']);

    expect($rows->firstWhere('id', $first->id)['timeline'])->toHaveCount(8)
        // La segunda solo tiene su creación y su cambio: 2 líneas.
        ->and($rows->firstWhere('id', $second->id)['timeline'])->toHaveCount(2);
});

it('cargar /reservas no cuesta más consultas por tener más bitácora', function () {
    $reservation = upcomingReservation();
    $reservation->update(['total_amount' => 1100]);

    // Los permisos de Spatie se resuelven una vez y se quedan cacheados en
    // el modelo: se calientan aquí para no contarlos solo en la primera
    // medición y confundirlos con la bitácora.
    $this->user->can('reservations.manage');

    DB::flushQueryLog();
    DB::enableQueryLog();
    reservationProps();
    $baseline = count(DB::getQueryLog());
    DB::disableQueryLog();

    // 60 cambios más: antes entraban TODOS a memoria y cada línea iba por
    // su autor por separado; ahora el ROW_NUMBER los corta en SQL.
    foreach (range(1, 60) as $i) {
        $reservation->update(['total_amount' => 1100 + $i]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    reservationProps();
    $withHistory = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($withHistory)->toBe($baseline);
});

it('la lista de próximas se acota a un horizonte y dice cuántas quedan fuera', function () {
    // Dentro del horizonte
    upcomingReservation(['guest_name' => 'Cercana']);
    // Muy a futuro: no debe salir en la lista, pero sí contarse aparte
    upcomingReservation([
        'guest_name' => 'Lejana',
        'starts_at' => now()->addDays(200),
        'ends_at' => now()->addDays(201),
    ]);

    $props = reservationProps();

    expect($props['reservations'])->toHaveCount(1)
        ->and($props['reservations'][0]['guest_name'])->toBe('Cercana')
        // El total del horizonte no incluye la de dentro de 200 días.
        ->and($props['upcomingTotal'])->toBe(1)
        ->and($props['upcomingDays'])->toBe(90);
});
