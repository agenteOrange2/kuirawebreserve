<?php

use App\Actions\Payments\IssueGroupPayment;
use App\Actions\Payments\RegisterGatewayPayment;
use App\Actions\Reservations\CreateGroupReservation;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\ReservationGroup;
use App\Models\Room;
use App\Models\RoomType;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    Room::factory()->count(3)->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    // 20% de anticipo por reserva: el cobro del grupo junta los anticipos.
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1000,
        'deposit_percent' => 20,
    ]);

    $this->group = app(CreateGroupReservation::class)->handle([
        'mode' => 'night',
        'starts_at' => now()->addDays(10)->setTime(15, 0),
        'ends_at' => now()->addDays(11)->setTime(12, 0),
        'guest_name' => 'Familia García',
        'guest_phone' => '5544332211',
        'confirmed' => false,
        'lines' => [['room_type_id' => $this->roomType->id, 'rooms' => 3, 'adults' => 2]],
    ]);
});

it('el cobro consolidado junta los anticipos de todas las reservas con desglose congelado', function () {
    $request = app(IssueGroupPayment::class)->handle($this->group);

    // 3 reservas x $1000 con 20% de anticipo = $600 en un solo cobro.
    expect((float) $request->amount)->toBe(600.0)
        ->and($request->reservation_group_id)->toBe($this->group->id)
        ->and($request->reservation_id)->toBeNull()
        ->and($request->meta['breakdown'])->toHaveCount(3)
        ->and(array_sum($request->meta['breakdown']))->toEqual(600.0);
});

it('emitir el cobro del grupo cancela los cobros individuales vivos de sus reservas', function () {
    $reservation = $this->group->reservations()->first();
    $individual = app(\App\Actions\Payments\IssuePaymentRequest::class)->handle($reservation);

    app(IssueGroupPayment::class)->handle($this->group);

    expect($individual->fresh()->status)->toBe(PaymentRequest::STATUS_CANCELED);
});

it('el pago del grupo se reparte por reserva y confirma todas', function () {
    $request = app(IssueGroupPayment::class)->handle($this->group);

    app(RegisterGatewayPayment::class)->handle($request, ['reference' => 'SPEI-GRP']);

    expect($request->fresh()->status)->toBe(PaymentRequest::STATUS_PAID);

    foreach ($this->group->reservations()->get() as $reservation) {
        expect($reservation->payments()->count())->toBe(1)
            ->and((float) $reservation->payments()->first()->amount)->toBe(200.0)
            ->and($reservation->payment_status)->toBe(PaymentStatus::DepositPaid)
            ->and($reservation->status)->toBe(ReservationStatus::Confirmed);
    }
});

it('sin saldo pendiente el grupo no emite cobros', function () {
    $request = app(IssueGroupPayment::class)->handle($this->group);
    app(RegisterGatewayPayment::class)->handle($request);

    // Pagar el saldo restante de cada reserva a mano.
    foreach ($this->group->reservations()->get() as $reservation) {
        app(\App\Actions\Reservations\RegisterReservationPayment::class)
            ->handle($reservation, ['amount' => $reservation->pendingBalance(), 'method' => 'cash']);
    }

    app(IssueGroupPayment::class)->handle($this->group->fresh());
})->throws(InvalidArgumentException::class, 'saldo');
