<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\RegisterReservationPayment;
use App\Actions\Reservations\TransitionReservation;
use App\Enums\ReservationStatus;
use App\Events\RoomStatusChanged;
use App\Exceptions\NoAvailabilityException;
use App\Http\Controllers\Agent\AgentToolsController;
use App\Http\Controllers\Tenant\ReservationController;
use App\Http\Controllers\Tenant\ReservationShowPageController;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Agent\AgentBrain;
use App\Services\Payments\PaymentProofHoldExtender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

// Caso real cabañas 2026-09-10: el apartado venció mientras el huésped
// depositaba, el personal le dio su código por chat y la reserva seguía
// cancelada — no había manera de reabrirla ni de reagendarla.

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->rooms = collect(['201', '202'])->map(fn (string $number) => Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => $number,
    ]));
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 500,
    ]);
});

function reopenHoldFor(array $overrides = []): Reservation
{
    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->rooms[0]->id,
        'starts_at' => now()->addDays(2)->setTime(15, 0),
        'ends_at' => now()->addDays(3)->setTime(12, 0),
        'guest_name' => 'Huésped Reabierto',
        ...$overrides,
    ]);
}

/** El barrido real de apartados vencidos, no un update a mano. */
function reopenExpire(Reservation $reservation): Reservation
{
    $reservation->update(['hold_expires_at' => now()->subMinute()]);
    test()->artisan('reservations:expire-holds');

    return $reservation->refresh();
}

function reopenConversation(Reservation $reservation): Conversation
{
    $channel = Channel::firstOrCreate(
        ['property_id' => test()->property->id, 'type' => Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );

    return Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '5216560000000',
        'status' => Conversation::STATUS_OPEN,
        'reservation_id' => $reservation->id,
        'last_message_at' => now(),
    ]);
}

it('el barrido deja el motivo del vencimiento y la reserva se reabre con el mismo código', function () {
    $reservation = reopenExpire(reopenHoldFor());
    $code = $reservation->displayCode();

    expect($reservation->status)->toBe(ReservationStatus::Cancelled)
        ->and($reservation->cancellation_reason)->toBe(Reservation::EXPIRED_HOLD_REASON)
        ->and($reservation->isExpiredHold())->toBeTrue();

    $reopened = app(TransitionReservation::class)->reopen($reservation);

    expect($reopened->status)->toBe(ReservationStatus::Pending)
        ->and($reopened->displayCode())->toBe($code)
        ->and($reopened->cancellation_reason)->toBeNull()
        ->and($reopened->hold_expires_at->isFuture())->toBeTrue()
        ->and($reopened->room_id)->toBe($this->rooms[0]->id)
        ->and((float) $reopened->total_amount)->toBe(500.0);
});

it('reabre directo como confirmada', function () {
    $reservation = reopenExpire(reopenHoldFor());

    $reopened = app(TransitionReservation::class)->reopen($reservation, null, ['confirmed' => true]);

    expect($reopened->status)->toBe(ReservationStatus::Confirmed)
        ->and($reopened->hold_expires_at)->toBeNull();
});

it('si su habitación ya se vendió toma otra libre del tipo, y sin ninguna no hay cupo', function () {
    $reservation = reopenExpire(reopenHoldFor());

    // Alguien más ganó la 201 en esas fechas.
    reopenHoldFor(['guest_name' => 'Otro', 'confirmed' => true]);

    $reopened = app(TransitionReservation::class)->reopen($reservation);
    expect($reopened->room_id)->toBe($this->rooms[1]->id);

    $second = reopenExpire(reopenHoldFor(['room_id' => null]));
    app(TransitionReservation::class)->reopen($second);
})->throws(NoAvailabilityException::class);

it('reagenda con fechas nuevas y recalcula el total con su tarifa', function () {
    $reservation = reopenExpire(reopenHoldFor());

    $reopened = app(TransitionReservation::class)->reopen($reservation, null, [
        'starts_at' => now()->addDays(10)->setTime(15, 0),
        'ends_at' => now()->addDays(13)->setTime(12, 0),
    ]);

    expect($reopened->status)->toBe(ReservationStatus::Pending)
        ->and($reopened->starts_at->isSameDay(now()->addDays(10)))->toBeTrue()
        ->and((float) $reopened->total_amount)->toBe(1500.0);
});

