<?php

use App\Actions\Payments\IssuePaymentRequest;
use App\Actions\Reservations\CreateReservation;
use App\Http\Controllers\Tenant\BookingController;
use App\Http\Controllers\Tenant\BookingExtrasController;
use App\Http\Controllers\Tenant\ExperienceWizardController;
use App\Http\Controllers\Tenant\PaymentReturnController;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Services\ReservationPolicy;
use Illuminate\Http\Request;

/*
 * Tres fallas del wizard reportadas en cabañas el 17-sep-2026:
 * 1. Fuera del horario de transferencias se seguía ofreciendo "Transferencia
 *    bancaria" (y grupos y experiencias hasta la aceptaban).
 * 2. El cobro por transferencia decía "Vigente por 0 horas".
 * 3. Quien entraba a Mercado Pago y regresaba sin pagar se quedaba en
 *    "Confirmando tu pago…" girando, sin poder elegir otro método.
 */

function bindPaymentFixesTenant(): Tenant
{
    $tenant = new Tenant;
    $tenant->id = 'hotel-pagos-test';
    $tenant->plan = 'basic';

    app()->instance(\Stancl\Tenancy\Contracts\Tenant::class, $tenant);
    app()->instance(Tenant::class, $tenant);

    return $tenant;
}

/** Cabañas: una tarjeta activa y transferencias solo de 9 a 5. */
function cabinsPaymentSettings(array $overrides = []): array
{
    return array_replace([
        'bank_accounts' => [['bank' => 'BBVA', 'holder' => 'Hotel Demo', 'clabe' => '012345678901234567', 'active' => true]],
        'transfer_hours_enabled' => true,
        'transfer_hours_open' => '09:00',
        'transfer_hours_close' => '17:00',
    ], $overrides);
}

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    bindPaymentFixesTenant();

    $this->property = Property::factory()->create(['settings' => cabinsPaymentSettings()]);
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->ratePlan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'deposit_percent' => 50,
    ]);
});

function pendingWizardHold(): Reservation
{
    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->ratePlan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDays(3),
        'confirmed' => false,
        'source_channel' => 'web',
        'guest_name' => 'Ana García',
    ]);
}

function wizardPayment(Reservation $reservation, array $body = []): \Illuminate\Http\JsonResponse
{
    $request = Request::create("/api/booking/holds/{$reservation->code}/payment", 'POST', $body);

    return app(BookingController::class)->payment($request, $reservation->code, app(IssuePaymentRequest::class));
}

/** Props de la página de retorno tal como las recibe el navegador. */
function returnPageProps(PaymentRequest $paymentRequest, array $query = []): array
{
    $request = Request::create("/pago/{$paymentRequest->uuid}", 'GET', $query);
    $request->headers->set('X-Inertia', 'true');

    return app(PaymentReturnController::class)($request, $paymentRequest->uuid)
        ->toResponse($request)->getData(true)['props'];
}

// ── 1. Transferencia fuera de horario ──

it('de noche el wizard no ofrece transferencia, y de día sí', function () {
    $this->travelTo(now()->setTime(20, 0));
    $night = app(BookingExtrasController::class)->paymentOptions()->getData(true);

    $this->travelTo(now()->setTime(10, 0));
    $day = app(BookingExtrasController::class)->paymentOptions()->getData(true);

    expect($night['transfer']['available'])->toBeFalse()
        ->and($night['transfer']['accounts_count'])->toBe(0)
        ->and($day['transfer']['available'])->toBeTrue()
        ->and($day['transfer']['accounts_count'])->toBe(1);
});

it('grupos y experiencias tampoco ofrecen transferencia de noche', function () {
    $this->travelTo(now()->setTime(23, 30));

    $data = app(ExperienceWizardController::class)->paymentOptions()->getData(true);

    expect($data['transfer']['available'])->toBeFalse();
});

it('sin horario configurado la transferencia se ofrece a cualquier hora', function () {
    $this->property->update(['settings' => cabinsPaymentSettings(['transfer_hours_enabled' => false])]);
    $this->travelTo(now()->setTime(3, 0));

    $data = app(BookingExtrasController::class)->paymentOptions()->getData(true);

    expect($data['transfer']['available'])->toBeTrue();
});

it('pedir transferencia de noche se rechaza diciendo el horario, no "no hay métodos"', function () {
    $this->travelTo(now()->setTime(20, 0));
    $reservation = pendingWizardHold();

    $response = wizardPayment($reservation, ['method' => 'transfer']);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['message'])->toBe('Las transferencias se reciben de 9:00 AM a 5:00 PM.');
});

it('el aviso de horario no aparece si el hotel no tiene cuentas', function () {
    $this->property->update(['settings' => cabinsPaymentSettings(['bank_accounts' => []])]);
    $this->travelTo(now()->setTime(20, 0));

    expect(app(ReservationPolicy::class)->transferClosedNotice())->toBeNull();
});

// ── 2. "Vigente por 0 horas" ──

it('dice los plazos como una persona', function (int $minutes, string $label) {
    expect(ReservationPolicy::durationLabel($minutes))->toBe($label);
})->with([
    [1, '1 minuto'],
    [20, '20 minutos'],
    [60, '1 hora'],
    [90, '1 h 30 min'],
    [180, '3 horas'],
]);

