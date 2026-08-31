<?php

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;

/**
 * Reenganche de cotizaciones frías. Los tres casos vienen de la bandeja
 * real de cabañas (2026-08-28/30), donde el "¿sigues por ahí?" salió
 * cuando no debía o prometió algo que el bot acababa de negar.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->channel = Channel::create([
        'property_id' => $this->property->id,
        'type' => Channel::TYPE_WHATSAPP_EVOLUTION,
        'external_id' => '1',
        'name' => 'WhatsApp',
        'mode' => 'auto',
        'active' => true,
    ]);
});

function coldConversation(array $messages, string $phone = '+5216141234567'): Conversation
{
    $conversation = Conversation::create([
        'channel_id' => test()->channel->id,
        'contact_phone' => $phone,
        'status' => Conversation::STATUS_OPEN,
        'lead_status' => Conversation::LEAD_QUOTING,
        'bot_enabled' => true,
        'last_message_at' => now()->subHour(),
    ]);

    foreach ($messages as [$direction, $body]) {
        $conversation->messages()->create([
            'direction' => $direction,
            'sender_type' => $direction === 'in' ? 'visitor' : 'bot',
            'body' => $body,
            'created_at' => now()->subHour(),
        ]);
    }

    return $conversation;
}

function lastBody(Conversation $conversation): string
{
    return (string) $conversation->messages()->latest('id')->first()->body;
}

it('reengancha al huésped que se quedó callado a media cotización', function () {
    $conversation = coldConversation([
        ['in', 'Hola, ¿precio de la cabaña?'],
        ['out', 'La Cabaña Escondida son $3,000 por noche. ¿Para qué fechas?'],
    ]);

    test()->artisan('conversations:follow-up')->assertSuccessful();

    expect($conversation->messages()->count())->toBe(3)
        ->and(lastBody($conversation))->toContain('¿Sigues por ahí?')
        ->and($conversation->refresh()->followupSent('quote_nudge'))->toBeTrue();
});

it('no persigue a quien nunca escribió (hilos que abre el bot en redes)', function () {
    $conversation = coldConversation([
        ['out', 'Hola, gracias por tu interés en Cabañas Real de la Sierra. ¿Cuántas personas viajan?'],
    ]);

    test()->artisan('conversations:follow-up')->assertSuccessful();

    expect($conversation->messages()->count())->toBe(1)
        ->and($conversation->refresh()->followupSent('quote_nudge'))->toBeFalse();
});

it('no persigue a quien ya se despidió o dijo que él avisa', function () {
    $conversation = coldConversation([
        ['in', '¿Me da precios de las cabañas?'],
        ['out', 'Los precios por noche son: - Cabaña Real: $4,500'],
        ['in', 'Aún no lo he empezado a planear pero en cuanto tenga la fecha se lo hago saber'],
        ['out', 'Perfecto, cuando guste. Buen día.'],
    ]);

    test()->artisan('conversations:follow-up')->assertSuccessful();

    expect($conversation->messages()->count())->toBe(4);
});

it('tras un "no hay disponibilidad" no promete apartar: ofrece otras fechas', function () {
    $conversation = coldConversation([
        ['in', '¿Tiene disponible el 5 y 6 de septiembre?'],
        ['out', 'Lamento informarle que no hay disponibilidad para el fin de semana del 5 y 6 de septiembre.'],
    ]);

    test()->artisan('conversations:follow-up')->assertSuccessful();

    $body = lastBody($conversation);

    expect($body)->toContain('no me quedó nada libre')
        ->and($body)->not->toContain('apartar')
        ->and($body)->toContain('otras');
});
