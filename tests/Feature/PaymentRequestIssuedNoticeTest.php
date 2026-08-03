<?php

use App\Actions\Payments\IssuePaymentRequest;
use App\Actions\Reservations\CreateReservation;
use App\Http\Controllers\Tenant\ReservationController;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Channels\DirectGuestMessenger;
use App\Services\Payments\PaymentGuestNotifier;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create([
        'settings' => ['bank_accounts' => [['bank' => 'BBVA', 'holder' => 'Hotel Prueba', 'clabe' => '012345678901234567', 'active' => true]]],
    ]);
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 500,
        'deposit_percent' => 20,
    ]);
});

function reservaCobroAviso(): \App\Models\Reservation
{
    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDays(20)->setTime(15, 0),
        'ends_at' => now()->addDays(22)->setTime(12, 0),
        'confirmed' => false,
        'guest_name' => 'Cobro Directo',
        'guest_phone' => '+5216141112233',
    ]);
}

it('el cobro generado desde el panel se envía solo al huésped', function () {
    $reservation = reservaCobroAviso();

    $this->mock(PaymentGuestNotifier::class)
        ->shouldReceive('paymentRequestIssued')
        ->once()
        ->withArgs(fn (PaymentRequest $request) => $request->reservation_id === $reservation->id);

    $response = app(ReservationController::class)->issuePayment(
        Request::create('/x', 'POST'),
        $reservation,
        app(IssuePaymentRequest::class),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($reservation->paymentRequests()->count())->toBe(1);
});

it('si el aviso truena, la solicitud de cobro se crea igual', function () {
    $this->mock(PaymentGuestNotifier::class)
        ->shouldReceive('paymentRequestIssued')
        ->once()
        ->andThrow(new RuntimeException('transporte caído'));

    $reservation = reservaCobroAviso();

    $response = app(ReservationController::class)->issuePayment(
        Request::create('/x', 'POST'),
        $reservation,
        app(IssuePaymentRequest::class),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($reservation->paymentRequests()->active()->count())->toBe(1);
});

it('la variante transferencia lleva las cuentas del hotel y pide el comprobante', function () {
    $reservation = reservaCobroAviso();
    $request = app(IssuePaymentRequest::class)->handle($reservation);

    // Sin conversación ligada, el aviso sale directo (WhatsApp/correo).
    $this->mock(DirectGuestMessenger::class)
        ->shouldReceive('send')
        ->once()
        ->withArgs(
            fn ($res, string $body) => str_contains($body, 'Anticipo')
                && str_contains($body, 'BBVA')
                && str_contains($body, 'Hotel Prueba')
                && str_contains($body, 'comprobante'),
        );

    app(PaymentGuestNotifier::class)->paymentRequestIssued($request);
});

it('la variante con pasarela lleva el link y avisa que se confirma sola', function () {
    $reservation = reservaCobroAviso();
    $request = app(IssuePaymentRequest::class)->handle($reservation);
    $request->forceFill(['checkout_url' => 'https://pagos.test/checkout/abc'])->save();

    $this->mock(DirectGuestMessenger::class)
        ->shouldReceive('send')
        ->once()
        ->withArgs(
            fn ($res, string $body) => str_contains($body, 'https://pagos.test/checkout/abc')
                && str_contains($body, 'se confirma sola'),
        );

    app(PaymentGuestNotifier::class)->paymentRequestIssued($request->refresh());
});
