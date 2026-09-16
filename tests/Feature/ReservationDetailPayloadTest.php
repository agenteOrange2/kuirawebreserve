<?php

use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\ReservationShowPageController;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * La ficha /reservas/{id} es la pantalla del dinero. Si le falta una clave
 * que el componente lee sin protección, la página se queda EN BLANCO y no
 * hay error en el log de PHP: el que truena es el navegador. Pasó con la
 * reserva 1713 de cabañas (2026-09-11) porque no viajaban los pagos.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $property = Property::factory()->create();
    $roomType = RoomType::factory()->create(['property_id' => $property->id]);
    $room = Room::factory()->create([
        'property_id' => $property->id,
        'room_type_id' => $roomType->id,
    ]);
    $plan = RatePlan::factory()->create([
        'property_id' => $property->id,
        'room_type_id' => $roomType->id,
        'price' => 3000,
    ]);

    $this->user = User::factory()->create();
    $this->reserva = Reservation::create([
        'property_id' => $property->id,
        'room_type_id' => $roomType->id,
        'room_id' => $room->id,
        'rate_plan_id' => $plan->id,
        'guest_name' => 'Huésped Con Ficha',
        'num_people' => 2,
        'starts_at' => now()->addDays(5)->setTime(14, 0),
        'ends_at' => now()->addDays(6)->setTime(11, 0),
        'status' => ReservationStatus::Pending,
        'total_amount' => 3000,
        'source_channel' => 'web',
        'created_by' => $this->user->id,
    // fresh() para que el modelo traiga los valores por defecto de la base
    // (payment_status nace 'unpaid'), como cualquier reserva ya guardada.
    ])->fresh();
});

function propsDeLaFicha(Reservation $reservation, User $user): array
{
    $request = Request::create("/reservas/{$reservation->id}", 'GET');
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => $user);

    return app(ReservationShowPageController::class)
        ->show($request, $reservation)
        ->toResponse($request)
        ->getData(true)['props'];
}

it('la ficha manda las claves del dinero que el componente lee sin protección', function () {
    $props = propsDeLaFicha($this->reserva, $this->user)['reservation'];

    // Sin `payments` (aunque sea vacío) la página se queda en blanco.
    expect($props)->toHaveKeys([
        'payments',
        'payment_request',
        'refunded_total',
        'refund_suggestion',
        'stay_id',
    ])
        ->and($props['payments'])->toBe([])
        ->and($props['payment_request'])->toBeNull()
        ->and($props['refunded_total'])->toEqual(0);
});

it('los abonos registrados viajan en la ficha con su método y su saldo', function () {
    Payment::create([
        'reservation_id' => $this->reserva->id,
        'amount' => 1500,
        'method' => 'transfer',
        'reference' => 'SPEI-0001',
        'paid_at' => now(),
    ]);

    $props = propsDeLaFicha($this->reserva->fresh(), $this->user)['reservation'];

    expect($props['payments'])->toHaveCount(1)
        ->and($props['payments'][0]['reference'])->toBe('SPEI-0001')
        ->and($props['payments'][0]['method'])->toBe(Payment::methodLabel('transfer'))
        ->and($props['pending_balance'])->toEqual(1500)
        ->and($props['paid_total'])->toEqual(1500);
});
