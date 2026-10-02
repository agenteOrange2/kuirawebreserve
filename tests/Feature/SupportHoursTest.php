<?php

use App\Http\Controllers\Agent\AgentToolsController;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\RoomType;
use App\Services\Agent\AgentBrain;
use App\Services\Channels\StaffAlerter;
use App\Services\SupportHours;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create([
        'timezone' => 'America/Ciudad_Juarez',
        'settings' => [
            'support_hours_enabled' => true,
            'support_hours_open' => '09:00',
            'support_hours_close' => '17:00',
            'support_hours_days' => [1, 2, 3, 4, 5, 6, 7],
            'phones' => [['code' => '52', 'number' => '6568508818']],
            'emails' => ['hotel@ejemplo.mx'],
        ],
    ]);
});

/** Un momento concreto en el reloj del hotel, no en UTC. */
function atLocal(string $when): CarbonImmutable
{
    return CarbonImmutable::parse($when, 'America/Ciudad_Juarez');
}

function reloadHours(): SupportHours
{
    return app(SupportHours::class);
}

it('sin horario configurado el hotel atiende siempre', function () {
    $this->property->update(['settings' => ['support_hours_enabled' => false]]);

    $hours = reloadHours();

    expect($hours->enabled())->toBeFalse()
        ->and($hours->isOpen(atLocal('2026-09-08 03:00')))->toBeTrue();
});

it('distingue dentro y fuera del turno con el reloj del hotel', function () {
    $hours = reloadHours();

    expect($hours->isOpen(atLocal('2026-09-08 10:30')))->toBeTrue()
        ->and($hours->isOpen(atLocal('2026-09-08 09:00')))->toBeTrue()
        // A las 17:00 en punto ya cerró: el turno es hasta las 17:00.
        ->and($hours->isOpen(atLocal('2026-09-08 17:00')))->toBeFalse()
        ->and($hours->isOpen(atLocal('2026-09-07 19:29')))->toBeFalse()
        ->and($hours->isOpen(atLocal('2026-09-08 03:00')))->toBeFalse();
});

it('los días no marcados son fuera de horario todo el día', function () {
    // Solo lunes a viernes: el domingo no hay quien conteste.
    $this->property->update(['settings' => array_merge($this->property->settings, [
        'support_hours_days' => [1, 2, 3, 4, 5],
    ])]);

    $hours = reloadHours();

    // 2026-09-13 es domingo; el 14, lunes.
    expect($hours->isOpen(atLocal('2026-09-13 11:00')))->toBeFalse()
        ->and($hours->isOpen(atLocal('2026-09-14 11:00')))->toBeTrue()
        ->and($hours->label())->toBe('de 9:00 a 17:00, lunes a viernes');
});

it('le dice al huésped cuándo lo retoman, en palabras', function () {
    $hours = reloadHours();

    expect($hours->nextOpeningLabel(atLocal('2026-09-08 22:00')))->toBe('mañana a partir de las 9:00')
        ->and($hours->nextOpeningLabel(atLocal('2026-09-08 06:00')))->toBe('hoy a partir de las 9:00')
        ->and($hours->label())->toBe('de 9:00 a 17:00, todos los días');

    expect($hours->afterHoursNotice(atLocal('2026-09-08 22:00')))
        ->toContain('horario de atención es de 9:00 a 17:00')
        ->toContain('mañana a partir de las 9:00');
});

it('el hotel que habla de usted no dice que alguien lo contacta: el asistente sigue atendiendo', function () {
    // Hotel México 2026-10-01, 18:04: "Tomo tu solicitud y alguien del hotel
    // te contacta mañana" a quien quería reservar, de tú.
    $this->property->update(['settings' => [...$this->property->settings, 'formal_address' => true]]);

    $notice = reloadHours()->afterHoursNotice(atLocal('2026-09-08 22:00'));

    expect($notice)->toContain('yo le atiendo: puedo cotizarle y apartarle')
        ->toContain('le responden mañana a partir de las 9:00')
        ->not->toContain('te contacta')
        ->not->toContain('Tomo tu solicitud');
});

