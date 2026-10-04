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

// ------------------------------------- comprobante de saldo (reserva 1789)
// Caso real cabañas 2026-10-03: el anticipo se capturó a mano y su comprobante
// se aprobó como cobro de SALDO por el mismo monto. Ni "anticipo cubierto" ni
// "excede lo pendiente" lo detenían y la reserva quedó "Pagada" con el doble.

function cobroDeSaldo(\App\Models\Reservation $reservation): PaymentRequest
{
    return PaymentRequest::create([
        'reservation_id' => $reservation->id,
        'method' => PaymentRequest::METHOD_TRANSFER,
        'concept' => PaymentRequest::CONCEPT_BALANCE,
        'amount' => 1750,
        'currency' => 'MXN',
        'status' => PaymentRequest::STATUS_PENDING,
        'expires_at' => now()->addDay(),
    ]);
}

it('un comprobante de saldo por el mismo monto que se capturó a mano pide confirmación', function () {
    $reservation = apartadoConAnticipo();
    capturarAMano($reservation, 1750, 'cash');
    $request = cobroDeSaldo($reservation);

    try {
        app(RegisterGatewayPayment::class)->handle($request, [], $this->sofia);
        $this->fail('Debió pedir confirmación');
    } catch (PaymentNeedsConfirmation $e) {
        expect($e->getMessage())->toContain('ya se capturó a mano un pago de $1,750.00 (efectivo)')
            ->and($e->confirmLabel)->toBe('Sí, es otro pago');
    }

    expect($reservation->refresh()->payments()->count())->toBe(1)
        ->and($reservation->payment_status)->toBe(PaymentStatus::DepositPaid);
});

it('aunque lo capturado sea transferencia, el de saldo no se liga solo: se pregunta', function () {
    $reservation = apartadoConAnticipo();
    capturarAMano($reservation, 1750);
    $request = cobroDeSaldo($reservation);

    expect(fn () => app(RegisterGatewayPayment::class)->handle($request, [], $this->sofia))
        ->toThrow(PaymentNeedsConfirmation::class);

    expect($reservation->refresh()->payments()->count())->toBe(1);
});

it('confirmando que es otro pago, el saldo sí se registra', function () {
    $reservation = apartadoConAnticipo();
    capturarAMano($reservation, 1750, 'cash');
    $request = cobroDeSaldo($reservation);

    app(RegisterGatewayPayment::class)->handle($request, ['confirm_overpay' => true], $this->sofia);

    expect($reservation->refresh()->paidTotal())->toBe(3500.0)
        ->and($reservation->payment_status)->toBe(PaymentStatus::Paid);
});

it('el saldo pedido días después del anticipo pasa sin preguntar', function () {
    $reservation = apartadoConAnticipo();
    capturarAMano($reservation, 1750, 'cash');
    $reservation->payments()->update(['created_at' => now()->subDays(5)]);
    $request = cobroDeSaldo($reservation);

    app(RegisterGatewayPayment::class)->handle($request, [], $this->sofia);

    expect($reservation->refresh()->payment_status)->toBe(PaymentStatus::Paid);
});

// ------------------------------- folio repetido (reserva 1789, 27-sep)
// La huésped pagó $1,750 por Mercado Pago (el folio quedó en gateway_ref),
// mandó la captura de ESE pago al chat y se aprobó como cobro de saldo.

function pagoMercadoPago(\App\Models\Reservation $reservation): \App\Models\Payment
{
    $deposit = app(IssuePaymentRequest::class)->handle($reservation);

    return app(RegisterGatewayPayment::class)->handle($deposit, [
        'gateway' => 'mercadopago',
        'gateway_ref' => '180527155686',
    ]);
}

it('el comprobante con el folio de un pago de pasarela ya registrado no se aprueba sin confirmar', function () {
    $reservation = apartadoConAnticipo();
    pagoMercadoPago($reservation);

    $balance = cobroDeSaldo($reservation->refresh());
    $balance->update(['meta' => ['tracking_key' => '180527155686']]);

    try {
        app(RegisterGatewayPayment::class)->handle($balance->refresh(), ['reference' => '180527155686'], $this->sofia);
        $this->fail('Debió detenerse');
    } catch (PaymentNeedsConfirmation $e) {
        expect($e->getMessage())->toContain('El folio 180527155686 ya está registrado')
            ->and($e->getMessage())->toContain('de esta misma reserva')
            ->and($e->confirmLabel)->toBe('Sí, es otra operación');
    }

    expect($reservation->refresh()->payments()->count())->toBe(1)
        ->and($reservation->payment_status)->toBe(PaymentStatus::DepositPaid);
});

it('el lector de comprobantes marca duplicado el folio que vive en gateway_ref', function () {
    $reservation = apartadoConAnticipo();
    pagoMercadoPago($reservation);

    $check = app(\App\Services\Payments\ReceiptCheck::class)->evaluate([
        'kind' => 'transfer_receipt',
        'amount' => 1750,
        'tracking_key' => '180527155686',
    ], cobroDeSaldo($reservation->refresh()));

    expect($check['verdict'])->toBe(\App\Services\Payments\ReceiptCheck::DUPLICATE);
});

it('un folio corto como una hora no se toma por folio repetido', function () {
    $reservation = apartadoConAnticipo();
    capturarAMano($reservation, 1750, 'cash'); // reference '13:11'
    $reservation->payments()->update(['created_at' => now()->subDays(5)]);

    app(RegisterGatewayPayment::class)->handle(cobroDeSaldo($reservation), ['reference' => '13:11'], $this->sofia);

    expect($reservation->refresh()->payment_status)->toBe(PaymentStatus::Paid);
});
