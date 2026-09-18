<?php

use App\Actions\Reservations\CreateReservation;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Agent\AgentBrain;

/*
 * La bandeja no se cerraba nunca: 985 conversaciones nuevas en 30 días
 * contra 37 marcadas como resueltas por el personal (cabañas, sep-2026).
 * Quien llegó, preguntó y se fue se cierra solo a los 2 días; quien llegó a
 * una COTIZACIÓN REAL se queda, aunque lleve semanas callado.
 */

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

function conversacion(array $attrs = []): Conversation
{
    return Conversation::create(array_merge([
        'channel_id' => test()->channel->id,
        'contact_phone' => '52165'.random_int(10000000, 99999999),
        'status' => Conversation::STATUS_OPEN,
        'bot_enabled' => true,
        'last_message_at' => now()->subDays(5),
    ], $attrs));
}

function cerrarViejas(array $options = []): void
{
    test()->artisan('conversations:close-stale', $options);
}

it('cierra a quien preguntó y se fue', function () {
    $conversation = conversacion();

    cerrarViejas();

    expect($conversation->fresh()->status)->toBe(Conversation::STATUS_RESOLVED)
        // Queda el rastro de que lo cerró el sistema, no una persona.
        ->and($conversation->fresh()->followupSent('auto_closed'))->toBeTrue();
});

it('no cierra a quien llegó a una cotización real, aunque lleve semanas callado', function () {
    $conversation = conversacion(['last_message_at' => now()->subDays(40)]);
    $conversation->markFollowup(AgentBrain::REAL_QUOTE);

    cerrarViejas();

    expect($conversation->fresh()->status)->toBe(Conversation::STATUS_OPEN);
});

it('respeta lo que sigue vivo: apartado, personal y espera de un humano', function () {
    $conHold = conversacion(['lead_status' => Conversation::LEAD_HOLD]);
    $ganada = conversacion(['lead_status' => Conversation::LEAD_WON]);
    $delPersonal = conversacion(['bot_enabled' => false]);
    $asignada = conversacion(['assigned_to' => User::factory()->create()->id]);
    $esperandoHumano = conversacion(['status' => Conversation::STATUS_PENDING]);
    $archivada = conversacion(['archived_at' => now()->subDay()]);

    cerrarViejas();

    expect($conHold->fresh()->status)->toBe(Conversation::STATUS_OPEN)
        ->and($ganada->fresh()->status)->toBe(Conversation::STATUS_OPEN)
        ->and($delPersonal->fresh()->status)->toBe(Conversation::STATUS_OPEN)
        ->and($asignada->fresh()->status)->toBe(Conversation::STATUS_OPEN)
        ->and($esperandoHumano->fresh()->status)->toBe(Conversation::STATUS_PENDING)
        ->and($archivada->fresh()->status)->toBe(Conversation::STATUS_OPEN);
});

it('no cierra si hay una reserva viva colgando, aunque el embudo diga otra cosa', function () {
    $roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $roomType->id]);
    $plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $roomType->id,
        'price' => 2500,
    ]);

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $plan->id,
        'room_id' => $room->id,
        'guest_name' => 'Karla Villalobos',
        'starts_at' => now()->addDays(4)->setTime(14, 0),
        'ends_at' => now()->addDays(5)->setTime(11, 0),
        'confirmed' => true,
    ]);

    $conversation = conversacion(['reservation_id' => $reservation->id]);

    cerrarViejas();

    expect($conversation->fresh()->status)->toBe(Conversation::STATUS_OPEN);
});

it('el silencio se cuenta con los días que pide el hotel', function () {
    $ayer = conversacion(['last_message_at' => now()->subDay()]);
    $anteayer = conversacion(['last_message_at' => now()->subDays(3)]);

    cerrarViejas();

    // Con el default de 2 días, la de ayer sigue viva.
    expect($ayer->fresh()->status)->toBe(Conversation::STATUS_OPEN)
        ->and($anteayer->fresh()->status)->toBe(Conversation::STATUS_RESOLVED);

    $this->property->update(['settings' => array_merge($this->property->settings ?? [], [
        'inbox_auto_close_days' => 0,
    ])]);
    $otra = conversacion(['last_message_at' => now()->subDays(10)]);

    cerrarViejas();

    // En 0 el cierre automático queda apagado para ese hotel.
    expect($otra->fresh()->status)->toBe(Conversation::STATUS_OPEN);
});

it('en seco cuenta pero no cierra', function () {
    $conversation = conversacion();

    cerrarViejas(['--dry-run' => true]);

    expect($conversation->fresh()->status)->toBe(Conversation::STATUS_OPEN);
});