it('el prompt del asistente cambia cuando ya no hay quien atienda', function () {
    // Dentro del turno no estorba con avisos.
    CarbonImmutable::setTestNow(atLocal('2026-09-08 10:00'));
    expect(app(AgentBrain::class)->supportHoursBlock())
        ->toContain('ahora mismo SÍ hay quien conteste');

    CarbonImmutable::setTestNow(atLocal('2026-09-08 22:00'));
    expect(app(AgentBrain::class)->supportHoursBlock())
        ->toContain('AHORA MISMO ESTÁ FUERA DE HORARIO')
        ->toContain('mañana a partir de las 9:00');

    // Sin horario configurado el prompt ni se entera.
    $this->property->update(['settings' => ['support_hours_enabled' => false]]);
    expect(app(AgentBrain::class)->supportHoursBlock())->toBe('');

    CarbonImmutable::setTestNow();
});

it('el asistente ya sabe qué hay en las cabañas: amenidades y servicios comunes', function () {
    // El bot transfería "¿tienen alberca?" a recepción porque el catálogo
    // tenía la amenidad pero el prompt no la recibía.
    RoomType::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Cabaña Escondida',
        'amenities' => ['Minisplit', 'Asador de carbón', 'Acceso a alberca'],
    ]);
    RoomType::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Cabaña Prisma',
        'amenities' => ['Terraza privada', 'Acceso a alberca'],
    ]);

    $payload = json_decode(app(AgentToolsController::class)->policies()->getContent(), true);
    $escondida = collect($payload['room_types'])->firstWhere('name', 'Cabaña Escondida');

    expect($escondida['amenities'])->toContain('Acceso a alberca')
        // Lo que comparten TODOS los tipos es servicio del complejo: así
        // contesta "¿tienen alberca?" sin que le nombren una cabaña.
        ->and($payload['hotel']['services'])->toContain('Acceso a alberca')
        ->and($payload['hotel']['services'])->not->toContain('Terraza privada')
        ->and($payload['hotel']['support_hours'])->toBe('de 9:00 a 17:00, todos los días');
});

it('el aviso al hotel sale una vez al día por conversación', function () {
    $channel = Channel::firstOrCreate(
        ['property_id' => $this->property->id, 'type' => Channel::TYPE_WEBCHAT, 'external_id' => null],
        ['name' => 'Messenger', 'mode' => 'auto', 'active' => true],
    );

    $conversation = Conversation::create([
        'channel_id' => $channel->id,
        'contact_name' => 'Ana',
        'contact_phone' => 'PSID-1',
        'status' => Conversation::STATUS_OPEN,
        'bot_enabled' => true,
        'last_message_at' => now(),
    ]);
    $conversation->messages()->create([
        'direction' => 'in',
        'sender_type' => 'visitor',
        'body' => '¿Tienen para el viernes?',
        'created_at' => now(),
    ]);

    $alerter = app(StaffAlerter::class);

    $first = $alerter->alert($conversation, StaffAlerter::KIND_AFTER_HOURS);
    $second = $alerter->alert($conversation, StaffAlerter::KIND_AFTER_HOURS);

    // El primero deja rastro en la campana; el segundo ni lo intenta.
    expect($first['bell'])->toBeTrue()
        ->and($second['bell'])->toBeFalse()
        ->and($conversation->refresh()->followupSent('alert:after_hours_quote:'.now()->toDateString()))->toBeTrue();

    // Y el traspaso es otro tipo de aviso: ese sí puede salir el mismo día.
    expect($alerter->alert($conversation, StaffAlerter::KIND_HANDOFF)['bell'])->toBeTrue();
});

it('el "¿sigues por ahí?" no persigue a nadie de madrugada', function () {
    CarbonImmutable::setTestNow(atLocal('2026-09-08 03:00'));

    $channel = Channel::firstOrCreate(
        ['property_id' => $this->property->id, 'type' => Channel::TYPE_WEBCHAT, 'external_id' => null],
        ['name' => 'Messenger', 'mode' => 'auto', 'active' => true],
    );
    $conversation = Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => 'PSID-2',
        'status' => Conversation::STATUS_OPEN,
        'bot_enabled' => true,
        'lead_status' => Conversation::LEAD_QUOTING,
        'last_message_at' => now()->subMinutes(40),
    ]);
    $conversation->messages()->create(['direction' => 'in', 'sender_type' => 'visitor', 'body' => '¿precio?', 'created_at' => now()->subMinutes(45)]);
    $conversation->messages()->create(['direction' => 'out', 'sender_type' => 'bot', 'body' => 'Son $3,000 la noche.', 'created_at' => now()->subMinutes(40)]);

    $antes = $conversation->messages()->count();
    $this->artisan('conversations:follow-up');

    expect($conversation->messages()->count())->toBe($antes);

    CarbonImmutable::setTestNow();
});
