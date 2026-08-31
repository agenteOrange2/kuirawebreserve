<?php

use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\InHouseStaysPageController;
use App\Http\Controllers\Tenant\ReservationsPageController;
use App\Http\Controllers\Tenant\ReservationUpcomingPageController;
use App\Models\Guest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

function makeUpcomingReservation(array $overrides = []): Reservation
{
    return Reservation::create(array_replace([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Huésped Próximo',
        'num_people' => 2,
        'starts_at' => now()->addDays(2)->setTime(15, 0),
        'ends_at' => now()->addDays(3)->setTime(12, 0),
        'status' => ReservationStatus::Confirmed,
        'total_amount' => 1000,
        'source_channel' => 'web',
        'created_by' => test()->user->id,
    ], $overrides));
}

function makeStayAlojada(array $overrides = []): Stay
{
    return Stay::create(array_replace([
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Huésped Alojado',
        'num_people' => 2,
        'check_in_at' => now()->subHours(3),
        'planned_end_at' => now()->addHours(5),
        'status' => Stay::STATUS_ACTIVE,
        'amount' => 1000,
        'channel' => 'walk_in',
        'created_by' => test()->user->id,
    ], $overrides));
}

/** Props Inertia de un page-controller invocado como petición X-Inertia. */
function propsDePagina(string $controller, array $query = []): array
{
    $request = Request::create('/pagina', 'GET', $query);
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => test()->user);

    return app($controller)($request)->toResponse($request)->getData(true)['props'];
}

it('la lista de /reservas recorta las próximas a 30 y expone el total real', function () {
    foreach (range(1, 34) as $i) {
        makeUpcomingReservation([
            'guest_name' => "Huésped {$i}",
            'starts_at' => now()->addDays($i)->setTime(15, 0),
            'ends_at' => now()->addDays($i + 1)->setTime(12, 0),
        ]);
    }

    $props = propsDePagina(ReservationsPageController::class);

    expect($props['reservations'])->toHaveCount(30)
        ->and($props['upcomingTotal'])->toBe(34)
        // Las 30 más cercanas, no 30 cualesquiera.
        ->and($props['reservations'][0]['guest_name'])->toBe('Huésped 1');
});

it('todas las próximas paginan, filtran por estado y buscan por huésped, teléfono y código', function () {
    $guest = Guest::create(['first_name' => 'Rosa Telefónica', 'phone' => '6561234567']);

    $confirmada = makeUpcomingReservation(['guest_name' => 'Laura Confirmada']);
    $pendiente = makeUpcomingReservation([
        'guest_name' => 'Rosa Telefónica',
        'guest_id' => $guest->id,
        'status' => ReservationStatus::Pending,
    ]);
    // Lo que ya salió del flujo vive en el historial, no aquí.
    makeUpcomingReservation([
        'guest_name' => 'Pasado Completado',
        'status' => ReservationStatus::Completed,
        'starts_at' => now()->subDays(3),
        'ends_at' => now()->subDays(2),
    ]);

    $todas = propsDePagina(ReservationUpcomingPageController::class);
    expect($todas['reservations']['total'])->toBe(2)
        ->and(collect($todas['reservations']['data'])->pluck('guest_name'))
        ->not->toContain('Pasado Completado');

    $pendientes = propsDePagina(ReservationUpcomingPageController::class, ['status' => 'pending']);
    expect($pendientes['reservations']['total'])->toBe(1)
        ->and($pendientes['reservations']['data'][0]['id'])->toBe($pendiente->id);

    $porTelefono = propsDePagina(ReservationUpcomingPageController::class, ['q' => '6561234567']);
    expect($porTelefono['reservations']['total'])->toBe(1)
        ->and($porTelefono['reservations']['data'][0]['id'])->toBe($pendiente->id);

    $porCodigo = propsDePagina(ReservationUpcomingPageController::class, ['q' => $confirmada->displayCode()]);
    expect($porCodigo['reservations']['total'])->toBe(1)
        ->and($porCodigo['reservations']['data'][0]['id'])->toBe($confirmada->id);
});