it('el cobro por transferencia de una hora dice "1 hora", no "0 horas"', function () {
    $this->property->update(['settings' => cabinsPaymentSettings([
        'transfer_valid_value' => 1,
        'transfer_valid_unit' => 'hour',
    ])]);
    $this->travelTo(now()->setTime(10, 0));
    $reservation = pendingWizardHold();

    $data = wizardPayment($reservation, ['method' => 'transfer'])->getData(true);

    expect($data['valid_label'])->toBe('1 hora')
        ->and($data['valid_hours'])->toBe(1);
});

it('un plazo de 20 minutos se dice en minutos', function () {
    $this->property->update(['settings' => cabinsPaymentSettings([
        'transfer_valid_value' => 20,
        'transfer_valid_unit' => 'minute',
    ])]);
    $this->travelTo(now()->setTime(10, 0));
    $reservation = pendingWizardHold();

    $data = wizardPayment($reservation, ['method' => 'transfer'])->getData(true);

    expect($data['valid_label'])->toBe('20 minutos');
});

// ── 3. Regresar de la pasarela sin pagar ──

it('volver de Mercado Pago sin pagar ya no se trata como un pago en camino', function () {
    $this->travelTo(now()->setTime(10, 0));
    $reservation = pendingWizardHold();
    $paymentRequest = app(IssuePaymentRequest::class)->handle($reservation, PaymentRequest::METHOD_TRANSFER);

    // "Volver a la tienda": Mercado Pago regresa con collection_status=null.
    $props = returnPageProps($paymentRequest, ['collection_status' => 'null', 'status' => 'null']);

    expect($props['payment']['outcome'])->toBe('abandoned')
        ->and($props['payment']['change_method_url'])->toContain("retomar={$paymentRequest->uuid}");
});

it('lee cómo terminó el checkout', function (array $query, ?string $outcome) {
    $this->travelTo(now()->setTime(10, 0));
    $paymentRequest = app(IssuePaymentRequest::class)->handle(pendingWizardHold(), PaymentRequest::METHOD_TRANSFER);

    expect(returnPageProps($paymentRequest, $query)['payment']['outcome'])->toBe($outcome);
})->with([
    'Mercado Pago aprobado' => [['collection_status' => 'approved'], 'processing'],
    'Mercado Pago en proceso' => [['collection_status' => 'in_process'], 'processing'],
    'Mercado Pago rechazado' => [['collection_status' => 'rejected'], 'failed'],
    'Stripe o PayPal cancelado' => [['cancelado' => '1'], 'abandoned'],
    'sin datos de la pasarela' => [[], null],
]);

it('no ofrece cambiar de método si el apartado ya venció', function () {
    $this->travelTo(now()->setTime(10, 0));
    $paymentRequest = app(IssuePaymentRequest::class)->handle(pendingWizardHold(), PaymentRequest::METHOD_TRANSFER);

    $this->travelTo(now()->addDays(5));

    expect(returnPageProps($paymentRequest, ['collection_status' => 'null'])['payment']['change_method_url'])->toBeNull();
});

it('retomar devuelve el mismo apartado listo para el paso de pago', function () {
    $this->travelTo(now()->setTime(10, 0));
    $reservation = pendingWizardHold();
    $paymentRequest = app(IssuePaymentRequest::class)->handle($reservation, PaymentRequest::METHOD_TRANSFER);

    $response = app(BookingController::class)->resume($paymentRequest->uuid);
    $data = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($data['code'])->toBe($reservation->displayCode())
        ->and($data['mode'])->toBe('block')
        ->and($data['requires_prepayment'])->toBeTrue()
        ->and((float) $data['total'])->toBe((float) $reservation->total_amount);
});

it('retomar después de elegir transferencia cancela el cobro de la pasarela', function () {
    $this->travelTo(now()->setTime(10, 0));
    $reservation = pendingWizardHold();
    $first = app(IssuePaymentRequest::class)->handle($reservation, PaymentRequest::METHOD_TRANSFER, preferFull: true);

    // El huésped regresa y elige otra cosa (aquí, pagar solo el anticipo):
    // el cobro anterior no puede seguir vivo por otro monto.
    wizardPayment($reservation, ['method' => 'transfer']);

    expect($first->refresh()->status)->toBe(PaymentRequest::STATUS_CANCELED);
});

it('no se puede retomar un apartado vencido, ni uno ya pagado, ni un uuid ajeno', function () {
    $this->travelTo(now()->setTime(10, 0));
    $reservation = pendingWizardHold();
    $paymentRequest = app(IssuePaymentRequest::class)->handle($reservation, PaymentRequest::METHOD_TRANSFER);

    expect(app(BookingController::class)->resume((string) \Illuminate\Support\Str::uuid())->getStatusCode())->toBe(404);

    $paymentRequest->forceFill(['status' => PaymentRequest::STATUS_PAID])->save();
    expect(app(BookingController::class)->resume($paymentRequest->uuid)->getStatusCode())->toBe(409);

    $paymentRequest->forceFill(['status' => PaymentRequest::STATUS_PENDING])->save();
    $this->travelTo(now()->addDays(5));
    expect(app(BookingController::class)->resume($paymentRequest->uuid)->getStatusCode())->toBe(410);
});
