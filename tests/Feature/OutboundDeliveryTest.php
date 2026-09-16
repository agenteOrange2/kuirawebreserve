<?php

use App\Actions\Reservations\CreateReservation;
use App\Models\Central\MetaChannelLink;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\StaffNotification;
use App\Services\Channels\DirectGuestMessenger;
use App\Services\Channels\MessageChunker;
use App\Services\Meta\MetaApi;
use Illuminate\Support\Facades\Http;

/**
 * Lo que no se entrega y nadie nota.
 *
 * Caso real cabañas 2026-09-15: el bot contestó con 4,457 caracteres y la
 * Cloud API devolvió "Param text.body must be at most 4096 characters long".
 * El mensaje quedó en la bandeja como si hubiera salido; el huésped nunca
 * recibió nada y el único rastro fue una línea de log. Lo mismo pasaba con
 * las fichas que traen un teléfono imposible ("656", "+5213").
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 4]);
    Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 3000,
    ]);

    config(['meta.graph_url' => 'https://graph.test/v21.0']);
});

function textoLargo(int $parrafos): string
{
    return trim(implode("\n\n", array_map(
        fn (int $i) => "Cabaña número {$i}. ".str_repeat('Incluye alberca, fogata y asador de carbón. ', 6),
        range(1, $parrafos),
    )));
}

function enlaceMeta(string $type = 'whatsapp'): MetaChannelLink
{
    return MetaChannelLink::create([
        'tenant_id' => 'demo',
        'type' => $type,
        'external_id' => 'PHONE1',
        'access_token' => 'tok-1',
        'active' => true,
    ]);
}

it('parte un texto largo en trozos que sí caben, sin perder contenido', function () {
    $texto = textoLargo(20);
    $limite = MessageChunker::limitFor('whatsapp');
    $trozos = MessageChunker::split($texto, $limite);

    expect(mb_strlen($texto))->toBeGreaterThan(4096)
        ->and(count($trozos))->toBeGreaterThan(1);

    foreach ($trozos as $trozo) {
        expect(mb_strlen($trozo))->toBeLessThanOrEqual($limite);
    }

    // Nada se queda fuera: la primera y la última cabaña siguen ahí.
    expect(implode(' ', $trozos))->toContain('Cabaña número 1.')->toContain('Cabaña número 20.');
});

it('un mensaje corto sigue siendo uno solo', function () {
    expect(MessageChunker::split('Tu cabaña te espera.', 4096))->toBe(['Tu cabaña te espera.']);
});

it('WhatsApp recibe la respuesta larga en varios mensajes en vez de rechazarla', function () {
    Http::fake(['graph.test/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

    $enviado = app(MetaApi::class)->sendText(enlaceMeta(), '5216565280146', textoLargo(20));

    expect($enviado)->toBeTrue();

    $cuerpos = [];
    Http::assertSent(function ($request) use (&$cuerpos) {
        $cuerpos[] = $request->data()['text']['body'] ?? '';

        return true;
    });

    expect(count($cuerpos))->toBeGreaterThan(1);

    foreach ($cuerpos as $cuerpo) {
        expect(mb_strlen($cuerpo))->toBeLessThanOrEqual(4096);
    }
});

it('Messenger e Instagram usan su propio tope, más chico', function () {
    expect(MessageChunker::limitFor('instagram'))->toBeLessThan(MessageChunker::limitFor('messenger'))
        ->and(MessageChunker::limitFor('messenger'))->toBeLessThan(MessageChunker::limitFor('whatsapp'));

    Http::fake(['graph.test/*' => Http::response(['message_id' => 'mid.1'])]);

    app(MetaApi::class)->sendText(enlaceMeta('messenger'), 'PSID1', textoLargo(12));

    $partes = 0;
    Http::assertSent(function ($request) use (&$partes) {
        $partes++;
        expect(mb_strlen($request->data()['message']['text'] ?? ''))->toBeLessThanOrEqual(2000);

        return true;
    });

    expect($partes)->toBeGreaterThan(1);
});

it('un teléfono imposible no se manda a la API y el hotel se entera', function () {
    Http::fake();

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'starts_at' => now()->addDays(2)->setTime(14, 0),
        'ends_at' => now()->addDays(3)->setTime(11, 0),
        'confirmed' => true,
        'source_channel' => 'web',
        'guest_name' => 'Kevin Andrés',
        'guest_phone' => '656',
    ]);

    $entregado = app(DirectGuestMessenger::class)->send($reservation, 'Tu reserva está confirmada.');

    expect($entregado)->toBeFalse();
    Http::assertNothingSent();

    $aviso = StaffNotification::where('title', 'Aviso no entregado')->latest('id')->first();

    expect($aviso?->title)->toBe('Aviso no entregado')
        ->and($aviso?->body)->toContain('656')
        ->and($aviso?->body)->toContain('Contáctalo por otra vía');
});

it('cuando el canal rechaza la respuesta del bot, queda marcada y suena la campana', function () {
    $channel = \App\Models\Channel::firstOrCreate(
        ['property_id' => $this->property->id, 'type' => 'whatsapp', 'external_id' => null],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );

    $conversation = \App\Models\Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '5216565280146',
        'contact_name' => 'Kevin Andrés',
        'status' => \App\Models\Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);

    $reply = $conversation->messages()->create([
        'direction' => 'out',
        'sender_type' => 'bot',
        'body' => 'Para el domingo 27 tenemos la Cabaña Escondida disponible.',
        'created_at' => now(),
    ]);

    app(\App\Services\Channels\OutboundMessenger::class)->flagUndelivered($conversation, $reply);

    $aviso = StaffNotification::where('title', 'Respuesta no entregada')->latest('id')->first();

    expect($reply->refresh()->meta['undelivered'])->toBeTrue()
        // La conversación deja de parecer atendida por el bot.
        ->and($conversation->refresh()->status)->toBe(\App\Models\Conversation::STATUS_PENDING)
        ->and($aviso)->not->toBeNull()
        ->and($aviso->body)->toContain('Kevin Andrés')
        ->and($aviso->url)->toContain('conversation='.$conversation->id);
});