it('una llegada que ya pasó no se revive sin fechas nuevas', function () {
    $reservation = reopenHoldFor();
    app(TransitionReservation::class)->cancel($reservation);
    $reservation->forceFill(['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(2)])->save();

    app(TransitionReservation::class)->reopen($reservation->refresh());
})->throws(InvalidArgumentException::class, 'ya pasó');

it('solo se reabre lo cancelado o un "no llegó"', function () {
    app(TransitionReservation::class)->reopen(reopenHoldFor());
})->throws(InvalidArgumentException::class);

it('una cancelación a mano no cuenta como apartado vencido', function () {
    $reservation = reopenHoldFor();
    app(TransitionReservation::class)->cancel($reservation, null, ReservationStatus::Cancelled, 'Lo pidió el huésped');

    expect($reservation->refresh()->isExpiredHold())->toBeFalse();
});

it('el comprobante que manda el huésped sostiene su apartado vigente', function () {
    $reservation = reopenHoldFor();
    $conversation = reopenConversation($reservation);

    $outgoing = $conversation->messages()->create(['direction' => 'out', 'sender_type' => 'bot', 'body' => 'Datos para transferir', 'created_at' => now()]);
    expect(app(PaymentProofHoldExtender::class)->extendFor($outgoing))->toBe(0);

    $proof = $conversation->messages()->create(['direction' => 'in', 'sender_type' => 'visitor', 'body' => '[Imagen]', 'created_at' => now()]);

    expect(app(PaymentProofHoldExtender::class)->extendFor($proof))->toBe(1)
        ->and($reservation->refresh()->hold_expires_at->gt(now()->addHours(23)))->toBeTrue()
        ->and($reservation->status)->toBe(ReservationStatus::Pending);
});

it('un comprobante que llega tarde reabre el apartado vencido si nadie ganó la habitación', function () {
    // Depositó 10 minutos después del plazo: el barrido ya lo canceló.
    $reservation = reopenExpire(reopenHoldFor());
    $conversation = reopenConversation($reservation);

    $proof = $conversation->messages()->create(['direction' => 'in', 'sender_type' => 'visitor', 'body' => '[Imagen]', 'created_at' => now()]);

    expect(app(PaymentProofHoldExtender::class)->extendFor($proof))->toBe(1)
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Pending)
        ->and($reservation->hold_expires_at->gt(now()->addHours(23)))->toBeTrue()
        ->and($reservation->cancellation_reason)->toBeNull();
});

it('un comprobante tardío no reabre si otro ya apartó la habitación', function () {
    $reservation = reopenExpire(reopenHoldFor(['room_id' => null]));
    $conversation = reopenConversation($reservation);

    // Las dos habitaciones del tipo se vendieron mientras tanto.
    reopenHoldFor(['guest_name' => 'Otro', 'confirmed' => true, 'room_id' => $this->rooms[0]->id]);
    reopenHoldFor(['guest_name' => 'Otra', 'confirmed' => true, 'room_id' => $this->rooms[1]->id]);

    $proof = $conversation->messages()->create(['direction' => 'in', 'sender_type' => 'visitor', 'body' => '[Imagen]', 'created_at' => now()]);

    expect(app(PaymentProofHoldExtender::class)->extendFor($proof))->toBe(0)
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);
});

it('reactivar_apartado revive el apartado vencido de la conversación y no toca el de otra', function () {
    $reservation = reopenExpire(reopenHoldFor());
    $conversation = reopenConversation($reservation);
    $tools = app(AgentToolsController::class);

    $stranger = reopenConversation(reopenHoldFor(['room_id' => $this->rooms[1]->id]));
    $denied = $tools->reopenHold(
        Request::create('/brain', 'POST', ['code' => $reservation->displayCode(), 'conversation_id' => $stranger->id]),
        app(TransitionReservation::class),
    );
    expect($denied->getStatusCode())->toBe(403)
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);

    $response = $tools->reopenHold(
        Request::create('/brain', 'POST', ['code' => $reservation->displayCode(), 'conversation_id' => $conversation->id]),
        app(TransitionReservation::class),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and(json_decode($response->getContent(), true)['code'])->toBe($reservation->displayCode())
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Pending);
});

