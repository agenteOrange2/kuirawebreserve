<?php

use App\Actions\Payments\IssuePaymentRequest;
use App\Actions\Payments\RegisterGatewayPayment;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\RegisterReservationPayment;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Events\RoomStatusChanged;
use App\Exceptions\PaymentNeedsConfirmation;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\Event;

// Casos reales cabañas 2026-09-15:
// - Iris (RES-2026-1750, total $3,500, anticipo $1,750): se capturó a mano su
//   transferencia de $1,750 y después se aprobó su comprobante en /pagos. La
//   aprobación creó OTRO pago de $1,750 y la reserva quedó "pagada" debiendo
//   la mitad.
// - Alonso (RES-2026-1725): abonó $1,700 de un anticipo de $1,750 y la
//   reserva decía "Sin pago".

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    // 2 noches de $1,750 = $3,500, anticipo del 50% = $1,750 (la Prisma de Iris).
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1750,
        'deposit_percent' => 50,
    ]);
    $this->sofia = User::factory()->create();
});

function apartadoConAnticipo(): \App\Models\Reservation
{
    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDays(20)->setTime(14, 0),
        'ends_at' => now()->addDays(22)->setTime(11, 0),
        'guest_name' => 'Iris Judith Zepeda Sauceda',
        'confirmed' => false,
    ]);
}

function capturarAMano(\App\Models\Reservation $reservation, float $monto, string $metodo = 'transfer'): void
{
    app(RegisterReservationPayment::class)->handle($reservation->refresh(), [
        'amount' => $monto,
        'method' => $metodo,
        'reference' => '13:11',
    ], test()->sofia);
}

// ------------------------------------------------------------ estados

it('un abono que no llega al anticipo se ve como anticipo incompleto, no como sin pago', function () {
    $reservation = apartadoConAnticipo();

    capturarAMano($reservation, 1700);

    $reservation->refresh();

    expect($reservation->payment_status)->toBe(PaymentStatus::Partial)
        ->and($reservation->payment_status->label())->toBe('Anticipo incompleto')
        // Con menos del anticipo no se confirma sola.
        ->and($reservation->status)->toBe(ReservationStatus::Pending);
});

it('solo el anticipo se ve como pago parcial, y el segundo pago la deja pagada', function () {
    $reservation = apartadoConAnticipo();

    capturarAMano($reservation, 1750);
    expect($reservation->refresh()->payment_status)->toBe(PaymentStatus::DepositPaid)
        ->and($reservation->payment_status->label())->toBe('Pago parcial');

    capturarAMano($reservation, 1750, 'cash');
    expect($reservation->refresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($reservation->payment_status->label())->toBe('Pagada');
});

// ---------------------------------------------------- aprobar dos veces

it('aprobar el comprobante de una transferencia ya capturada la liga, no la duplica', function () {
    $reservation = apartadoConAnticipo();
    $request = app(IssuePaymentRequest::class)->handle($reservation);

    capturarAMano($reservation, 1750);

    $payment = app(RegisterGatewayPayment::class)->handle($request->refresh(), [], $this->sofia);

    $reservation->refresh();

    expect($reservation->payments()->count())->toBe(1)
        ->and($reservation->paidTotal())->toBe(1750.0)
        ->and($reservation->payment_status)->toBe(PaymentStatus::DepositPaid)
        ->and($payment->payment_request_id)->toBe($request->id)
        ->and($request->refresh()->status)->toBe(PaymentRequest::STATUS_PAID)
        ->and($request->meta['linked_to_captured_payment'])->toBe($payment->id);
});

it('si el anticipo ya se cubrió con otro dinero, pide confirmación antes de sumar', function () {
    $reservation = apartadoConAnticipo();
    $request = app(IssuePaymentRequest::class)->handle($reservation);

    // Pagó el anticipo en efectivo: no hay transferencia capturada que ligar.
    capturarAMano($reservation, 1750, 'cash');

    expect(fn () => app(RegisterGatewayPayment::class)->handle($request->refresh(), [], $this->sofia))
        ->toThrow(PaymentNeedsConfirmation::class);

    expect($reservation->refresh()->payments()->count())->toBe(1);
});

it('con la confirmación sí registra el dinero de más', function () {
    $reservation = apartadoConAnticipo();
    $request = app(IssuePaymentRequest::class)->handle($reservation);
    capturarAMano($reservation, 1750, 'cash');

    app(RegisterGatewayPayment::class)->handle($request->refresh(), ['confirm_overpay' => true], $this->sofia);

    expect($reservation->refresh()->paidTotal())->toBe(3500.0)
        ->and($reservation->payment_status)->toBe(PaymentStatus::Paid);
});

it('un comprobante normal, sin nada capturado antes, se registra como siempre', function () {
    $reservation = apartadoConAnticipo();
    $request = app(IssuePaymentRequest::class)->handle($reservation);

    app(RegisterGatewayPayment::class)->handle($request, [], $this->sofia);

    expect($reservation->refresh()->paidTotal())->toBe(1750.0)
        ->and($reservation->payment_status)->toBe(PaymentStatus::DepositPaid)
        ->and($reservation->status)->toBe(ReservationStatus::Confirmed);
});

it('el pago que avisa la pasarela nunca se detiene: ese dinero ya entró', function () {
    $reservation = apartadoConAnticipo();
    $request = app(IssuePaymentRequest::class)->handle($reservation);
    capturarAMano($reservation, 1750, 'cash');

    // Sin persona que verifique (webhook): se registra y se marca de más.
    app(RegisterGatewayPayment::class)->handle($request->refresh());

    expect($reservation->refresh()->paidTotal())->toBe(3500.0);
});
