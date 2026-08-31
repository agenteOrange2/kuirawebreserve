<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\TransitionReservation;
use App\Events\RoomStatusChanged;
use App\Http\Controllers\Tenant\WaitlistPublicController;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Models\WaitlistEntry;
use App\Services\Agent\AgentBrain;
use App\Services\Channels\DirectGuestMessenger;
use App\Services\Channels\OutboundMessenger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '601',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 900,
    ]);
});

/** Tenant en memoria como contexto de tenancy (sin DB propia). */
function actAsTenant(string $plan): Tenant
{
    $tenant = new Tenant;
    $tenant->id = "hotel-{$plan}";
    $tenant->plan = $plan;

    app()->instance(\Stancl\Tenancy\Contracts\Tenant::class, $tenant);

    return $tenant;
}

function waitlistStore(array $payload): \Illuminate\Http\JsonResponse
{
    $request = Request::create('/api/booking/waitlist', 'POST', $payload);

    return app(WaitlistPublicController::class)->store($request);
}

it('la captura pública crea la entrada en espera', function () {
    $response = waitlistStore([
        'guest_name' => 'Espera Turno',
        'guest_phone' => '5511223344',
        'starts_at' => now()->addDays(5)->toDateString(),
        'ends_at' => now()->addDays(7)->toDateString(),
    ]);

    expect($response->getStatusCode())->toBe(201);

    $entry = WaitlistEntry::firstOrFail();

    expect($entry->status)->toBe(WaitlistEntry::STATUS_WAITING)
        ->and($entry->guest_name)->toBe('Espera Turno')
        ->and($entry->room_type_id)->toBeNull();
});

it('sin teléfono ni correo la captura se rechaza', function () {
    expect(fn () => waitlistStore([
        'guest_name' => 'Sin Contacto',
        'starts_at' => now()->addDays(5)->toDateString(),
        'ends_at' => now()->addDays(7)->toDateString(),
    ]))->toThrow(ValidationException::class);
});

it('el mismo contacto con el mismo rango no duplica su entrada', function () {
    $payload = [
        'guest_name' => 'Doble Clic',
        'guest_phone' => '5511223344',
        'starts_at' => now()->addDays(5)->toDateString(),
        'ends_at' => now()->addDays(7)->toDateString(),
    ];

    waitlistStore($payload);
    $again = waitlistStore($payload);

    expect($again->getStatusCode())->toBe(200)
        ->and(WaitlistEntry::query()->count())->toBe(1);
});

it('sin el módulo, el endpoint público responde 403 (middleware)', function () {
    actAsTenant('basic'); // basic no incluye lista-espera

    $request = Request::create('http://hotel.test/api/booking/waitlist', 'POST');

    expect(fn () => app(EnsureModuleEnabled::class)->handle($request, fn () => response('ok'), 'lista-espera'))
        ->toThrow(HttpException::class);
});

it('cancelar una reserva avisa a las entradas solapadas y las sella', function () {
    actAsTenant('pro'); // pro incluye lista-espera (semilla config/plans.php)

    // Solapada del mismo tipo, solapada sin tipo (cualquiera) → se avisan.
    $sameType = WaitlistEntry::create([
        'room_type_id' => $this->roomType->id,
        'starts_at' => now()->addDays(3)->toDateString(),
        'ends_at' => now()->addDays(5)->toDateString(),
        'guest_name' => 'Mismo Tipo',
        'guest_phone' => '5511111111',
        'status' => WaitlistEntry::STATUS_WAITING,
    ]);
    $anyType = WaitlistEntry::create([
        'room_type_id' => null,
        'starts_at' => now()->addDays(4)->toDateString(),
        'ends_at' => now()->addDays(6)->toDateString(),
        'guest_name' => 'Cualquier Tipo',
        'guest_email' => 'cualquiera@correo.test',
        'status' => WaitlistEntry::STATUS_WAITING,
    ]);
    // Fuera de rango y ya avisada → NO se tocan.
    $outOfRange = WaitlistEntry::create([
        'room_type_id' => null,
        'starts_at' => now()->addDays(20)->toDateString(),
        'ends_at' => now()->addDays(22)->toDateString(),
        'guest_name' => 'Otro Mes',
        'guest_phone' => '5522222222',
        'status' => WaitlistEntry::STATUS_WAITING,
    ]);
    $alreadyNotified = WaitlistEntry::create([
        'room_type_id' => $this->roomType->id,
        'starts_at' => now()->addDays(3)->toDateString(),
        'ends_at' => now()->addDays(5)->toDateString(),
        'guest_name' => 'Ya Avisado',
        'guest_phone' => '5533333333',
        'status' => WaitlistEntry::STATUS_NOTIFIED,
        'notified_at' => now()->subDay(),
    ]);

    $this->mock(DirectGuestMessenger::class, function ($mock) {
        $mock->shouldIgnoreMissing();
        $mock->shouldReceive('sendToContactDetailed')
            ->twice()
            ->withArgs(fn ($phone, $email, $subject, $body) => $subject === 'Se liberó espacio para tus fechas'
                && str_contains($body, 'se liberó espacio para tus fechas'))
            ->andReturn(['whatsapp' => true, 'email' => false]);
    });

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(3)->setTime(15, 0),
        'ends_at' => now()->addDays(6)->setTime(12, 0),
        'confirmed' => true,
        'guest_name' => 'El Que Cancela',
    ]);

    app(TransitionReservation::class)->cancel($reservation);

    expect($sameType->refresh()->status)->toBe(WaitlistEntry::STATUS_NOTIFIED)
        ->and($sameType->notified_at)->not->toBeNull()
        ->and($sameType->notified_channel)->toBe('whatsapp')
        ->and($sameType->notify_attempts)->toBe(1)
        ->and($sameType->notify_failed_at)->toBeNull()
        ->and($anyType->refresh()->status)->toBe(WaitlistEntry::STATUS_NOTIFIED)
        ->and($outOfRange->refresh()->status)->toBe(WaitlistEntry::STATUS_WAITING)
        ->and($alreadyNotified->refresh()->notified_at->isYesterday())->toBeTrue();
});

