<?php

use App\Actions\Payments\IssuePaymentRequest;
use App\Actions\Reservations\CreateReservation;
use App\Http\Controllers\Webhooks\EvolutionWebhookController;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Channels\InboundMediaService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Storage::fake('local');

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'deposit_percent' => 30,
    ]);
});

/** 1x1 PNG real: el detector de mime debe verlo como imagen. */
function tinyPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}

function mediaReservation(): Reservation
{
    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDay(),
        'confirmed' => false,
        'source_channel' => 'web',
        'guest_name' => 'Ana García',
        'guest_phone' => '6141234567',
    ]);
}

function mediaConversation(?int $reservationId = null): Conversation
{
    $channel = Channel::firstOrCreate(
        ['property_id' => test()->property->id, 'type' => Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );

    return Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '5216141234567',
        'reservation_id' => $reservationId,
        'status' => Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);
}

function inboundMessage(Conversation $conversation): \App\Models\Message
{
    return $conversation->messages()->create([
        'direction' => 'in',
        'sender_type' => 'visitor',
        'body' => '[Imagen]',
        'created_at' => now(),
    ]);
}

it('la foto entrante cae sola como comprobante de la transferencia pendiente y se acusa recibo', function () {
    $reservation = mediaReservation();
    $request = app(IssuePaymentRequest::class)->handle($reservation);
    $conversation = mediaConversation($reservation->id);
    $message = inboundMessage($conversation);

    $outcome = app(InboundMediaService::class)->handle($conversation, $message, tinyPng(), 'image/png', 'comprobante.png');

    expect($outcome)->toBe(InboundMediaService::OUTCOME_RECEIPT)
        ->and($message->getFirstMedia('attachments'))->not->toBeNull()
        ->and($request->refresh()->receiptPayload())->not->toBeNull()
        ->and($request->receiptPayload()['is_image'])->toBeTrue()
        // Acuse sin dar el pago por recibido (regla dura de spec-pagos).
        ->and($conversation->messages()->where('direction', 'out')->where('sender_type', 'system')->value('body'))
        ->toContain('Recibimos tu comprobante')
        ->and($conversation->refresh()->status)->toBe(Conversation::STATUS_OPEN);
});

it('sin cobro pendiente, la foto queda como adjunto y la conversación espera a un humano', function () {
    $conversation = mediaConversation();
    $message = inboundMessage($conversation);

    $outcome = app(InboundMediaService::class)->handle($conversation, $message, tinyPng(), 'image/png');

    expect($outcome)->toBe(InboundMediaService::OUTCOME_STORED)
        ->and($message->getFirstMedia('attachments'))->not->toBeNull()
        ->and($message->attachmentsPayload()[0]['is_image'])->toBeTrue()
        ->and($conversation->refresh()->status)->toBe(Conversation::STATUS_PENDING);
});

it('no pisa un comprobante ya adjunto: la segunda foto solo se guarda en el hilo', function () {
    $reservation = mediaReservation();
    $request = app(IssuePaymentRequest::class)->handle($reservation);
    $conversation = mediaConversation($reservation->id);

    $first = app(InboundMediaService::class)->handle($conversation, inboundMessage($conversation), tinyPng(), 'image/png', 'primero.png');
    $second = app(InboundMediaService::class)->handle($conversation, inboundMessage($conversation), tinyPng(), 'image/png', 'segundo.png');

    expect($first)->toBe(InboundMediaService::OUTCOME_RECEIPT)
        ->and($second)->toBe(InboundMediaService::OUTCOME_STORED)
        ->and($request->refresh()->getFirstMedia('receipt')->file_name)->toBe('primero.png');
});

it('tipos no soportados (audio, stickers) no truenan: no se guarda nada', function () {
    $conversation = mediaConversation();
    $message = inboundMessage($conversation);

    $outcome = app(InboundMediaService::class)->handle($conversation, $message, 'ogg-bytes', 'audio/ogg');

    expect($outcome)->toBeNull()
        ->and($message->getFirstMedia('attachments'))->toBeNull();
});

