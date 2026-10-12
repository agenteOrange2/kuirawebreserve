<?php

use App\Http\Controllers\Tenant\InboxController;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\StaffNotification;

/**
 * La ventana de 24 h de WhatsApp en la bandeja (2026-10-08). El personal
 * contestaba traspasos de 2 a 40 horas después y el mensaje rebotaba con
 * "No se entregó" (#131047). 48 h no existe: es regla de Meta. Ahora la
 * bandeja dice cuánto queda, suena la campana 4 h antes y, cerrada, ofrece
 * retomar con una plantilla aprobada.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    $this->property = Property::factory()->create();
});

function conversacionWhatsapp(int $horasDesdeElHuesped, string $status = Conversation::STATUS_PENDING, string $tipo = 'whatsapp'): Conversation
{
    $channel = Channel::firstOrCreate(
        ['property_id' => test()->property->id, 'type' => $tipo, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );
    $conversation = Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '5216141234567',
        'contact_name' => 'Karla Arreola',
        'status' => $status,
        'bot_enabled' => false,
        'last_message_at' => now(),
    ]);
    $conversation->messages()->create([
        'direction' => 'in',
        'sender_type' => 'visitor',
        'body' => '¿Se puede llevar asador?',
        'created_at' => now()->subHours($horasDesdeElHuesped),
    ]);

    return $conversation;
}

function ventana(Conversation $conversation): ?array
{
    return app(InboxController::class)->show($conversation)->getData(true)['window'];
}

it('dice cuánto queda para contestar por WhatsApp', function () {
    $window = ventana(conversacionWhatsapp(3));

    expect($window['open'])->toBeTrue()
        ->and($window['minutes_left'])->toBeGreaterThan(20 * 60)
        ->and($window['label'])->toContain('Quedan')
        ->and($window['templates'])->toBeTrue();
});

it('cerrada la ventana, lo dice y ofrece plantilla', function () {
    $window = ventana(conversacionWhatsapp(30));

    expect($window['open'])->toBeFalse()
        ->and($window['label'])->toContain('se cerró hace')
        ->and($window['templates'])->toBeTrue();
});

it('Messenger también avisa la ventana, pero sin plantillas', function () {
    $window = ventana(conversacionWhatsapp(30, tipo: 'messenger'));

    expect($window['open'])->toBeFalse()->and($window['templates'])->toBeFalse();
});

it('los canales sin ventana no muestran nada', function () {
    expect(ventana(conversacionWhatsapp(30, tipo: Channel::TYPE_WHATSAPP_EVOLUTION)))->toBeNull();
});

it('suena la campana cuando a quien espera al personal le quedan 4 h o menos', function () {
    $porCerrar = conversacionWhatsapp(21);
    conversacionWhatsapp(10); // le sobra tiempo
    $this->artisan('conversations:warn-closing-windows')->assertSuccessful();

    $avisos = StaffNotification::query()->where('title', 'Se cierra la ventana para contestar')->get();

    expect($avisos)->toHaveCount(1)
        ->and($avisos->first()->subject_id)->toBe($porCerrar->id)
        ->and($avisos->first()->body)->toContain('Karla Arreola');

    // Una sola vez por mensaje del huésped.
    $this->artisan('conversations:warn-closing-windows')->assertSuccessful();
    expect(StaffNotification::query()->where('title', 'Se cierra la ventana para contestar')->count())->toBe(1);
});

it('no avisa si el personal ya le contestó', function () {
    $conversation = conversacionWhatsapp(21);
    $conversation->messages()->create(['direction' => 'out', 'sender_type' => 'staff', 'body' => 'Sí se puede.', 'created_at' => now()->subHour()]);

    $this->artisan('conversations:warn-closing-windows')->assertSuccessful();

    expect(StaffNotification::query()->where('title', 'Se cierra la ventana para contestar')->count())->toBe(0);
});

it('lee solo las plantillas aprobadas y manda la plantilla con el nombre del huésped', function () {
    \Illuminate\Support\Facades\Http::fake([
        '*/message_templates*' => \Illuminate\Support\Facades\Http::response(['data' => [
            ['name' => 'retomar_conversacion', 'status' => 'APPROVED', 'language' => 'es_MX', 'category' => 'UTILITY',
                'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}, te escribimos de {{2}} para dar seguimiento a tu mensaje.']]],
            ['name' => 'promo', 'status' => 'PENDING', 'language' => 'es_MX', 'components' => []],
        ]]),
        '*/messages' => \Illuminate\Support\Facades\Http::response(['messages' => [['id' => 'wamid.X']]]),
    ]);

    $link = new \App\Models\Central\MetaChannelLink(['type' => 'whatsapp', 'external_id' => '123', 'waba_id' => '999', 'access_token' => 'tok']);
    $api = app(\App\Services\Meta\MetaApi::class);

    $templates = $api->approvedTemplates($link);

    expect($templates)->toHaveCount(1)
        ->and($templates[0]['params'])->toBe(2)
        ->and($api->sendTemplate($link, '5216141234567', 'retomar_conversacion', 'es_MX', ['Karla', 'Cabañas']))->toBeTrue()
        ->and($api->lastSentIds)->toBe(['wamid.X']);

    \Illuminate\Support\Facades\Http::assertSent(fn ($request) => str_ends_with($request->url(), '/123/messages')
        && $request['to'] === '526141234567'
        && $request['type'] === 'template'
        && $request['template']['components'][0]['parameters'][0]['text'] === 'Karla');
});