it('sin el módulo la cancelación no avisa a nadie', function () {
    actAsTenant('basic');

    WaitlistEntry::create([
        'room_type_id' => null,
        'starts_at' => now()->addDays(3)->toDateString(),
        'ends_at' => now()->addDays(5)->toDateString(),
        'guest_name' => 'Nadie Le Avisa',
        'guest_phone' => '5544444444',
        'status' => WaitlistEntry::STATUS_WAITING,
    ]);

    $this->mock(DirectGuestMessenger::class, function ($mock) {
        $mock->shouldIgnoreMissing();
        $mock->shouldNotReceive('sendToContactDetailed');
    });

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(3)->setTime(15, 0),
        'ends_at' => now()->addDays(6)->setTime(12, 0),
        'confirmed' => true,
        'guest_name' => 'Cancela Basic',
    ]);

    app(TransitionReservation::class)->cancel($reservation);

    expect(WaitlistEntry::firstOrFail()->status)->toBe(WaitlistEntry::STATUS_WAITING);
});

it('si el aviso no sale por ningún canal la entrada vuelve a espera con el motivo', function () {
    actAsTenant('pro');

    $entry = WaitlistEntry::create([
        'room_type_id' => $this->roomType->id,
        'starts_at' => now()->addDays(3)->toDateString(),
        'ends_at' => now()->addDays(5)->toDateString(),
        'guest_name' => 'Nadie Le Llega',
        'guest_phone' => '5511111111',
        'status' => WaitlistEntry::STATUS_WAITING,
    ]);

    // Sin WhatsApp conectado ni SMTP: ningún canal lo toma.
    $this->mock(DirectGuestMessenger::class, function ($mock) {
        $mock->shouldIgnoreMissing();
        $mock->shouldReceive('sendToContactDetailed')
            ->once()
            ->andReturn(['whatsapp' => false, 'email' => false]);
    });

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(3)->setTime(15, 0),
        'ends_at' => now()->addDays(6)->setTime(12, 0),
        'confirmed' => true,
        'guest_name' => 'El Que Cancela',
    ]);

    app(TransitionReservation::class)->cancel($reservation);

    $entry->refresh();

    // Lo importante: NO queda como "Avisado" cuando nadie recibió nada.
    expect($entry->status)->toBe(WaitlistEntry::STATUS_WAITING)
        ->and($entry->notified_at)->toBeNull()
        ->and($entry->notified_channel)->toBeNull()
        ->and($entry->notify_attempts)->toBe(1)
        ->and($entry->notify_failed_at)->not->toBeNull()
        ->and($entry->notify_error)->toContain('No salió por WhatsApp');
});

