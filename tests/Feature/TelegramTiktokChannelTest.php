<?php

use App\Http\Controllers\Webhooks\TelegramWebhookController;
use App\Http\Controllers\Webhooks\TiktokWebhookController;
use App\Models\Central\TelegramChannelLink;
use App\Models\Central\TiktokChannelLink;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Services\Channels\OutboundMessenger;
use App\Services\Telegram\TelegramApi;
use App\Services\Tiktok\TiktokApi;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
});

function telegramUpdate(array $overrides = []): array
{
    return array_replace_recursive([
        'update_id' => 900001,
        'message' => [
            'message_id' => 55,
            'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'María', 'last_name' => 'López'],
            'chat' => ['id' => 777, 'type' => 'private'],
            'text' => 'Hola, ¿tienen disponibilidad?',
        ],
    ], $overrides);
}

it('normaliza el update de Telegram a mensaje entrante', function () {
    $inbound = TelegramWebhookController::extractMessage(telegramUpdate());

    expect($inbound)->not->toBeNull()
        ->and($inbound['from'])->toBe('777')
        ->and($inbound['name'])->toBe('María López')
        ->and($inbound['body'])->toBe('Hola, ¿tienen disponibilidad?')
        ->and($inbound['externalId'])->toBe('777:55');
});

it('ignora grupos, bots y updates sin mensaje', function () {
    expect(TelegramWebhookController::extractMessage(
        telegramUpdate(['message' => ['chat' => ['type' => 'group']]]),
    ))->toBeNull();

    expect(TelegramWebhookController::extractMessage(
        telegramUpdate(['message' => ['from' => ['is_bot' => true]]]),
    ))->toBeNull();

    expect(TelegramWebhookController::extractMessage(['update_id' => 1]))->toBeNull();
});

it('convierte foto de Telegram en media con placeholder', function () {
    $inbound = TelegramWebhookController::extractMessage(telegramUpdate([
        'message' => [
            'text' => null,
            'photo' => [
                ['file_id' => 'small', 'width' => 90],
                ['file_id' => 'big', 'width' => 800],
            ],
        ],
    ]));

    expect($inbound['media']['file_id'])->toBe('big')
        ->and($inbound['body'])->toBe('[Imagen]');
});

it('rechaza webhooks de Telegram y TikTok con token desconocido', function () {
    $this->postJson('/webhooks/telegram/'.str_repeat('x', 48), telegramUpdate())
        ->assertNotFound();

    $this->postJson('/webhooks/tiktok/'.str_repeat('x', 48), ['event' => 'message'])
        ->assertNotFound();
});

it('envía texto por la Bot API de Telegram con el chat_id', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'result' => []])]);

    $link = TelegramChannelLink::create([
        'tenant_id' => 'demo',
        'name' => 'Recepción',
        'bot_id' => '123456',
        'bot_token' => '123456:ABCDEF',
        'bot_username' => 'hotel_bot',
        'webhook_token' => TelegramChannelLink::generateToken(),
        'active' => true,
    ]);

    $ok = app(TelegramApi::class)->sendText($link, '777', 'Hola desde el hotel');

    expect($ok)->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bot123456:ABCDEF/sendMessage'
        && $request['chat_id'] === '777'
        && $request['text'] === 'Hola desde el hotel');
});

it('despacha la salida por el bot exacto del canal de la conversación', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'result' => []])]);

    $property = Property::factory()->create();

    $link = TelegramChannelLink::create([
        'tenant_id' => 'demo',
        'bot_id' => '123456',
        'bot_token' => '123456:ABCDEF',
        'webhook_token' => TelegramChannelLink::generateToken(),
        'active' => true,
    ]);

    $channel = Channel::create([
        'property_id' => $property->id,
        'type' => Channel::TYPE_TELEGRAM,
        'external_id' => (string) $link->id,
        'name' => 'Telegram',
        'mode' => 'auto',
        'active' => true,
    ]);

    $conversation = Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '777',
        'status' => Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);

    expect(app(OutboundMessenger::class)->pushToConversation($conversation, 'Tu apartado sigue vigente'))->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/bot123456:ABCDEF/sendMessage'));
});

it('normaliza el payload de mensajes de TikTok e ignora ecos propios', function () {
    $messages = TiktokWebhookController::extractMessages([
        'event' => 'im.message.received',
        'data' => [
            'sender_id' => 'user-abc',
            'recipient_id' => 'biz-1',
            'message_id' => 'm-1',
            'message' => ['type' => 'text', 'text' => 'Hola, quiero una habitación'],
        ],
    ]);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['from'])->toBe('user-abc')
        ->and($messages[0]['body'])->toBe('Hola, quiero una habitación')
        ->and($messages[0]['externalId'])->toBe('m-1');

    // Eco: la propia cuenta business hablando.
    expect(TiktokWebhookController::extractMessages([
        'event' => 'im.message.received',
        'data' => ['sender_id' => 'biz-1', 'recipient_id' => 'biz-1', 'message' => ['text' => 'eco']],
    ]))->toBeEmpty();

    // Evento ajeno a mensajería.
    expect(TiktokWebhookController::extractMessages([
        'event' => 'video.published',
        'data' => ['sender_id' => 'user-abc'],
    ]))->toBeEmpty();
});

it('responde el challenge de verificación del webhook de TikTok', function () {
    $link = TiktokChannelLink::create([
        'tenant_id' => 'demo',
        'business_id' => 'biz-1',
        'access_token' => 'act.secreto',
        'webhook_token' => TiktokChannelLink::generateToken(),
        'active' => true,
    ]);

    $this->postJson('/webhooks/tiktok/'.$link->webhook_token, ['challenge' => 'abc123'])
        ->assertOk()
        ->assertJson(['challenge' => 'abc123']);
});

it('envía texto por la Business API de TikTok con el access token', function () {
    Http::fake(['*' => Http::response(['code' => 0, 'message' => 'OK'])]);

    $link = TiktokChannelLink::create([
        'tenant_id' => 'demo',
        'business_id' => 'biz-1',
        'access_token' => 'act.secreto',
        'webhook_token' => TiktokChannelLink::generateToken(),
        'active' => true,
    ]);

    $ok = app(TiktokApi::class)->sendText($link, 'user-abc', 'Hola desde el hotel');

    expect($ok)->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/business/message/send/')
        && $request->hasHeader('Access-Token', 'act.secreto')
        && $request['recipient_id'] === 'user-abc'
        && $request['message']['text'] === 'Hola desde el hotel');
});
