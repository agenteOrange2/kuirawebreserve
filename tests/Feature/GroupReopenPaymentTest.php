<?php

use App\Actions\Reservations\CreateGroupReservation;
use App\Actions\Reservations\RegisterReservationPayment;
use App\Actions\Reservations\TransitionReservation;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\GroupReservationController;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Request;

// Caso real cabañas 2026-09-11 (GRP2026-0145, Santiago Montoya): grupo de 4
// cabañas cancelado, con $4,500 en efectivo entregados por el responsable.
// No había cómo reabrirlo ni cómo registrar ese dinero.

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Cabaña Sencilla',
        'capacity' => 4,
    ]);
    Room::factory()->count(4)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'max_occupancy' => 4,
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 3000,
    ]);
});

function grupoDeCuatro(): ReservationGroup
{
    return app(CreateGroupReservation::class)->handle([
        'mode' => 'night',
        'starts_at' => now()->addDays(20)->setTime(14, 0),
        'ends_at' => now()->addDays(21)->setTime(11, 0),
        'guest_name' => 'Santiago Montoya Contreras',
        'confirmed' => true,
        'lines' => [['room_type_id' => test()->roomType->id, 'rooms' => 4, 'adults' => 2]],
    ]);
}

function cancelarGrupo(ReservationGroup $group): void
{
    app(GroupReservationController::class)->cancel(
        Request::create('/x', 'POST'),
        $group,
        app(TransitionReservation::class),
    );
}

it('reabre el grupo completo con sus mismos códigos', function () {
    $group = grupoDeCuatro();
    $codigos = $group->reservations->map->displayCode()->sort()->values()->all();
    cancelarGrupo($group);

    expect($group->fresh()->reservations->pluck('status')->unique()->all())
        ->toBe([ReservationStatus::Cancelled]);

    $response = app(GroupReservationController::class)->reopen(
        Request::create('/x', 'PATCH', ['confirmed' => true]),
        $group->fresh(),
        app(TransitionReservation::class),
    );

    $reabierto = $group->fresh()->load('reservations');

    expect($response->getStatusCode())->toBe(200)
        ->and($reabierto->reservations->pluck('status')->unique()->all())->toBe([ReservationStatus::Confirmed])
        ->and($reabierto->reservations->map->displayCode()->sort()->values()->all())->toBe($codigos);
});

it('todo o nada: si una cabaña ya se vendió, no reabre ninguna', function () {
    $group = grupoDeCuatro();
    cancelarGrupo($group);

    // Alguien más se llevó una de las cuatro para esas fechas.
    app(\App\Actions\Reservations\CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'starts_at' => now()->addDays(20)->setTime(14, 0),
        'ends_at' => now()->addDays(21)->setTime(11, 0),
        'guest_name' => 'Otro huésped',
        'confirmed' => true,
    ]);

    $response = app(GroupReservationController::class)->reopen(
        Request::create('/x', 'PATCH'),
        $group->fresh(),
        app(TransitionReservation::class),
    );

    expect($response->getStatusCode())->toBe(422)
        ->and(json_decode($response->getContent(), true)['message'])->toContain('No se reabrió ninguna')
        ->and($group->fresh()->reservations->pluck('status')->unique()->all())->toBe([ReservationStatus::Cancelled]);
});

it('registra un pago parcial del grupo repartido entre sus cabañas', function () {
    $group = grupoDeCuatro();

    // $4,500 en efectivo sobre un total de $12,000.
    $response = app(GroupReservationController::class)->registerPayment(
        Request::create('/x', 'POST', ['amount' => 4500, 'method' => 'cash']),
        $group,
        app(RegisterReservationPayment::class),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and(round((float) Payment::sum('amount'), 2))->toBe(4500.0)
        // Se repartió entre las cuatro, no se cargó todo a una.
        ->and(Payment::query()->distinct()->count('reservation_id'))->toBe(4)
        ->and(round($group->fresh()->totalAmount(), 2))->toBe(12000.0);

    $pendiente = $group->fresh()->reservations->sum(fn (Reservation $r) => $r->pendingBalance());
    expect(round($pendiente, 2))->toBe(7500.0);
});

it('no acepta un pago mayor al saldo del grupo ni transferencia sin folio', function () {
    $group = grupoDeCuatro();

    $exceso = app(GroupReservationController::class)->registerPayment(
        Request::create('/x', 'POST', ['amount' => 99999, 'method' => 'cash']),
        $group,
        app(RegisterReservationPayment::class),
    );

    expect($exceso->getStatusCode())->toBe(422)
        ->and(json_decode($exceso->getContent(), true)['message'])->toContain('excede el saldo')
        ->and(Payment::count())->toBe(0);

    expect(fn () => app(GroupReservationController::class)->registerPayment(
        Request::create('/x', 'POST', ['amount' => 1000, 'method' => 'transfer']),
        $group,
        app(RegisterReservationPayment::class),
    ))->toThrow(\Illuminate\Validation\ValidationException::class);
});
