<?php

use App\Actions\Payments\IssuePaymentRequest;
use App\Actions\Reservations\CreateGroupReservation;
use App\Actions\Reservations\CreateReservation;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\StaffNotification;
use App\Services\Agent\AgentBrain;
use App\Services\Channels\InboundMediaService;
use App\Services\Payments\ReceiptCheck;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Hasta el 2026-09-15 cualquier imagen que llegara a una conversación con
 * apartado sostenía la habitación 24 horas, se pegaba al cobro como
 * "comprobante" y el huésped recibía "Recibimos tu comprobante": una selfie,
 * la foto de la INE o la captura de la cabaña valían lo mismo que una
 * transferencia, y nadie las miraba hasta abrir la bandeja.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Storage::fake('local');

    config([
        'services.receipt_reader.enabled' => true,
        'services.receipt_reader.url' => 'https://api.minimax.io/v1',
        'services.receipt_reader.api_key' => 'test-key',
        'services.receipt_reader.model' => 'MiniMax-M3',
    ]);

    $this->property = Property::factory()->create([
        'settings' => ['bank_accounts' => [['bank' => 'BANORTE', 'holder' => 'Hotel', 'clabe' => '072150000000001234', 'active' => true]]],
    ]);
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Cabaña Sencilla', 'capacity' => 4]);
    Room::factory()->count(2)->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 3000,
        'deposit_percent' => 50,
    ]);
});

function png(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}

/** La respuesta del modelo de visión, en el formato de OpenAI. */
function leeComo(array $json): void
{
    Http::fake(['api.minimax.io/*' => Http::response([
        'choices' => [['message' => ['content' => "<think>miro la imagen</think>\n```json\n".json_encode($json)."\n```"]]],
        'usage' => ['total_tokens' => 1312],
    ])]);
}

function comprobante(array $overrides = []): array
{
    return array_replace([
        'kind' => 'transfer_receipt',
        'description' => 'Transferencia SPEI de BBVA a Banorte',
        'amount' => 1500,
        'date' => now()->toDateString(),
        'time' => '18:10',
        'tracking_key' => 'MBAN01002609140012345',
        'reference' => '2709',
        'destination_bank' => 'BANORTE',
        'destination_account_last4' => '1234',
        'beneficiary' => 'CABAÑAS REAL DE LA SIERRA',
        'status' => 'Transferencia exitosa',
    ], $overrides);
}

function apartado(): Reservation
{
    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'starts_at' => now()->addDays(3)->setTime(14, 0),
        'ends_at' => now()->addDays(4)->setTime(11, 0),
        'confirmed' => false,
        'source_channel' => 'agent',
        'guest_name' => 'Kevin Andrés',
        'guest_phone' => '6565280146',
    ]);
}

function hilo(?int $reservationId = null, string $phone = '5216565280146'): Conversation
{
    $channel = Channel::firstOrCreate(
        ['property_id' => test()->property->id, 'type' => Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );

    return Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => $phone,
        'reservation_id' => $reservationId,
        'status' => Conversation::STATUS_OPEN,
        'bot_enabled' => true,
        'last_message_at' => now(),
    ]);
}

function llegaFoto(Conversation $conversation, string $nombre = 'foto.png'): array
{
    $message = $conversation->messages()->create([
        'direction' => 'in',
        'sender_type' => 'visitor',
        'body' => '[Imagen]',
        'created_at' => now(),
    ]);

    return [$message, app(InboundMediaService::class)->handle($conversation, $message, png(), 'image/png', $nombre)];
}

// ------------------------------------------------- lo que NO es un pago

it('una foto que no es comprobante no sostiene el apartado ni llega a Pagos', function () {
    leeComo(['kind' => 'not_receipt', 'description' => 'Selfie de una persona en la sierra']);

    $reservation = apartado();
    $request = app(IssuePaymentRequest::class)->handle($reservation);
    $conversation = hilo($reservation->id);
    $vencia = $reservation->refresh()->hold_expires_at;

    [$message, $outcome] = llegaFoto($conversation, 'selfie.png');

    expect($outcome)->toBe(InboundMediaService::OUTCOME_DESCRIBED)
        // El archivo sí se guarda en el hilo: el personal puede verlo.
        ->and($message->getFirstMedia('attachments'))->not->toBeNull()
        ->and($request->refresh()->getFirstMedia('receipt'))->toBeNull()
        ->and($reservation->refresh()->hold_expires_at->eq($vencia))->toBeTrue()
        ->and($conversation->messages()->where('sender_type', 'system')->count())->toBe(0)
        // Y la conversación NO se traba esperando a una persona: el bot sabe
        // qué se ve y puede contestar.
        ->and($conversation->refresh()->status)->toBe(Conversation::STATUS_OPEN);
});

