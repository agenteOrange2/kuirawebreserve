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

it('el buscador encuentra por nombre completo y por teléfono, escrito como sea', function () {
    Guest::create([
        'first_name' => 'Karla',
        'last_name' => 'Villalobos Mena',
        'phone' => '+52 614 586 9225',
        'email' => 'karlavm@gmail.com',
    ]);

    // El nombre tal como se ve en la lista: antes daba cero resultados
    // porque comparaba la frase entera contra nombre y apellido por
    // separado, y de ahí salían huéspedes duplicados al reservar.
    $porNombre = propsDeHuespedes('index', ['q' => 'Karla Villalobos']);
    expect($porNombre['guests']['total'])->toBe(1);

    // El teléfono guardado con formato, tecleado sin él (y al revés).
    $soloDigitos = propsDeHuespedes('index', ['q' => '6145869225']);
    expect($soloDigitos['guests']['total'])->toBe(1);

    $conFormato = propsDeHuespedes('index', ['q' => '+52 614 586 9225']);
    expect($conFormato['guests']['total'])->toBe(1);

    // Y lo que no es de nadie sigue sin traer a nadie.
    expect(propsDeHuespedes('index', ['q' => 'Fulano Inexistente'])['guests']['total'])->toBe(0);
});

it('el directorio trae visitas, lo gastado y la última visita sin consultar por fila', function () {
    // Una estancia cerrada de $1,500 y una reserva completada sin estancia
    // de $3,000: el gasto son las dos, las visitas también.
    $conEstancia = reservaDeHuesped(['total_amount' => 1500]);
    Stay::create([
        'reservation_id' => $conEstancia->id,
        'guest_id' => test()->guest->id,
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => test()->guest->full_name,
        'num_people' => 2,
        'check_in_at' => now()->subDays(5)->setTime(15, 0),
        'planned_end_at' => now()->subDays(4)->setTime(12, 0),
        'check_out_at' => now()->subDays(4)->setTime(12, 0),
        'status' => Stay::STATUS_COMPLETED,
        'amount' => 1500,
        'channel' => 'web',
    ]);
    reservaDeHuesped(['total_amount' => 3000]);

    $fila = collect(propsDeHuespedes('index')['guests']['data'])
        ->firstWhere('id', test()->guest->id);

    // El select de la consulta no puede pisar los conteos de withVisits:
    // cuando pasó, el directorio entero decía "0 visitas".
    expect($fila['visits'])->toBe(2)
        ->and($fila['total_spent'])->toEqual(4500)
        ->and($fila['last_visit'])->toBe(now()->subDays(5)->format('d/m/Y'));
});

it('el directorio se ordena por lo que ha dejado cada huésped', function () {
    reservaDeHuesped(['total_amount' => 1000]);

    $gastalon = Guest::create(['first_name' => 'Gastalón', 'last_name' => 'Fiel']);
    reservaDeHuesped(['guest_id' => $gastalon->id, 'total_amount' => 9000]);

    $orden = collect(propsDeHuespedes('index', ['sort' => 'spent'])['guests']['data'])
        ->pluck('full_name')
        ->all();

    expect($orden[0])->toBe('Gastalón Fiel');
});

it('el CSV del directorio respeta el filtro y trae las cifras de cada fila', function () {
    reservaDeHuesped(['total_amount' => 2500]);
    Guest::create(['first_name' => 'Nadie', 'last_name' => 'Buscado']);

    $request = Request::create('/huespedes/exportar', 'GET', ['q' => 'Rosaura']);
    $request->setUserResolver(fn () => test()->user);

    $csv = app(GuestsPageController::class)->export($request);

    ob_start();
    $csv->sendContent();
    $contenido = ob_get_clean();

    expect($contenido)->toContain('Rosaura Quintero')
        ->and($contenido)->toContain('2500.00')
        ->and($contenido)->not->toContain('Nadie Buscado');
});

it('la ficha resume al huésped: noches, ticket, su habitación y por dónde reserva', function () {
    // Dos noches en la 104 y una en otra: la 104 es "su habitación".
    $otra = Room::factory()->create([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
        'number' => '210',
    ]);

    foreach ([1, 2] as $i) {
        $reserva = reservaDeHuesped([
            'total_amount' => 2000,
            'source_channel' => 'whatsapp',
            'starts_at' => now()->subMonths($i)->setTime(15, 0),
            'ends_at' => now()->subMonths($i)->addDays(2)->setTime(12, 0),
        ]);
        Stay::create([
            'reservation_id' => $reserva->id,
            'guest_id' => test()->guest->id,
            'room_id' => test()->room->id,
            'rate_plan_id' => test()->plan->id,
            'guest_name' => test()->guest->full_name,
            'num_people' => 2,
            'check_in_at' => now()->subMonths($i)->setTime(15, 0),
            'planned_end_at' => now()->subMonths($i)->addDays(2)->setTime(12, 0),
            'check_out_at' => now()->subMonths($i)->addDays(2)->setTime(12, 0),
            'status' => Stay::STATUS_COMPLETED,
            'amount' => 2000,
            'channel' => 'whatsapp',
        ]);
    }

    reservaDeHuesped([
        'room_id' => $otra->id,
        'total_amount' => 1000,
        'starts_at' => now()->subMonths(6)->setTime(15, 0),
        'ends_at' => now()->subMonths(6)->addDay()->setTime(12, 0),
    ]);

    $metrics = propsDeHuespedes('show', [], test()->guest)['metrics'];

    expect($metrics['visits'])->toBe(3)
        // 2 + 2 noches de las estancias y 1 de la reserva sin estancia.
        ->and($metrics['nights'])->toBe(5)
        ->and($metrics['total_spent'])->toEqual(5000)
        ->and($metrics['average_ticket'])->toEqual(round(5000 / 3, 2))
        // La 104 la repitió; la 210 fue una sola vez.
        ->and($metrics['favorite_room'])->toBe('104')
        ->and($metrics['last_visit_ago'])->toContain('hace');
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