it('extractMessages de Evolution reconoce imagen y documento con su media descriptor', function () {
    $payload = [
        'event' => 'messages.upsert',
        'instance' => 'hotel-demo',
        'data' => [
            'key' => ['remoteJid' => '5216141234567@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-IMG-1'],
            'pushName' => 'Ana',
            'messageType' => 'imageMessage',
            'message' => [
                'imageMessage' => ['caption' => 'Aquí está mi comprobante', 'mimetype' => 'image/jpeg'],
                'base64' => base64_encode(tinyPng()),
            ],
        ],
    ];

    $messages = EvolutionWebhookController::extractMessages($payload);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['body'])->toBe('Aquí está mi comprobante')
        ->and($messages[0]['media']['mime'])->toBe('image/jpeg')
        ->and($messages[0]['media']['base64'])->not->toBeNull();

    // Documento sin caption: body placeholder y filename para el adjunto.
    $doc = EvolutionWebhookController::extractMessages(array_replace_recursive($payload, [
        'data' => [
            'messageType' => 'documentMessage',
            'message' => [
                'imageMessage' => null,
                'documentMessage' => ['fileName' => 'spei.pdf', 'mimetype' => 'application/pdf'],
                'base64' => null,
            ],
        ],
    ]));

    // array_replace_recursive no borra imageMessage (queda null): el
    // extractor debe ignorar llaves nulas y quedarse con el documento.
    expect($doc[0]['media']['filename'])->toBe('spei.pdf')
        ->and($doc[0]['body'])->toBe('[Documento]');
});

it('downloadMedia de Meta baja el binario en dos pasos con el token', function () {
    config(['meta.graph_url' => 'https://graph.test/v21.0']);
    Http::fake([
        'graph.test/v21.0/MEDIA123' => Http::response(['url' => 'https://lookaside.test/whatsapp/file-1', 'mime_type' => 'image/jpeg']),
        'lookaside.test/*' => Http::response('JPEG-BYTES'),
    ]);

    $link = \App\Models\Central\MetaChannelLink::create([
        'tenant_id' => 'demo',
        'type' => 'whatsapp',
        'external_id' => 'PHONE1',
        'access_token' => 'tok-1',
        'active' => true,
    ]);

    $result = app(\App\Services\Meta\MetaApi::class)->downloadMedia($link, 'MEDIA123');

    expect($result)->not->toBeNull()
        ->and($result['contents'])->toBe('JPEG-BYTES')
        ->and($result['mime'])->toBe('image/jpeg');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'lookaside.test')
        && $request->hasHeader('Authorization', 'Bearer tok-1'));
});

it('el historial del bot anota los adjuntos: nunca debe negar una imagen que sí llegó', function () {
    $conversation = mediaConversation();
    $message = inboundMessage($conversation);
    app(InboundMediaService::class)->handle($conversation, $message, tinyPng(), 'image/png');

    $conversation->messages()->create([
        'direction' => 'in', 'sender_type' => 'visitor', 'body' => 'sin adjunto', 'created_at' => now(),
    ]);

    $history = (new ReflectionMethod(\App\Services\Agent\AgentBrain::class, 'history'))
        ->invoke(app(\App\Services\Agent\AgentBrain::class), $conversation);

    $contents = collect($history)->map(fn ($m) => $m->content);

    expect($contents->first(fn ($c) => str_contains($c, '[Imagen]')))
        ->toContain('[adjuntó una imagen o documento — el personal puede verlo]')
        ->and($contents->first(fn ($c) => str_contains($c, 'sin adjunto')))
        ->not->toContain('adjuntó');
});

it('reemitir un cobro rechazado crea uno nuevo y rescata el comprobante que llegó por el chat', function () {
    $reservation = mediaReservation();
    $rejected = app(IssuePaymentRequest::class)->handle($reservation);
    $rejected->update(['status' => PaymentRequest::STATUS_REJECTED, 'meta' => ['rejected_reason' => 'Monto incorrecto']]);

    // La foto buena llega DESPUÉS del rechazo: sin cobro pendiente queda
    // solo en el hilo — el rescate del reemitir la recupera de ahí.
    $conversation = mediaConversation($reservation->id);
    app(InboundMediaService::class)->handle($conversation, inboundMessage($conversation), tinyPng(), 'image/png', 'bueno.png');

    $response = app(\App\Http\Controllers\Tenant\PaymentRequestController::class)
        ->reissue($rejected, app(IssuePaymentRequest::class));
    $data = $response->getData(true);
    $fresh = PaymentRequest::find($data['request_id']);

    expect($response->getStatusCode())->toBe(200)
        ->and($data['rescued_receipt'])->toBeTrue()
        ->and($fresh->status)->toBe(PaymentRequest::STATUS_PENDING)
        ->and($fresh->id)->not->toBe($rejected->id)
        ->and($fresh->getFirstMedia('receipt')->file_name)->toBe('bueno.png');
});

it('no se reemite un cobro que ya está pagado', function () {
    $reservation = mediaReservation();
    $request = app(IssuePaymentRequest::class)->handle($reservation);
    $request->update(['status' => PaymentRequest::STATUS_PAID]);

    $response = app(\App\Http\Controllers\Tenant\PaymentRequestController::class)
        ->reissue($request, app(IssuePaymentRequest::class));

    expect($response->getStatusCode())->toBe(422);
});
