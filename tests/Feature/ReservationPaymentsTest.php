<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\RegisterReservationPayment;
use App\Actions\Reservations\TransitionReservation;
use App\Enums\PaymentStatus;
use App\Events\RoomStatusChanged;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);

    // Tarifa con cobro anticipado: 20% al reservar, liquidar 1 semana antes.
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 500,
        'deposit_percent' => 20,
        'payment_due_unit' => 'week',
        'payment_due_value' => 1,
    ]);
});

function reservar(array $overrides = []): \App\Models\Reservation
{
    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDays(30)->setTime(15, 0),
        'ends_at' => now()->addDays(32)->setTime(12, 0), // 2 noches → $1000
        'confirmed' => true,
        ...$overrides,
    ]);
}

it('calcula anticipo y fecha límite al crear la reserva', function () {
    $reservation = reservar();

    expect((float) $reservation->total_amount)->toBe(1000.0)
        ->and((float) $reservation->deposit_amount)->toBe(200.0) // 20%
        ->and($reservation->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and($reservation->payment_due_at->toDateString())
        ->toBe($reservation->starts_at->copy()->subWeek()->toDateString())
        ->and($reservation->isPaymentOverdue())->toBeFalse();
});

it('los abonos mueven el estado: sin pago → anticipo → pagada', function () {
    $reservation = reservar();
    $action = app(RegisterReservationPayment::class);

    $action->handle($reservation, ['amount' => 200, 'method' => 'transfer', 'reference' => 'SPEI-123']);
    expect($reservation->refresh()->payment_status)->toBe(PaymentStatus::DepositPaid)
        ->and($reservation->pendingBalance())->toBe(800.0);

    $action->handle($reservation, ['amount' => 800, 'method' => 'cash']);
    expect($reservation->refresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($reservation->pendingBalance())->toBe(0.0)
        ->and($reservation->payments()->count())->toBe(2);
});

it('rechaza abonos que exceden el pendiente', function () {
    $reservation = reservar();

    app(RegisterReservationPayment::class)->handle($reservation, ['amount' => 1200, 'method' => 'cash']);
})->throws(InvalidArgumentException::class, 'excede');

it('rechaza pagos en reservas canceladas', function () {
    $reservation = reservar();
    app(TransitionReservation::class)->cancel($reservation);

    app(RegisterReservationPayment::class)->handle($reservation->refresh(), ['amount' => 100, 'method' => 'cash']);
})->throws(InvalidArgumentException::class, 'cancelada');

it('marca pago vencido cuando pasa la fecha límite sin liquidar', function () {
    $reservation = reservar();
    $reservation->update(['payment_due_at' => now()->subDay()]);

    expect($reservation->refresh()->isPaymentOverdue())->toBeTrue();

    // Al liquidar deja de estar vencida.
    app(RegisterReservationPayment::class)->handle($reservation, ['amount' => 1000, 'method' => 'card']);
    expect($reservation->refresh()->isPaymentOverdue())->toBeFalse();
});

it('las tarifas sin cobro anticipado usan el default del hotel (5 dias antes)', function () {
    $libre = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 500,
    ]);

    $reservation = reservar(['rate_plan_id' => $libre->id]);

    expect((float) $reservation->deposit_amount)->toBe(0.0)
        ->and($reservation->payment_due_at->toDateString())
        ->toBe($reservation->starts_at->copy()->subDays(5)->toDateString())
        ->and($reservation->isPaymentOverdue())->toBeFalse();
});

it('el default del hotel no aplica a llegadas mas proximas que el plazo', function () {
    $libre = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 500,
    ]);

    // Llega en 2 dias: una fecha limite "5 dias antes" ya estaria vencida.
    $reservation = reservar([
        'rate_plan_id' => $libre->id,
        'starts_at' => now()->addDays(2)->setTime(15, 0),
        'ends_at' => now()->addDays(3)->setTime(12, 0),
    ]);

    expect($reservation->payment_due_at)->toBeNull();
});

it('con el interruptor global apagado nadie tiene fecha limite, ni con tarifa configurada', function () {
    $this->property->update(['settings' => ['balance_due_enabled' => false]]);

    $reservation = reservar(); // tarifa CON payment_due propio (1 semana)

    expect($reservation->payment_due_at)->toBeNull();
});
