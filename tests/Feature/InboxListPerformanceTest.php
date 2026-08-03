<?php

use App\Http\Controllers\Tenant\InboxController;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

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

function conversationWithMessages(int $n, string $phone): Conversation
{
    $conversation = Conversation::create([
        'channel_id' => test()->channel->id,
        'contact_phone' => $phone,
        'status' => Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);

    foreach (range(1, $n) as $i) {
        $conversation->messages()->create([
            'direction' => $i % 2 === 0 ? 'out' : 'in',
            'sender_type' => $i % 2 === 0 ? 'staff' : 'guest',
            'body' => "Mensaje {$i} de {$phone}",
            'created_at' => now()->addSeconds($i),
        ]);
    }

    return $conversation->refresh();
}

/** Lista de conversaciones tal como la arma la bandeja, ya serializada. */
function serializedInbox(): array
{
    $controller = app(InboxController::class);

    return (function () {
        return $this->conversationQuery()
            ->orderByDesc('last_message_at')
            ->get()
            ->map(fn (Conversation $c) => $this->serializeConversation($c))
            ->all();
    })->call($controller);
}

it('la vista previa se guarda con el último mensaje, venga de quien venga', function () {
    $conversation = conversationWithMessages(3, '+5216141234567');

    expect($conversation->last_message_preview)->toBe('Mensaje 3 de +5216141234567');

    $conversation->messages()->create([
        'direction' => 'out',
        'sender_type' => 'staff',
        'body' => 'Respuesta del mostrador',
        'created_at' => now()->addMinute(),
    ]);

    expect($conversation->refresh()->last_message_preview)->toBe('Respuesta del mostrador');
});

it('la vista previa se recorta para no guardar el mensaje completo', function () {
    $conversation = conversationWithMessages(1, '+5216140000009');

    $conversation->messages()->create([
        'direction' => 'in',
        'sender_type' => 'guest',
        'body' => str_repeat('a', 900),
        'created_at' => now()->addMinute(),
    ]);

    $preview = (string) $conversation->refresh()->last_message_preview;

    // Recortada, pero con contenido: sin el "not empty" un preview nulo
    // también cumpliría el tope y el test pasaría en falso.
    expect($preview)->not->toBeEmpty()
        ->and(strlen($preview))->toBeLessThanOrEqual(253);
});

it('la bandeja entrega la vista previa del último mensaje', function () {
    conversationWithMessages(3, '+5216141234567');

    expect(serializedInbox()[0]['preview'])->toBe('Mensaje 3 de +5216141234567');
});

it('serializar la bandeja no cuesta consultas por conversación', function () {
    foreach (range(1, 12) as $i) {
        conversationWithMessages(4, '+52161400000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $rows = serializedInbox();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Antes cada conversación costaba dos consultas extra (vista previa y
    // transferencia por verificar): 12 conversaciones sumaban 24 de más.
    // Ahora todo viaja en la consulta base y sus eager loads.
    expect($rows)->toHaveCount(12)
        ->and($queries)->toBeLessThanOrEqual(6);
});
