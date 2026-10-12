<?php

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\User;
use App\Services\Agent\AgentBrain;

/**
 * TRASPASAR NO ES CALLARSE. Auditoría cabañas 24-sep → 8-oct (2026-10-08):
 * tras un traspaso del bot el personal tardó de 2 a 40 horas, y mientras
 * el huésped seguía preguntando cosas que el bot sabía contestar.
 *
 * coversHandoff decide cuándo el bot vuelve a contestar "de guardia".
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create(['name' => 'Cabañas Real de la Sierra']);
    $this->channel = Channel::create([
        'property_id' => $this->property->id,
        'type' => Channel::TYPE_WHATSAPP_EVOLUTION,
        'external_id' => '1',
        'name' => 'WhatsApp',
        'mode' => 'auto',
        'active' => true,
    ]);

    // De 11 de la mañana: horario en que el personal SÍ debería contestar.
    $this->travelTo(now()->setTime(11, 0));
});

function traspasada(int $hace = 20): Conversation
{
    $conversation = Conversation::create([
        'channel_id' => test()->channel->id,
        'contact_phone' => '5216561234567',
        'status' => Conversation::STATUS_PENDING,
        'bot_enabled' => false,
        'last_message_at' => now(),
    ]);

    $conversation->messages()->create(['direction' => 'in', 'sender_type' => 'guest', 'body' => '¿Puedo llevar mi asador?', 'created_at' => now()->subMinutes($hace + 1)]);
    $conversation->messages()->create(['direction' => 'out', 'sender_type' => 'system', 'body' => 'Te comunicamos con una persona del hotel; en un momento te atienden.', 'created_at' => now()->subMinutes($hace)]);

    return $conversation;
}

it('contesta de guardia si nadie del personal tomó el traspaso en 10 minutos', function () {
    expect(app(AgentBrain::class)->coversHandoff(traspasada(20)))->toBeTrue();
});

it('le da tiempo al personal antes de volver a contestar', function () {
    expect(app(AgentBrain::class)->coversHandoff(traspasada(3)))->toBeFalse();
});

it('se calla en cuanto alguien del personal escribe', function () {
    $conversation = traspasada(30);
    $conversation->messages()->create(['direction' => 'out', 'sender_type' => 'staff', 'body' => 'Hola, sí se puede.', 'created_at' => now()->subMinutes(5)]);

    expect(app(AgentBrain::class)->coversHandoff($conversation))->toBeFalse();
});

it('sigue de guardia aunque ya haya contestado algo en ese rato', function () {
    $conversation = traspasada(40);
    $conversation->messages()->create(['direction' => 'out', 'sender_type' => 'bot', 'body' => 'La Cabaña Real tiene dos habitaciones.', 'created_at' => now()->subMinutes(5)]);

    expect(app(AgentBrain::class)->coversHandoff($conversation))->toBeTrue();
});

it('respeta la conversación que el personal tomó o apagó a mano', function () {
    $asignada = traspasada(30);
    $asignada->update(['assigned_to' => User::factory()->create()->id]);

    $sinAviso = Conversation::create([
        'channel_id' => test()->channel->id,
        'contact_phone' => '5216569999999',
        'status' => Conversation::STATUS_PENDING,
        'bot_enabled' => false,
        'last_message_at' => now(),
    ]);
    $sinAviso->messages()->create(['direction' => 'in', 'sender_type' => 'guest', 'body' => 'Hola', 'created_at' => now()->subHour()]);

    expect(app(AgentBrain::class)->coversHandoff($asignada))->toBeFalse()
        ->and(app(AgentBrain::class)->coversHandoff($sinAviso))->toBeFalse();
});

it('de noche no espera: nadie va a contestar', function () {
    $this->travelTo(now()->setTime(23, 30));

    expect(app(AgentBrain::class)->coversHandoff(traspasada(2)))->toBeTrue();
});