it('el aviso manual reintenta, sella el canal y suma intento', function () {
    actAsTenant('pro');

    $entry = WaitlistEntry::create([
        'room_type_id' => null,
        'starts_at' => now()->addDays(3)->toDateString(),
        'ends_at' => now()->addDays(5)->toDateString(),
        'guest_name' => 'Segundo Toque',
        'guest_email' => 'segundo@correo.test',
        'status' => WaitlistEntry::STATUS_WAITING,
        'notify_attempts' => 1,
        'notify_failed_at' => now()->subHour(),
        'notify_error' => 'No salió por correo',
    ]);

    $this->mock(DirectGuestMessenger::class, function ($mock) {
        $mock->shouldIgnoreMissing();
        $mock->shouldReceive('sendToContactDetailed')
            ->once()
            ->andReturn(['whatsapp' => false, 'email' => true]);
    });

    $sent = app(\App\Services\Channels\WaitlistNotifier::class)->notifyNow($entry);

    $entry->refresh();

    expect($sent)->toBeTrue()
        ->and($entry->status)->toBe(WaitlistEntry::STATUS_NOTIFIED)
        ->and($entry->notified_channel)->toBe('email')
        ->and($entry->notify_attempts)->toBe(2)
        ->and($entry->notify_failed_at)->toBeNull()
        ->and($entry->notify_error)->toBeNull();
});

it('waitlist:expire marca expiradas las de fechas pasadas sin tocar convertidas', function () {
    $vencida = WaitlistEntry::create([
        'starts_at' => now()->subDays(10)->toDateString(),
        'ends_at' => now()->subDays(8)->toDateString(),
        'guest_name' => 'Fecha Pasada',
        'guest_phone' => '5511111111',
        'status' => WaitlistEntry::STATUS_WAITING,
    ]);
    $avisadaVencida = WaitlistEntry::create([
        'starts_at' => now()->subDays(4)->toDateString(),
        'ends_at' => now()->subDays(2)->toDateString(),
        'guest_name' => 'Avisada Pasada',
        'guest_phone' => '5522222222',
        'status' => WaitlistEntry::STATUS_NOTIFIED,
    ]);
    $convertida = WaitlistEntry::create([
        'starts_at' => now()->subDays(4)->toDateString(),
        'ends_at' => now()->subDays(2)->toDateString(),
        'guest_name' => 'Ya Reservó',
        'guest_phone' => '5533333333',
        'status' => WaitlistEntry::STATUS_CONVERTED,
    ]);
    $futura = WaitlistEntry::create([
        'starts_at' => now()->addDays(4)->toDateString(),
        'ends_at' => now()->addDays(6)->toDateString(),
        'guest_name' => 'Sigue Esperando',
        'guest_phone' => '5544444444',
        'status' => WaitlistEntry::STATUS_WAITING,
    ]);

    $this->artisan('waitlist:expire')->assertSuccessful();

    expect($vencida->refresh()->status)->toBe(WaitlistEntry::STATUS_EXPIRED)
        ->and($avisadaVencida->refresh()->status)->toBe(WaitlistEntry::STATUS_EXPIRED)
        ->and($convertida->refresh()->status)->toBe(WaitlistEntry::STATUS_CONVERTED)
        ->and($futura->refresh()->status)->toBe(WaitlistEntry::STATUS_WAITING);
});

/** Canal de WhatsApp con el asistente encendido y cerebro configurado. */
function activeAgentChannel(string $mode = 'auto'): Channel
{
    test()->mock(AgentBrain::class, fn ($mock) => $mock->shouldReceive('isConfigured')->andReturnTrue());

    return Channel::create([
        'property_id' => test()->property->id,
        'type' => Channel::TYPE_WHATSAPP_EVOLUTION,
        'external_id' => '1',
        'name' => 'WhatsApp del hotel',
        'mode' => $mode,
        'active' => true,
    ]);
}

