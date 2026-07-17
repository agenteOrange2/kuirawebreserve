<?php

use App\Http\Controllers\Webhooks\EvolutionWebhookController;
use App\Models\Central\EvolutionChannelLink;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Services\Channels\OutboundMessenger;
use App\Services\Evolution\EvolutionApi;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
});

function evolutionPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'event' => 'messages.upsert',
        'instance' => 'hotel-demo',
        'data' => [
            'key' => [
                'remoteJid' => '5216141234567@s.whatsapp.net',
                'fromMe' => false,
                'id' => 'BAE5F1A2B3C4',
            ],
            'pushName' => 'María López',
            'message' => ['conversation' => 'Hola, ¿tienen disponibilidad?'],
            'messageType' => 'conversation',
        ],
    ], $overrides);
}

it('normaliza el payload de messages.upsert de Evolution', function () {
    $messages = EvolutionWebhookController::extractMessages(evolutionPayload());

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['from'])->toBe('5216141234567')
        ->and($messages[0]['name'])->toBe('María López')
        ->and($messages[0]['body'])->toBe('Hola, ¿tienen disponibilidad?')
        ->and($messages[0]['externalId'])->toBe('BAE5F1A2B3C4');

    // El formato de evento con guiones bajos (webhook por eventos) también entra.
    expect(EvolutionWebhookController::extractMessages(
        evolutionPayload(['event' => 'MESSAGES_UPSERT']),
    ))->toHaveCount(1);
});

it('ignora ecos propios, grupos y otros eventos', function () {
    expect(EvolutionWebhookController::extractMessages(
        evolutionPayload(['data' => ['key' => ['fromMe' => true]]]),
    ))->toBeEmpty();

    expect(EvolutionWebhookController::extractMessages(
        evolutionPayload(['data' => ['key' => ['remoteJid' => '1203630@g.us']]]),
    ))->toBeEmpty();

    expect(EvolutionWebhookController::extractMessages(
        evolutionPayload(['event' => 'connection.update']),
    ))->toBeEmpty();
});

it('rechaza webhooks con token desconocido', function () {
    $this->postJson('/webhooks/evolution/'.str_repeat('x', 48), evolutionPayload())
        ->assertNotFound();
});

it('envía texto por la instancia con la api key en el header', function () {
    Http::fake(['*' => Http::response(['status' => 'ok'])]);

    $link = EvolutionChannelLink::create([
        'tenant_id' => 'demo',
        'name' => 'Recepción',
        'base_url' => 'https://evolution.test',
        'instance' => 'hotel-demo',
        'api_key' => 'secreta-123',
        'webhook_token' => EvolutionChannelLink::generateToken(),
        'active' => true,
    ]);

    $ok = app(EvolutionApi::class)->sendText($link, '5216141234567', 'Hola desde el hotel');

    expect($ok)->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://evolution.test/message/sendText/hotel-demo'
        && $request->hasHeader('apikey', 'secreta-123')
        && $request['number'] === '5216141234567'
        && $request['text'] === 'Hola desde el hotel');
});

it('despacha la salida por la instancia exacta del canal de la conversación', function () {
    Http::fake(['*' => Http::response(['status' => 'ok'])]);

    $property = Property::factory()->create();

    $link = EvolutionChannelLink::create([
        'tenant_id' => 'demo',
        'base_url' => 'https://evolution.test',
        'instance' => 'ventas',
        'api_key' => 'secreta-123',
        'webhook_token' => EvolutionChannelLink::generateToken(),
        'active' => true,
    ]);

    $channel = Channel::create([
        'property_id' => $property->id,
        'type' => Channel::TYPE_WHATSAPP_EVOLUTION,
        'external_id' => (string) $link->id,
        'name' => 'WhatsApp ventas',
        'mode' => 'auto',
        'active' => true,
    ]);

    $conversation = Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '5216141234567',
        'status' => Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);

    $ok = app(OutboundMessenger::class)->pushToConversation($conversation, 'Tu apartado sigue vigente');

    expect($ok)->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/message/sendText/ventas'));

    // El webchat no empuja nada: el visitante lee por polling.
    $webchat = Channel::create([
        'property_id' => $property->id,
        'type' => Channel::TYPE_WEBCHAT,
        'name' => 'Webchat',
        'mode' => 'auto',
        'active' => true,
    ]);
    $webchatConversation = Conversation::create([
        'channel_id' => $webchat->id,
        'status' => Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);

    expect(app(OutboundMessenger::class)->pushToConversation($webchatConversation, 'Hola'))->toBeFalse();
});

it('calcula un retraso humanizado acotado y proporcional al texto', function () {
    $short = EvolutionApi::humanDelay('Hola');
    $long = EvolutionApi::humanDelay(str_repeat('palabras que suman tecleo ', 40));

    expect($short)->toBeGreaterThanOrEqual(EvolutionApi::DELAY_MIN_MS)
        ->and($short)->toBeLessThanOrEqual(EvolutionApi::DELAY_MAX_MS)
        ->and($long)->toBe(EvolutionApi::DELAY_MAX_MS)
        ->and($long)->toBeGreaterThan($short);
});

it('envía las respuestas del bot con retraso de tecleo', function () {
    Http::fake(['*' => Http::response(['status' => 'ok'])]);

    $link = EvolutionChannelLink::create([
        'tenant_id' => 'demo',
        'base_url' => 'https://evolution.test',
        'instance' => 'hotel-demo',
        'api_key' => 'secreta-123',
        'webhook_token' => EvolutionChannelLink::generateToken(),
        'active' => true,
    ]);

    app(EvolutionApi::class)->sendText($link, '5216141234567', 'Tenemos disponibilidad para hoy', 3500);

    Http::assertSent(fn ($request) => $request['delay'] === 3500
        && $request['text'] === 'Tenemos disponibilidad para hoy');
});

it('permite varias instancias Evolution como canales separados', function () {
    $property = Property::factory()->create();

    $first = Channel::create([
        'property_id' => $property->id,
        'type' => Channel::TYPE_WHATSAPP_EVOLUTION,
        'external_id' => '1',
        'name' => 'Recepción',
        'mode' => 'auto',
        'active' => true,
    ]);
    $second = Channel::create([
        'property_id' => $property->id,
        'type' => Channel::TYPE_WHATSAPP_EVOLUTION,
        'external_id' => '2',
        'name' => 'Ventas',
        'mode' => 'copilot',
        'active' => true,
    ]);

    expect($first->id)->not->toBe($second->id)
        ->and(Channel::where('type', Channel::TYPE_WHATSAPP_EVOLUTION)->count())->toBe(2);
});
