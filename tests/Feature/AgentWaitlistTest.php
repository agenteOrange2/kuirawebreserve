<?php

use App\Http\Controllers\Agent\AgentToolsController;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\WaitlistEntry;
use Illuminate\Http\Request;

// En 2.5 días de septiembre, más de 40 conversaciones de cabañas chocaron con
// "no hay disponibilidad" para el 18-19 y el 25-26 y ahí se acabaron. El
// módulo de lista de espera estaba encendido; el bot no tenía con qué apuntar
// a nadie.

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create(['name' => 'Cabañas Real de la Sierra']);
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Cabaña Real']);

    $channel = Channel::firstOrCreate(
        ['property_id' => $this->property->id, 'type' => Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );

    $this->conversation = Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '5216563584124',
        'status' => Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);
});

function apuntar(array $params): array
{
    $request = Request::create('/brain', 'POST', $params);

    return json_decode(app(AgentToolsController::class)->joinWaitlist($request)->getContent(), true);
}

it('apunta al huésped con el teléfono del chat, no con lo que teclee', function () {
    $salida = apuntar([
        'guest_name' => 'Elliot',
        'guest_phone' => '656 850',   // incompleto: el del chat manda
        'starts_at' => now()->addDays(8)->toDateString(),
        'ends_at' => now()->addDays(9)->toDateString(),
        'room_type_id' => $this->roomType->id,
        'conversation_id' => $this->conversation->id,
    ]);

    $entry = WaitlistEntry::first();

    expect($salida['ok'])->toBeTrue()
        ->and($salida['message'])->toContain('lista de espera')
        ->and($entry->guest_phone)->toBe('5216563584124')
        ->and($entry->conversation_id)->toBe($this->conversation->id)
        ->and($entry->status)->toBe(WaitlistEntry::STATUS_WAITING);
});

it('no apunta dos veces al mismo huésped para las mismas fechas', function () {
    $datos = [
        'guest_name' => 'Elliot',
        'starts_at' => now()->addDays(8)->toDateString(),
        'ends_at' => now()->addDays(9)->toDateString(),
        'conversation_id' => $this->conversation->id,
    ];

    apuntar($datos);
    $segunda = apuntar($datos);

    expect(WaitlistEntry::count())->toBe(1)
        ->and($segunda['ya_estaba'])->toBeTrue();
});

it('sin forma de avisarle no lo apunta', function () {
    $sinChat = Conversation::create([
        'channel_id' => $this->conversation->channel_id,
        'contact_phone' => 'web-123',
        'status' => Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);

    $request = Request::create('/brain', 'POST', [
        'guest_name' => 'Elliot',
        'starts_at' => now()->addDays(8)->toDateString(),
        'ends_at' => now()->addDays(9)->toDateString(),
        'conversation_id' => $sinChat->id,
    ]);

    $respuesta = app(AgentToolsController::class)->joinWaitlist($request);

    expect($respuesta->getStatusCode())->toBe(422)
        ->and(WaitlistEntry::count())->toBe(0);
});

it('no apunta para una fecha que ya pasó', function () {
    $request = Request::create('/brain', 'POST', [
        'guest_name' => 'Elliot',
        'starts_at' => now()->subDays(3)->toDateString(),
        'ends_at' => now()->subDay()->toDateString(),
        'conversation_id' => $this->conversation->id,
    ]);

    expect(fn () => app(AgentToolsController::class)->joinWaitlist($request))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
