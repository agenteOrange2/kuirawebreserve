<?php

use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\ReservationRackController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Http\Request;

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
        'price' => 900,
    ]);
    $this->user = User::factory()->create();
});

function rackPayload(array $params = []): array
{
    $request = Request::create('/api/reservations/rack', 'GET', $params);

    return app(ReservationRackController::class)($request)->getData(true);
}

function makeRackReservation(array $overrides = []): Reservation
{
    return Reservation::create([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Ana García',
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(3)->setTime(12, 0),
        'status' => ReservationStatus::Confirmed,
        'source_channel' => 'panel',
        'total_amount' => 1800,
        'created_by' => test()->user->id,
        ...$overrides,
    ]);
}

it('arma el rack agrupado por tipo con las barras del rango', function () {
    makeRackReservation();

    $payload = rackPayload(['days' => 14]);

    expect($payload['days'])->toHaveCount(14)
        ->and($payload['days'][0])->toBe(now()->toDateString())
        ->and($payload['groups'])->toHaveCount(1)
        ->and($payload['groups'][0]['type'])->toBe('Sencilla')
        ->and($payload['groups'][0]['rate_plan_id'])->toBe($this->plan->id)
        ->and($payload['groups'][0]['rooms'][0]['number'])->toBe('101')
        ->and($payload['groups'][0]['rooms'][0]['entries'])->toHaveCount(1);

    $entry = $payload['groups'][0]['rooms'][0]['entries'][0];

    expect($entry['kind'])->toBe('reservation')
        ->and($entry['guest'])->toBe('Ana García')
        ->and($entry['tone'])->toBe('info')
        ->and($entry['start'])->toBe(now()->addDay()->toDateString())
        ->and($entry['end'])->toBe(now()->addDays(3)->toDateString());
});

it('excluye holds vencidos y reservas fuera del rango', function () {
    // Hold vencido: no ocupa.
    makeRackReservation([
        'status' => ReservationStatus::Pending,
        'hold_expires_at' => now()->subMinutes(5),
    ]);

    // Fuera del rango consultado.
    makeRackReservation([
        'starts_at' => now()->addDays(40),
        'ends_at' => now()->addDays(42),
    ]);

    // Hold vigente: sí ocupa, en tono warning.
    makeRackReservation([
        'status' => ReservationStatus::Pending,
        'hold_expires_at' => now()->addMinutes(20),
    ]);

    $payload = rackPayload(['days' => 14]);
    $entries = $payload['groups'][0]['rooms'][0]['entries'];

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['status'])->toBe('pending')
        ->and($entries[0]['tone'])->toBe('warning');
});

it('las estancias activas aparecen como En casa y las checked_in no se duplican', function () {
    $reservation = makeRackReservation([
        'starts_at' => now()->subDay(),
        'status' => ReservationStatus::CheckedIn,
    ]);

    Stay::create([
        'room_id' => $this->room->id,
        'rate_plan_id' => $this->plan->id,
        'reservation_id' => $reservation->id,
        'guest_name' => 'Ana García',
        'check_in_at' => now()->subDay(),
        'planned_end_at' => now()->addDays(2),
        'status' => Stay::STATUS_ACTIVE,
        'amount' => 1800,
        'channel' => 'panel',
        'created_by' => $this->user->id,
    ]);

    $payload = rackPayload(['days' => 7]);
    $entries = $payload['groups'][0]['rooms'][0]['entries'];

    // Solo la estancia (la reserva checked_in se representa por su stay).
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['kind'])->toBe('stay')
        ->and($entries[0]['status_label'])->toBe('En casa')
        ->and($entries[0]['tone'])->toBe('primary')
        ->and($entries[0]['reservation_id'])->toBe($reservation->id);
});
