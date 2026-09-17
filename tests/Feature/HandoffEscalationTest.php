<?php

use App\Console\Commands\EscalateHandoffs;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\StaffNotification;

// Esperas reales de cabañas del 13 al 15 de septiembre tras un traspaso del
// asistente: 16 min, 41 min, 1 h 12, 2 h 47, 5 h, 9.6 h y 22 h (un evento de
// 60 personas). El traspaso se marcaba en la bandeja y ahí moría.

beforeEach(function () {
    // Las pruebas corren a cualquier hora; el comando calla de noche.
    test()->travelTo(\Carbon\CarbonImmutable::now()->setTime(11, 0));

    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create(['name' => 'Cabañas Real de la Sierra']);

    // Horario abierto: el aviso no suena cuando no hay nadie.
    $this->property->update(['settings' => array_merge($this->property->settings ?? [], [
        'support_hours_enabled' => false,
    ])]);

    $this->channel = Channel::firstOrCreate(
        ['property_id' => $this->property->id, 'type' => Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );
});

function hiloEsperando(int $minutos, string $quien = 'Gabriela'): Conversation
{
    $conversation = Conversation::create([
        'channel_id' => test()->channel->id,
        'contact_phone' => '5216560000001',
        'contact_name' => $quien,
        'status' => Conversation::STATUS_PENDING,
        'bot_enabled' => false,
        'last_message_at' => now(),
    ]);

    $conversation->messages()->create([
        'direction' => 'in',
        'sender_type' => 'visitor',
        'body' => '¿Me pueden dar una cita para liquidar?',
        'created_at' => now()->subMinutes($minutos),
    ]);

    return $conversation;
}

function avisosDeEspera(): \Illuminate\Support\Collection
{
    return StaffNotification::query()->where('url', '/bandeja?esperando=1')->orderBy('id')->get();
}

it('a los 15 minutos suena la campana y a la hora vuelve a sonar', function () {
    $conversation = hiloEsperando(20);

    test()->artisan('conversations:escalate-handoffs')->assertSuccessful();

    expect(avisosDeEspera())->toHaveCount(1)
        ->and(avisosDeEspera()->first()->body)->toContain('Gabriela');

    // Corre otra vez: no repite el mismo escalón.
    test()->artisan('conversations:escalate-handoffs');
    expect(avisosDeEspera())->toHaveCount(1);

    // Pasa la hora: segundo escalón.
    $conversation->messages()->first()->forceFill(['created_at' => now()->subMinutes(75)])->save();
    test()->artisan('conversations:escalate-handoffs');

    // El aviso no se duplica: el mismo se actualiza y la campana vuelve a
    // sonar (StaffNotifier), que es justo lo que queremos a la hora.
    expect(avisosDeEspera())->toHaveCount(1)
        // A la hora el aviso ya no es solo la campana: sale por WhatsApp y
        // correo al hotel (StaffAlerter), con su propio tipo para que el
        // "un aviso por día" del traspaso no se lo trague.
        ->and(avisosDeEspera()->first()->title)->toContain('esperando')
        ->and(avisosDeEspera()->first()->body)->toContain('1 h 15 min')
        ->and($conversation->fresh()->followupSent('alert:handoff_waiting:'.now()->toDateString()))->toBeTrue();
});

it('no avisa de una espera corta', function () {
    hiloEsperando(5);

    test()->artisan('conversations:escalate-handoffs')->assertSuccessful();

    expect(avisosDeEspera())->toHaveCount(0);
});

it('el reloj no se reinicia porque el huésped insista', function () {
    $conversation = hiloEsperando(40);

    // Vuelve a escribir ahorita: sigue esperando desde hace 40 minutos.
    $conversation->messages()->create([
        'direction' => 'in',
        'sender_type' => 'visitor',
        'body' => '¿Hola?',
        'created_at' => now(),
    ]);

    expect((int) $conversation->waitingSince()->diffInMinutes(now()))->toBe(40);
});

it('una vez que el personal contesta, el reloj arranca de cero', function () {
    $conversation = hiloEsperando(40);

    $conversation->messages()->create([
        'direction' => 'out',
        'sender_type' => 'staff',
        'body' => 'Con gusto, ¿le parece mañana a las 10?',
        'created_at' => now()->subMinutes(30),
    ]);

    $conversation->messages()->create([
        'direction' => 'in',
        'sender_type' => 'visitor',
        'body' => 'Sí, perfecto',
        'created_at' => now()->subMinutes(10),
    ]);

    expect((int) $conversation->waitingSince()->diffInMinutes(now()))->toBe(10);

    // Y los escalones se miden sobre esos 10 minutos: todavía no se avisa.
    test()->artisan('conversations:escalate-handoffs');
    expect(avisosDeEspera())->toHaveCount(0);
});

it('los escalones son 15 y 60 minutos', function () {
    expect(EscalateHandoffs::STEPS)->toBe([15, 60]);
});

it('no escala el rezago viejo ni de madrugada', function () {
    // Traspaso de anteayer: sigue en la bandeja, pero ya no hace vibrar el
    // teléfono del hotel (2026-09-17: la primera corrida escaló 10 hilos
    // viejos a las 11:42 de la noche).
    hiloEsperando(60 * 40, 'Evento de 60 personas');

    test()->artisan('conversations:escalate-handoffs')->assertSuccessful();

    expect(avisosDeEspera())->toHaveCount(0);

    // Y de noche no se avisa aunque la espera sea reciente.
    test()->travelTo(\Carbon\CarbonImmutable::now()->setTime(23, 30));
    hiloEsperando(30, 'Gabriela');

    test()->artisan('conversations:escalate-handoffs')->assertSuccessful();

    expect(avisosDeEspera())->toHaveCount(0);
});

it('no manda más de tres avisos por corrida', function () {
    collect(range(1, 5))->each(fn (int $i) => hiloEsperando(20, 'Huésped '.$i));

    test()->artisan('conversations:escalate-handoffs')->assertSuccessful();

    expect(avisosDeEspera())->toHaveCount(EscalateHandoffs::MAX_ALERTS);
});