it('el bot recibe en su historial qué era la imagen', function () {
    leeComo(['kind' => 'not_receipt', 'description' => 'Captura de una conversación']);

    $conversation = hilo(apartado()->id);
    llegaFoto($conversation);

    $history = (new ReflectionMethod(AgentBrain::class, 'history'))
        ->invoke(app(AgentBrain::class), $conversation);

    expect(collect($history)->map(fn ($m) => $m->content)->implode(' '))
        ->toContain('NO es un comprobante de pago')
        ->toContain('Captura de una conversación');
});

// ---------------------------------------------------- el comprobante real

it('el comprobante sostiene el apartado, se pega al cobro y el acuse trae el monto', function () {
    leeComo(comprobante());

    $reservation = apartado();
    $request = app(IssuePaymentRequest::class)->handle($reservation);
    $conversation = hilo($reservation->id);

    // El apartado corto de cabañas: 10 minutos para pagar (el caso de la
    // conv. 69, que depositó 8 minutos antes de vencer y se canceló igual).
    $reservation->refresh()->update(['hold_expires_at' => now()->addMinutes(10)]);
    $vencia = $reservation->refresh()->hold_expires_at;

    [, $outcome] = llegaFoto($conversation, 'spei.png');

    $request->refresh();

    expect($outcome)->toBe(InboundMediaService::OUTCOME_RECEIPT)
        ->and($request->getFirstMedia('receipt'))->not->toBeNull()
        ->and($request->meta['receipt_check']['verdict'])->toBe(ReceiptCheck::MATCH)
        ->and($request->meta['tracking_key'])->toBe('MBAN01002609140012345')
        ->and($reservation->refresh()->hold_expires_at->gt($vencia))->toBeTrue()
        ->and($conversation->messages()->where('sender_type', 'system')->value('body'))
        ->toContain('Recibimos tu comprobante por $1,500.00')
        // El personal recibe lo leído, para verificar sin abrir la foto.
        ->and(StaffNotification::latest('id')->value('body'))
        ->toContain('Transferencia por $1,500.00')
        ->toContain('rastreo MBAN01002609140012345');
});

it('un monto menor no se calla: lo ve el personal y se le dice al huésped', function () {
    leeComo(comprobante(['amount' => 500]));

    $reservation = apartado();
    $request = app(IssuePaymentRequest::class)->handle($reservation);
    $conversation = hilo($reservation->id);

    llegaFoto($conversation);

    expect($request->refresh()->meta['receipt_check']['verdict'])->toBe(ReceiptCheck::REVIEW)
        ->and($request->meta['receipt_check']['warnings'][0])->toContain('MENOR al cobro')
        ->and($conversation->messages()->where('sender_type', 'system')->value('body'))
        ->toContain('menor al anticipo')
        ->and(StaffNotification::latest('id')->value('title'))->toBe('Comprobante con diferencias');
});

it('marca la cuenta destino que no es del hotel', function () {
    leeComo(comprobante(['destination_account_last4' => '9999']));

    $reservation = apartado();
    $request = app(IssuePaymentRequest::class)->handle($reservation);

    llegaFoto(hilo($reservation->id));

    expect($request->refresh()->meta['receipt_check']['warnings'])
        ->toContain('La cuenta destino (terminación 9999) no coincide con las cuentas del hotel.');
});

it('la misma clave de rastreo no sostiene dos apartados', function () {
    leeComo(comprobante());

    $primera = apartado();
    app(IssuePaymentRequest::class)->handle($primera);
    llegaFoto(hilo($primera->id));

    // Otro huésped (otra reserva) manda EXACTAMENTE el mismo comprobante.
    $segunda = apartado();
    $request = app(IssuePaymentRequest::class)->handle($segunda);
    $conversation = hilo($segunda->id, '5216560000000');
    $vencia = $segunda->refresh()->hold_expires_at;

    [, $outcome] = llegaFoto($conversation, 'reciclado.png');

    expect($outcome)->toBe(InboundMediaService::OUTCOME_STORED)
        ->and($request->refresh()->getFirstMedia('receipt'))->toBeNull()
        ->and($segunda->refresh()->hold_expires_at->eq($vencia))->toBeTrue()
        ->and(StaffNotification::latest('id')->value('title'))->toBe('Comprobante repetido');
});

// ------------------------------------------------------------- el grupo

it('en un grupo deja UN cobro consolidado y acusa con el folio GRP-', function () {
    leeComo(comprobante(['amount' => 3000]));

    $group = app(CreateGroupReservation::class)->handle([
        'mode' => 'night',
        'starts_at' => now()->addDays(3)->setTime(14, 0),
        'ends_at' => now()->addDays(4)->setTime(11, 0),
        'guest_name' => 'Kevin Andrés',
        'lines' => [['room_type_id' => $this->roomType->id, 'rooms' => 2, 'adults' => 4]],
    ]);

    $conversation = hilo($group->reservations()->first()->id);

    [, $outcome] = llegaFoto($conversation, 'spei-grupo.png');

    $requests = PaymentRequest::all();

    expect($outcome)->toBe(InboundMediaService::OUTCOME_RECEIPT)
        // Antes nacía un cobro por cabaña (GRP-2026-0152 amaneció con dos).
        ->and($requests)->toHaveCount(1)
        ->and($requests->first()->reservation_group_id)->toBe($group->id)
        ->and($requests->first()->getFirstMedia('receipt'))->not->toBeNull()
        ->and($conversation->messages()->where('sender_type', 'system')->value('body'))
        ->toContain($group->displayCode());
});

