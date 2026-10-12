<?php

use App\Http\Controllers\Tenant\InboxController;
use App\Jobs\ResumeBotReply;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

/**
 * "Devolver al bot" (2026-10-08): si el huésped quedó esperando, el bot le
 * contesta en cuanto se le devuelve la conversación; antes se quedaba
 * callado hasta el siguiente mensaje. Los avisos del sistema no cuentan
 * como respuesta.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    $this->property = Property::factory()->create();
    Queue::fake();
});

function pausedConversation(array $messages): Conversation
{
    $channel = Channel::firstOrCreate(
        ['property_id' => test()->property->id, 'type' => Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );
    $conversation = Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '+5216141234567',
        'status' => Conversation::STATUS_OPEN,
        'bot_enabled' => false,
        'last_message_at' => now(),
    ]);
    foreach ($messages as [$direction, $sender, $body]) {
        $conversation->messages()->create([
            'direction' => $direction,
            'sender_type' => $sender,
            'body' => $body,
            'created_at' => now(),
        ]);
    }

    return $conversation->refresh();
}

function returnToBot(Conversation $conversation, ?string $respond = null): array
{
    $request = Request::create("/api/inbox/{$conversation->id}", 'PATCH', array_filter([
        'bot_enabled' => true,
        'status' => 'open',
        'respond' => $respond,
    ]));

    return app(InboxController::class)->update($request, $conversation)->getData(true);
}

it('si el huésped quedó esperando tras el traspaso, el bot contesta al devolverlo', function () {
    $conversation = pausedConversation([
        ['in', 'visitor', 'Carmen Ramírez, 4 personas'],
        ['out', 'system', 'Te comunicamos con una persona del hotel; en un momento te atienden.'],
        ['in', 'visitor', 'Gracias'],
    ]);

    expect(returnToBot($conversation)['bot_responding'])->toBeTrue()
        ->and($conversation->refresh()->bot_enabled)->toBeTrue();
    Queue::assertPushed(ResumeBotReply::class, fn ($job) => $job->conversationId === $conversation->id);
});

it('el aviso del sistema no cuenta como respuesta', function () {
    $conversation = pausedConversation([
        ['in', 'visitor', '¿Tienen la cabaña real el sábado?'],
        ['out', 'system', 'Te comunicamos con una persona del hotel.'],
    ]);

    expect(ResumeBotReply::guestIsWaiting($conversation))->toBeTrue()
        ->and(returnToBot($conversation)['bot_responding'])->toBeTrue();
});

it('si lo último fue del hotel no escribe, salvo que se le pida', function () {
    $conversation = pausedConversation([
        ['in', 'visitor', 'Hola'],
        ['out', 'staff', 'Hola, ya te mandamos tu liga de pago.'],
    ]);

    expect(returnToBot($conversation)['bot_responding'])->toBeFalse();
    Queue::assertNotPushed(ResumeBotReply::class);

    $conversation->update(['bot_enabled' => false]);
    expect(returnToBot($conversation, 'now')['bot_responding'])->toBeTrue();
    Queue::assertPushed(ResumeBotReply::class);
});

it('con respond=no solo enciende el interruptor, como antes', function () {
    $conversation = pausedConversation([['in', 'visitor', 'Hola']]);

    expect(returnToBot($conversation, 'no')['bot_responding'])->toBeFalse()
        ->and($conversation->refresh()->bot_enabled)->toBeTrue();
    Queue::assertNotPushed(ResumeBotReply::class);
});

it('si el bot ya estaba activo no vuelve a disparar respuesta', function () {
    $conversation = pausedConversation([['in', 'visitor', 'Hola']]);
    $conversation->update(['bot_enabled' => true]);

    expect(returnToBot($conversation)['bot_responding'])->toBeFalse();
    Queue::assertNotPushed(ResumeBotReply::class);
});