it('con el asistente activo el aviso sale por él y abre la conversación en la bandeja', function () {
    actAsTenant('pro');
    $channel = activeAgentChannel();

    $entry = WaitlistEntry::create([
        'room_type_id' => $this->roomType->id,
        'starts_at' => now()->addDays(3)->toDateString(),
        'ends_at' => now()->addDays(5)->toDateString(),
        'guest_name' => 'Habla Con El Bot',
        'guest_phone' => '6147332481',
        'status' => WaitlistEntry::STATUS_WAITING,
    ]);

    $this->mock(OutboundMessenger::class, function ($mock) {
        $mock->shouldReceive('pushToConversation')->once()->andReturnTrue();
    });

    // El WhatsApp ya salió por el asistente: el directo se llama SIN teléfono.
    $this->mock(DirectGuestMessenger::class, function ($mock) {
        $mock->shouldIgnoreMissing();
        $mock->shouldReceive('sendToContactDetailed')
            ->once()
            ->withArgs(fn ($phone, $email) => $phone === null)
            ->andReturn(['whatsapp' => false, 'email' => false]);
    });

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(3)->setTime(15, 0),
        'ends_at' => now()->addDays(6)->setTime(12, 0),
        'confirmed' => true,
        'guest_name' => 'El Que Cancela',
    ]);

    app(TransitionReservation::class)->cancel($reservation);

    $entry->refresh();
    $conversation = Conversation::firstOrFail();

    expect($entry->status)->toBe(WaitlistEntry::STATUS_NOTIFIED)
        ->and($entry->notified_channel)->toBe('agente')
        ->and($entry->channelLabel())->toBe('el asistente')
        // El renglón guarda el hilo: es la prueba clicable del aviso.
        ->and($entry->conversation_id)->toBe($conversation->id)
        // El hilo queda abierto y con el bot listo para contestar.
        ->and($conversation->channel_id)->toBe($channel->id)
        ->and($conversation->contact_phone)->toBe('526147332481')
        ->and($conversation->bot_enabled)->toBeTrue()
        ->and($conversation->lead_status)->toBe(Conversation::LEAD_QUOTING)
        // Y el asistente sabe de dónde viene la plática.
        ->and($conversation->summary)->toContain('lista de espera')
        ->and($conversation->messages()->where('direction', 'out')->count())->toBe(1);
});

it('si el asistente no logra mandar, el aviso cae al envío directo y no deja mensaje fantasma', function () {
    actAsTenant('pro');
    activeAgentChannel();

    $entry = WaitlistEntry::create([
        'room_type_id' => null,
        'starts_at' => now()->addDays(3)->toDateString(),
        'ends_at' => now()->addDays(5)->toDateString(),
        'guest_name' => 'Se Cayó Evolution',
        'guest_phone' => '6147332482',
        'status' => WaitlistEntry::STATUS_WAITING,
    ]);

    $this->mock(OutboundMessenger::class, function ($mock) {
        $mock->shouldReceive('pushToConversation')->once()->andReturnFalse();
    });

    $this->mock(DirectGuestMessenger::class, function ($mock) {
        $mock->shouldIgnoreMissing();
        $mock->shouldReceive('sendToContactDetailed')
            ->once()
            ->withArgs(fn ($phone, $email) => $phone === '6147332482')
            ->andReturn(['whatsapp' => true, 'email' => false]);
    });

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(3)->setTime(15, 0),
        'ends_at' => now()->addDays(6)->setTime(12, 0),
        'confirmed' => true,
        'guest_name' => 'El Que Cancela',
    ]);

    app(TransitionReservation::class)->cancel($reservation);

    expect($entry->refresh()->notified_channel)->toBe('whatsapp')
        // El hilo pudo nacer, pero sin mensaje: nada salió por ahí.
        ->and(Conversation::firstOrFail()->messages()->count())->toBe(0);
});

it('el asistente sigue el hilo que ya existe aunque WhatsApp guarde el 521 de México', function () {
    actAsTenant('pro');
    $channel = activeAgentChannel();

    // Hilo viejo con el formato que manda Evolution para México.
    $previo = Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '5216147332481',
        'contact_name' => 'Ya Había Escrito',
        'status' => Conversation::STATUS_RESOLVED,
        'bot_enabled' => true,
    ]);

    WaitlistEntry::create([
        'room_type_id' => null,
        'starts_at' => now()->addDays(3)->toDateString(),
        'ends_at' => now()->addDays(5)->toDateString(),
        'guest_name' => 'Ya Había Escrito',
        'guest_phone' => '614 733 2481',
        'status' => WaitlistEntry::STATUS_WAITING,
    ]);

    $this->mock(OutboundMessenger::class, function ($mock) {
        $mock->shouldReceive('pushToConversation')->once()->andReturnTrue();
    });
    $this->mock(DirectGuestMessenger::class, function ($mock) {
        $mock->shouldIgnoreMissing();
        $mock->shouldReceive('sendToContactDetailed')->andReturn(['whatsapp' => false, 'email' => false]);
    });

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(3)->setTime(15, 0),
        'ends_at' => now()->addDays(6)->setTime(12, 0),
        'confirmed' => true,
        'guest_name' => 'El Que Cancela',
    ]);

    app(TransitionReservation::class)->cancel($reservation);

    // Un solo hilo, reabierto, no uno nuevo con el número sin el 1.
    expect(Conversation::query()->count())->toBe(1)
        ->and($previo->refresh()->status)->toBe(Conversation::STATUS_OPEN)
        ->and($previo->messages()->count())->toBe(1);
});
