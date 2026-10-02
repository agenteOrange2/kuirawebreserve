<?php

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Services\Agent\AgentBrain;
use Carbon\CarbonImmutable;

/**
 * Caso real cabañas 2026-09-29 (Messenger, conv. 1631): "¿Tiene disponible
 * 24 y 25?" y el bot cotizó septiembre de 2027. El dueño pidió que con solo
 * el número del día el bot pregunte el mes antes de consultar o cotizar.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    $this->travelTo(CarbonImmutable::parse('2026-09-29 16:56', 'America/Ciudad_Juarez'));

    $this->property = Property::factory()->create();
});

function chatSinMes(array $mensajes): Conversation
{
    $channel = Channel::firstOrCreate(
        ['property_id' => test()->property->id, 'type' => Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );
    $conversation = Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '521656'.random_int(1000000, 9999999),
        'status' => Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);

    foreach ($mensajes as [$direccion, $texto]) {
        $conversation->messages()->create([
            'direction' => $direccion,
            'sender_type' => $direccion === 'in' ? 'visitor' : 'agent',
            'body' => $texto,
            'created_at' => now(),
        ]);
    }

    return $conversation;
}

function diasSinMes(Conversation $conversation): array
{
    return (fn () => $this->dayWithoutMonth($conversation))->call(app(AgentBrain::class));
}

it('con solo el número del día pide el mes antes de cotizar', function () {
    $conversation = chatSinMes([
        ['in', 'Cabaña Real para 6 personas'],
        ['out', '¿En qué fechas la necesitas? ¿Para cuántas noches?'],
        ['in', 'Tiene disponible 24 y 25?'],
    ]);

    $bloque = (fn () => $this->requestedDatesBlock($conversation))->call(app(AgentBrain::class));

    expect(diasSinMes($conversation))->toBe([24, 25])
        ->and($bloque)->toContain('FECHA SIN MES')
        ->and($bloque)->toContain('24 y 25')
        ->and($bloque)->not->toContain('2027');
});

it('cuenta también "el 24" y "sábado 24" sin mes', function (string $dicho) {
    expect(diasSinMes(chatSinMes([['in', $dicho]])))->toBe([24]);
})->with(['para el 24', 'el sábado 24']);

it('no pregunta el mes cuando ya viene dicho', function (string $dicho) {
    expect(diasSinMes(chatSinMes([['in', $dicho]])))->toBe([]);
})->with([
    '24 y 25 de octubre',
    'el 24 de octubre',
    '24/10',
    'octubre',
    'Somos 4 y 2 niños',
    'el sábado',
]);

it('no lo pregunta si eligió un día que el bot le ofreció con su mes', function () {
    $conversation = chatSinMes([
        ['out', "Para esa fecha no hay. Te puedo ofrecer:\n- Sábado 24 de octubre\n- Sábado 31 de octubre"],
        ['in', 'El sábado 24'],
    ]);

    expect(diasSinMes($conversation))->toBe([]);
});
