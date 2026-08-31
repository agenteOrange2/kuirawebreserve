<?php

use App\Actions\Inventory\CreateOrder;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\TransitionReservation;
use App\Events\RoomStatusChanged;
use App\Models\Guest;
use App\Models\Product;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id, 'price' => 500]);
});

it('guarda el número de documento encriptado', function () {
    $guest = Guest::create([
        'first_name' => 'Ana',
        'last_name' => 'García',
        'id_document_type' => 'ine',
        'id_document_number' => 'GARC800101MCHRNN01',
    ]);

    $raw = DB::table('guests')->where('id', $guest->id)->value('id_document_number');

    expect($guest->refresh()->id_document_number)->toBe('GARC800101MCHRNN01')
        ->and($raw)->not->toBe('GARC800101MCHRNN01')
        ->and($guest->full_name)->toBe('Ana García');
});

it('la reserva usa al huésped del CRM por guest_id', function () {
    $guest = Guest::create(['first_name' => 'Luis', 'last_name' => 'Pérez', 'phone' => '+52611111']);

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'guest_id' => $guest->id,
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(2)->setTime(12, 0),
        'confirmed' => true,
    ]);

    expect($reservation->guest_id)->toBe($guest->id)
        ->and($reservation->guest_name)->toBe('Luis Pérez');
});

it('crea huésped nuevo por teléfono y lo reutiliza en la siguiente reserva', function () {
    $first = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'guest_name' => 'Marta',
        'guest_phone' => '+52622222',
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(2)->setTime(12, 0),
        'confirmed' => true,
    ]);

    $second = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'guest_phone' => '+52622222',
        'starts_at' => now()->addDays(5)->setTime(15, 0),
        'ends_at' => now()->addDays(6)->setTime(12, 0),
        'confirmed' => true,
    ]);

    expect(Guest::count())->toBe(1)
        ->and($second->guest_id)->toBe($first->guest_id);
});

it('calcula métricas del huésped: visitas y gasto con consumos', function () {
    $guest = Guest::create(['first_name' => 'Rico', 'phone' => '+52633333']);

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'guest_id' => $guest->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'confirmed' => true,
    ]);

    $stay = app(TransitionReservation::class)->checkIn($reservation);

    // Consumo cargado a la habitación.
    $coca = Product::factory()->create(['property_id' => $this->property->id, 'price' => 30, 'stock_qty' => 10]);
    app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'stay_id' => $stay->id,
        'lines' => [['product_id' => $coca->id, 'qty' => 2]],
    ]);

    app(TransitionReservation::class)->checkOut($stay);

    $metrics = $guest->metrics();

    // Hospedaje $1000 (2 noches × 500) + consumos $60.
    expect($metrics['visits'])->toBe(1)
        ->and($metrics['total_spent'])->toBe(1060.0)
        ->and($metrics['cancellations'])->toBe(0);
});

it('purga definitivamente a un huésped archivado y su historial queda sin vínculo', function () {
    $guest = Guest::create(['first_name' => 'Paola', 'phone' => '+52666666']);

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'guest_id' => $guest->id,
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(2)->setTime(12, 0),
        'confirmed' => true,
    ]);

    // Primer borrado: tiene historial, así que se archiva (soft delete).
    app(App\Http\Controllers\Tenant\GuestController::class)->destroy($guest);

    expect(Guest::find($guest->id))->toBeNull()
        ->and(Guest::withTrashed()->find($guest->id))->not->toBeNull();

    // Segundo borrado sobre el archivado: purga definitiva; la reserva
    // se conserva pero pierde el vínculo (FK nullOnDelete).
    app(App\Http\Controllers\Tenant\GuestController::class)->destroy($guest->refresh());

    expect(Guest::withTrashed()->find($guest->id))->toBeNull()
        ->and($reservation->refresh()->guest_id)->toBeNull();
});

it('busca huéspedes por nombre, teléfono o email', function () {
    Guest::create(['first_name' => 'Carlos', 'last_name' => 'Santana', 'phone' => '+52644444', 'email' => 'carlos@mail.com']);
    Guest::create(['first_name' => 'Otro', 'phone' => '+52655555']);

    expect(Guest::search('santa')->count())->toBe(1)
        ->and(Guest::search('4444')->count())->toBe(1)
        ->and(Guest::search('carlos@')->count())->toBe(1)
        ->and(Guest::search('nadie')->count())->toBe(0);
});

it('cuenta como visita la reserva completada sin estancia (historial migrado)', function () {
    $guest = Guest::create(['first_name' => 'Migrada', 'phone' => '+52644444']);

    // Así llegó el historial del sitio anterior: reservas completadas sin
    // estancia, porque nadie capturó horas de entrada y salida.
    \App\Models\Reservation::create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'room_id' => $this->room->id,
        'rate_plan_id' => $this->plan->id,
        'guest_id' => $guest->id,
        'guest_name' => 'Migrada',
        'num_people' => 2,
        'starts_at' => now()->subDays(10)->setTime(14, 0),
        'ends_at' => now()->subDays(9)->setTime(11, 0),
        'status' => \App\Enums\ReservationStatus::Completed,
        'total_amount' => 3000,
        'source_channel' => 'web',
    ]);

    $metrics = $guest->metrics();

    expect($metrics['visits'])->toBe(1)
        ->and($metrics['total_spent'])->toBe(3000.0)
        ->and($metrics['last_visit'])->toBe(now()->subDays(10)->format('d/m/Y'));
});
