<?php

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\StaffNotification;
use App\Services\Channels\MetaDeliveryFailures;

// Auditoría del 2026-09-24 sobre el VPS de cabañas: 350 mensajes rechazados
// por WhatsApp en 12 días y CERO marcados en la bandeja. El fallo llega
// después del envío, por webhook, y hasta ahora solo se escribía en la
// bitácora: el hotel creía que había contestado y el huésped no recibió nada.

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create([
        'settings' => [
            'transfer_whatsapps' => [['code' => '52', 'number' => '6568508818']],
        ],
    ]);

    $channel = Channel::firstOrCreate(
        ['property_id' => $this->property->id, 'type' => 'whatsapp', 'external_id' => null],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );

    $this->conversation = Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '5216561234567',
        'contact_name' => 'Huésped de prueba',
        'status' => Conversation::STATUS_OPEN,
        'bot_enabled' => true,
        'last_message_at' => now(),
    ]);
});

function respuestaEnviada(array $meta = []): \App\Models\Message
{
    return test()->conversation->messages()->create([
        'direction' => 'out',
        'sender_type' => 'bot',
        'body' => 'Para el sábado 26 tenemos la Cabaña Real en $4,500.',
        'meta' => $meta,
        'created_at' => now(),
    ]);
}

function fallo(string $to, int $code, ?string $wamid = null): void
{
    app(MetaDeliveryFailures::class)->apply($wamid, $to, [[
        'code' => $code,
        'title' => 'Re-engagement message',
        'error_data' => ['details' => 'Message failed to send because more than 24 hours have passed'],
    ]]);
}

it('marca el mensaje exacto que no llegó y lo pone a la vista del personal', function () {
    $mensaje = respuestaEnviada(['wamids' => ['wamid.ABC123']]);

    fallo('5216561234567', 131047, 'wamid.ABC123');

    $mensaje->refresh();
    $this->conversation->refresh();

    expect($mensaje->meta['undelivered'])->toBeTrue()
        ->and($mensaje->meta['delivery_error_code'])->toBe(131047)
        ->and($mensaje->meta['delivery_error'])->toContain('24 horas')
        // El hilo queda esperando a una persona y suena la campana.
        ->and($this->conversation->status)->toBe(Conversation::STATUS_PENDING)
        ->and(StaffNotification::where('title', 'Respuesta no entregada')->exists())->toBeTrue();
});

it('sin el id de Meta se queda con el último mensaje que le mandamos', function () {
    // Los envíos anteriores a este cambio no guardaron el id.
    $mensaje = respuestaEnviada();

    fallo('5216561234567', 131026);

    expect($mensaje->refresh()->meta['undelivered'])->toBeTrue()
        ->and($mensaje->meta['delivery_error'])->toContain('no recibe WhatsApp');
});

it('un aviso al propio hotel avisa por la campana, no toca conversaciones', function () {
    $mensaje = respuestaEnviada();

    // 90 de los 350 fallos iban al número del hotel: sus avisos de traspaso
    // morían en silencio porque nadie le había escrito al bot en 24 h.
    fallo('5216568508818', 131047);

    expect($mensaje->refresh()->meta['undelivered'] ?? false)->toBeFalse()
        ->and($this->conversation->refresh()->status)->toBe(Conversation::STATUS_OPEN)
        ->and(StaffNotification::where('title', 'Un aviso por WhatsApp no le llegó al hotel')->exists())->toBeTrue();
});

it('no marca nada si el número no es de nadie conocido', function () {
    $mensaje = respuestaEnviada();

    fallo('5219998887777', 131026);

    expect($mensaje->refresh()->meta['undelivered'] ?? false)->toBeFalse()
        ->and(StaffNotification::count())->toBe(0);
});
