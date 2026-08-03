<?php

use App\Http\Controllers\Tenant\InboxController;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
});

function inboxConversation(array $overrides = []): Conversation
{
    $channel = Channel::firstOrCreate(
        ['property_id' => test()->property->id, 'type' => Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );

    return Conversation::create(array_replace([
        'channel_id' => $channel->id,
        'contact_phone' => '+5216141234567',
        'status' => Conversation::STATUS_RESOLVED,
        'last_message_at' => now(),
    ], $overrides));
}

it('archiva de golpe las resueltas y respeta abiertas y ya archivadas', function () {
    $resolved = inboxConversation();
    $open = inboxConversation(['status' => Conversation::STATUS_OPEN, 'contact_phone' => '+5216140000001']);
    $alreadyArchived = inboxConversation(['archived_at' => now()->subDay(), 'contact_phone' => '+5216140000002']);

    $response = app(InboxController::class)->archiveResolved();

    expect($response->getData(true)['archived'])->toBe(1)
        ->and($resolved->refresh()->archived_at)->not->toBeNull()
        ->and($open->refresh()->archived_at)->toBeNull()
        ->and($alreadyArchived->refresh()->archived_at->isBefore(now()->subHours(12)))->toBeTrue();
});

it('una conversación archivada vuelve sola a la bandeja cuando el huésped escribe', function () {
    $conversation = inboxConversation(['archived_at' => now()]);

    $conversation->messages()->create([
        'direction' => 'in',
        'sender_type' => 'guest',
        'body' => 'Hola, quiero otra reservación',
        'created_at' => now(),
    ]);

    $conversation->refresh();

    expect($conversation->archived_at)->toBeNull()
        ->and($conversation->status)->toBe(Conversation::STATUS_OPEN);
});

it('la retención elimina solo las archivadas con más de 30 días', function () {
    $old = inboxConversation(['archived_at' => now()->subDays(31)]);
    $recent = inboxConversation(['archived_at' => now()->subDays(5), 'contact_phone' => '+5216140000003']);
    $active = inboxConversation(['status' => Conversation::STATUS_OPEN, 'contact_phone' => '+5216140000004']);

    $this->artisan('conversations:prune-archived')
        ->expectsOutputToContain('1 conversación(es)')
        ->assertSuccessful();

    expect(Conversation::find($old->id))->toBeNull()
        ->and(Conversation::find($recent->id))->not->toBeNull()
        ->and(Conversation::find($active->id))->not->toBeNull();
});

it('vaciar el archivo borra todas las archivadas y respeta la bandeja', function () {
    inboxConversation(['archived_at' => now()->subDay()]);
    inboxConversation(['archived_at' => now(), 'contact_phone' => '+5216140000005']);
    $active = inboxConversation(['status' => Conversation::STATUS_OPEN, 'contact_phone' => '+5216140000006']);

    $response = app(InboxController::class)->destroyArchived();

    expect($response->getData(true)['deleted'])->toBe(2)
        ->and(Conversation::whereNotNull('archived_at')->count())->toBe(0)
        ->and(Conversation::find($active->id))->not->toBeNull();
});

it('los mensajes salientes no desarchivan la conversación', function () {
    $conversation = inboxConversation(['archived_at' => now()]);

    $conversation->messages()->create([
        'direction' => 'out',
        'sender_type' => 'bot',
        'body' => 'Recordatorio de saldo',
        'created_at' => now(),
    ]);

    expect($conversation->refresh()->archived_at)->not->toBeNull();
});