it('los alojados de /reservas se recortan a 20 y la estancia enfocada siempre viaja', function () {
    $ultima = null;

    foreach (range(1, 22) as $i) {
        $ultima = makeStayAlojada([
            'guest_name' => "Alojado {$i}",
            'planned_end_at' => now()->addHours($i),
        ]);
    }

    $props = propsDePagina(ReservationsPageController::class);

    expect($props['stays'])->toHaveCount(20)
        ->and($props['staysTotal'])->toBe(22)
        ->and(collect($props['stays'])->pluck('id'))->not->toContain($ultima->id);

    // Con ?stay= (el botón "Registrar salida" de /reservas/alojados) esa
    // estancia entra aunque cayera fuera del tope: si no, el modal de
    // salida no abriría nada.
    $enfocada = propsDePagina(ReservationsPageController::class, ['stay' => $ultima->id]);

    expect($enfocada['focusStayId'])->toBe($ultima->id)
        ->and(collect($enfocada['stays'])->pluck('id'))->toContain($ultima->id);
});

it('los alojados completos paginan y buscan por huésped, habitación o placa', function () {
    makeStayAlojada(['guest_name' => 'Ana Alojada', 'vehicle_plate' => 'ABC-123']);
    makeStayAlojada(['guest_name' => 'Beto Alojado']);
    // Una estancia cerrada no es "alojado ahora".
    makeStayAlojada([
        'guest_name' => 'Carlos Salido',
        'status' => Stay::STATUS_COMPLETED,
        'check_out_at' => now()->subHour(),
    ]);

    $todos = propsDePagina(InHouseStaysPageController::class);
    expect($todos['stays']['total'])->toBe(2)
        ->and(collect($todos['stays']['data'])->pluck('guest_name'))
        ->not->toContain('Carlos Salido');

    $porNombre = propsDePagina(InHouseStaysPageController::class, ['q' => 'Beto']);
    expect($porNombre['stays']['total'])->toBe(1)
        ->and($porNombre['stays']['data'][0]['guest_name'])->toBe('Beto Alojado');

    $porPlaca = propsDePagina(InHouseStaysPageController::class, ['q' => 'ABC-123']);
    expect($porPlaca['stays']['total'])->toBe(1)
        ->and($porPlaca['stays']['data'][0]['guest_name'])->toBe('Ana Alojada');

    $porHabitacion = propsDePagina(InHouseStaysPageController::class, ['q' => '101']);
    expect($porHabitacion['stays']['total'])->toBe(2);
});

it('la página de reservas no cobra consultas por fila (el pagado viaja en la lista)', function () {
    foreach (range(1, 30) as $i) {
        $reserva = makeUpcomingReservation([
            'guest_name' => "Huésped {$i}",
            'starts_at' => now()->addDays($i)->setTime(15, 0),
            'ends_at' => now()->addDays($i + 1)->setTime(12, 0),
        ]);

        \App\Models\Payment::create([
            'reservation_id' => $reserva->id,
            'amount' => 500,
            'method' => 'transfer',
            'paid_at' => now(),
        ]);
    }

    DB::enableQueryLog();
    $props = propsDePagina(ReservationsPageController::class);
    $consultas = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Antes cada fila preguntaba su pagado y su pendiente por separado y
    // releía los ajustes del hotel para la fianza: 30 reservas eran casi
    // 100 consultas de más. Ahora la lista entera cuesta menos de 20.
    expect($props['reservations'])->toHaveCount(30)
        ->and($props['reservations'][0]['paid_total'])->toEqual(500)
        ->and($consultas)->toBeLessThanOrEqual(22);
});

it('la página dice si el hotel puede cobrar en línea', function () {
    expect(propsDePagina(ReservationsPageController::class)['gatewayAvailable'])->toBeFalse();

    \App\Models\Central\PaymentGatewayLink::create([
        'tenant_id' => (string) tenant('id'),
        'provider' => 'stripe',
        'mode' => 'test',
        'public_key' => 'pk_test',
        'secret_key' => 'sk_test',
        'webhook_token' => \App\Models\Central\PaymentGatewayLink::generateToken(),
        'active' => true,
    ]);

    expect(propsDePagina(ReservationsPageController::class)['gatewayAvailable'])->toBeTrue();

    // Apagada por plataforma o por el hotel: el modal de pago debe ofrecer
    // transferencia, no un link que el backend ya no va a emitir.
    app(\App\Services\Payments\PaymentMethodGate::class)
        ->set((string) tenant('id'), 'stripe', false);

    expect(propsDePagina(ReservationsPageController::class)['gatewayAvailable'])->toBeFalse();
});