// ------------------------------------------------- sin lector configurado

it('sin lector, todo archivo sigue contando como posible comprobante', function () {
    config(['services.receipt_reader.api_key' => null]);
    Http::fake();

    $reservation = apartado();
    $request = app(IssuePaymentRequest::class)->handle($reservation);

    [, $outcome] = llegaFoto(hilo($reservation->id));

    expect($outcome)->toBe(InboundMediaService::OUTCOME_RECEIPT)
        ->and($request->refresh()->getFirstMedia('receipt'))->not->toBeNull();

    Http::assertNothingSent();
});

// ------------------------------------------- el barrido de apartados

it('un apartado con comprobante sin verificar no se cancela: se sostiene y se vuelve a avisar', function () {
    leeComo(comprobante());

    $reservation = apartado();
    app(IssuePaymentRequest::class)->handle($reservation);
    llegaFoto(hilo($reservation->id));

    // El personal no lo abrió y el plazo ya pasó.
    $reservation->refresh()->update(['hold_expires_at' => now()->subMinutes(5)]);

    test()->artisan('reservations:expire-holds')->assertSuccessful();

    $reservation->refresh();

    expect($reservation->status)->toBe(\App\Enums\ReservationStatus::Pending)
        ->and($reservation->hold_expires_at->isFuture())->toBeTrue()
        ->and(StaffNotification::latest('id')->value('title'))->toBe('Comprobante sin verificar');
});

it('en un grupo, el comprobante de una cabaña sostiene a todas', function () {
    $group = app(CreateGroupReservation::class)->handle([
        'mode' => 'night',
        'starts_at' => now()->addDays(3)->setTime(14, 0),
        'ends_at' => now()->addDays(4)->setTime(11, 0),
        'guest_name' => 'Kevin Andrés',
        'lines' => [['room_type_id' => $this->roomType->id, 'rooms' => 2, 'adults' => 4]],
    ]);

    // La forma vieja, la que hay en producción (GRP-2026-0152): un cobro por
    // cabaña y la foto pegada a uno solo, sin cobro consolidado.
    $rooms = $group->reservations()->orderBy('id')->get();
    $conRecibo = app(IssuePaymentRequest::class)->handle($rooms->last());
    app(IssuePaymentRequest::class)->handle($rooms->first());
    $conRecibo->addMediaFromString(png())->usingFileName('spei.png')->toMediaCollection('receipt');

    expect($conRecibo->refresh()->getFirstMedia('receipt'))->not->toBeNull();

    $rooms->each(fn ($r) => $r->refresh()->update(['hold_expires_at' => now()->subMinutes(5)]));

    test()->artisan('reservations:expire-holds')->assertSuccessful();

    // Las dos siguen vivas: un grupo es todo o nada.
    expect($group->reservations()->where('status', \App\Enums\ReservationStatus::Pending)->count())->toBe(2)
        ->and($group->reservations()->where('status', \App\Enums\ReservationStatus::Cancelled)->count())->toBe(0);
});

it('sin comprobante, el apartado vencido se cancela como siempre', function () {
    $reservation = apartado();
    $reservation->update(['hold_expires_at' => now()->subMinutes(5)]);

    test()->artisan('reservations:expire-holds')->assertSuccessful();

    expect($reservation->refresh()->status)->toBe(\App\Enums\ReservationStatus::Cancelled);
});

it('un cobro con comprobante no se vence solo: la cola de Pagos no lo esconde', function () {
    $reservation = apartado();
    $conRecibo = app(IssuePaymentRequest::class)->handle($reservation);
    $conRecibo->addMediaFromString(png())->usingFileName('spei.png')->toMediaCollection('receipt');

    $otra = apartado();
    $sinRecibo = app(IssuePaymentRequest::class)->handle($otra);

    // A los dos se les acabó la vigencia.
    PaymentRequest::whereIn('id', [$conRecibo->id, $sinRecibo->id])->update(['expires_at' => now()->subMinute()]);

    test()->artisan('payments:expire-requests')->assertSuccessful();

    expect($conRecibo->refresh()->status)->toBe(PaymentRequest::STATUS_PENDING)
        ->and($conRecibo->expires_at->isFuture())->toBeTrue()
        ->and($sinRecibo->refresh()->status)->toBe(PaymentRequest::STATUS_EXPIRED)
        ->and(StaffNotification::where('title', 'Comprobante sin verificar')->exists())->toBeTrue();
});