it('reactivar_apartado no revive lo que canceló el hotel', function () {
    $reservation = reopenHoldFor();
    app(TransitionReservation::class)->cancel($reservation);
    $conversation = reopenConversation($reservation);

    $response = app(AgentToolsController::class)->reopenHold(
        Request::create('/brain', 'POST', ['code' => $reservation->displayCode(), 'conversation_id' => $conversation->id]),
        app(TransitionReservation::class),
    );

    expect($response->getStatusCode())->toBe(422)
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);
});

it('una respuesta del bot en ruso nunca sale: sin quien la traduzca va una frase segura en español', function () {
    $brain = (new ReflectionClass(AgentBrain::class))->newInstanceWithoutConstructor();
    $enforce = fn (string $text) => (fn () => $this->enforceLanguage($text, null))->call($brain);

    // Texto real de la conversación 69 de cabañas (2026-09-10).
    $out = $enforce('He передал ваш запрос. Скоро с вами свяжутся по телефону для подтверждения оплаты.');

    expect($out)->toContain('Disculpe')
        ->and($out)->not->toMatch('/\p{Cyrillic}/u')
        ->and($enforce('Con gusto le ayudo con su reserva.'))->toBe('Con gusto le ayudo con su reserva.')
        ->and($enforce('Sure, the cabin is available on Friday.'))->toBe('Sure, the cabin is available on Friday.');
});

it('el comprobante deja un cobro por transferencia pendiente para aprobarlo en Pagos', function () {
    // RES-2026-1693: el bot pasó las cuentas sin emitir el cobro, así que
    // en Pagos no había nada que aprobar aunque el huésped ya depositó.
    $reservation = reopenHoldFor();
    $conversation = reopenConversation($reservation);
    $proof = $conversation->messages()->create(['direction' => 'in', 'sender_type' => 'visitor', 'body' => '[Imagen]', 'created_at' => now()]);

    app(PaymentProofHoldExtender::class)->extendFor($proof);

    $request = $reservation->paymentRequests()->where('status', PaymentRequest::STATUS_PENDING)->first();

    expect($request)->not->toBeNull()
        ->and($request->method)->toBe(PaymentRequest::METHOD_TRANSFER)
        ->and($request->expires_at->gt(now()->addHours(23)))->toBeTrue();
});

it('una transferencia ya verificada se registra desde la reserva, con folio o comprobante', function () {
    $reservation = reopenHoldFor(['confirmed' => true]);
    $controller = app(ReservationController::class);

    // Sin folio Y sin comprobante no pasa: algo tiene que respaldar el dinero.
    // (Desde 2026-09-16 el comprobante sirve de respaldo, así que el rechazo
    // es una respuesta 422 con el motivo, no una excepción de validación.)
    $sinRespaldo = $controller->registerPayment(
        Request::create('/x', 'POST', ['amount' => 250, 'method' => 'transfer']),
        $reservation,
        app(RegisterReservationPayment::class),
    );

    expect($sinRespaldo->getStatusCode())->toBe(422)
        ->and($sinRespaldo->getData(true)['message'])->toContain('comprobante')
        ->and($reservation->payments()->count())->toBe(0);

    $response = $controller->registerPayment(
        Request::create('/x', 'POST', ['amount' => 250, 'method' => 'transfer', 'reference' => 'SPEI-99']),
        $reservation,
        app(RegisterReservationPayment::class),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($reservation->payments()->first()->method)->toBe('transfer')
        ->and($reservation->payments()->first()->reference)->toBe('SPEI-99');
});

it('la ficha de la reserva trae su dinero, su conversación y la historia completa', function () {
    $reservation = reopenHoldFor(['confirmed' => true]);
    $conversation = reopenConversation($reservation);
    $user = User::factory()->create();

    $request = Request::create("/reservas/{$reservation->id}", 'GET');
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => $user);

    $props = app(ReservationShowPageController::class)
        ->show($request, $reservation)
        ->toResponse($request)
        ->getData(true)['props'];

    expect($props['reservation']['code'])->toBe($reservation->displayCode())
        ->and($props['conversationId'])->toBe($conversation->id)
        ->and($props['reservation']['timeline'])->not->toBeEmpty()
        ->and($props['reservation']['pending_balance'])->toEqual(500);
});
