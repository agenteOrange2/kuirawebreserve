<?php

use App\Actions\Reservations\CreateGroupReservation;
use App\Http\Controllers\Agent\AgentToolsController;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use Illuminate\Http\Request;

/**
 * Caso real cabañas 2026-09-14 (Abril Alejandra, GRP-2026-0149).
 *
 * El bot apartó 4 cabañas para 16 personas, dio el folio GRP-, cobró
 * $6,750 de anticipo por transferencia y recibió el comprobante por el
 * chat. Seis minutos después le dijo: "el código de reserva GRP-2026-0149
 * no aparece registrado" y "ya no hay disponibilidad para el 20 de
 * septiembre, solo queda 1 cabaña". Las dos cosas eran falsas: el folio
 * existía y las 4 cabañas que "faltaban" eran las suyas, apartadas por
 * ella. El hotel le devolvió el dinero y perdió la venta.
 *
 * Dos defectos: consultar_reserva solo buscaba en `reservations.code`, y la
 * disponibilidad contaba los apartados del propio huésped como ocupados sin
 * decirle al bot de quién eran.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Cabaña Sencilla',
        'capacity' => 4,
    ]);
    $this->rooms = Room::factory()->count(4)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 3000,
        'deposit_percent' => 50,
    ]);

    $channel = Channel::firstOrCreate(
        ['property_id' => $this->property->id, 'type' => Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );

    $this->conversation = Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '5216560000000',
        'status' => Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);
});

function grupoDeAbril(int $rooms = 4): ReservationGroup
{
    $group = app(CreateGroupReservation::class)->handle([
        'starts_at' => now()->addDays(6)->format('Y-m-d').' 14:00',
        'ends_at' => now()->addDays(7)->format('Y-m-d').' 11:00',
        'guest_name' => 'Abril Alejandra Mendoza Duran',
        'mode' => 'night',
        'confirmed' => false,
        'source_channel' => 'agent',
        'lines' => [['room_type_id' => test()->roomType->id, 'rooms' => $rooms]],
    ]);

    // Igual que el bot: la conversación queda ligada por la primera del grupo.
    test()->conversation->update([
        'reservation_id' => $group->fresh()->reservations()->orderBy('id')->first()->id,
    ]);

    return $group->fresh();
}

it('consultar_reserva encuentra el folio del grupo, no solo el de una habitación', function () {
    $group = grupoDeAbril();

    $response = app(AgentToolsController::class)->showReservation($group->displayCode());
    $data = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($data['kind'])->toBe('group')
        ->and($data['code'])->toBe($group->displayCode())
        ->and($data['rooms_count'])->toBe(4)
        ->and($data['rooms_alive'])->toBe(4)
        ->and((float) $data['total'])->toBe(12000.0)
        ->and($data['instructions'])->toContain('SIGUE VIGENTE');
});

it('un código que no existe no autoriza al bot a decir que la reserva no existe', function () {
    $response = app(AgentToolsController::class)->showReservation('GRP-2026-9999');

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getData(true)['message'])->toContain('transferir_a_humano');
});

it('la disponibilidad avisa que las habitadas ocupadas son del propio huésped', function () {
    $group = grupoDeAbril();

    // Las 4 cabañas del tipo están apartadas: por ella.
    $request = Request::create('/agent/availability-overview', 'GET', [
        'starts_at' => now()->addDays(6)->format('Y-m-d').' 14:00',
        'ends_at' => now()->addDays(7)->format('Y-m-d').' 11:00',
        'guests' => 16,
        'conversation_id' => test()->conversation->id,
    ]);

    $data = app(AgentToolsController::class)
        ->availabilityOverview($request, app(AvailabilityService::class))
        ->getData(true);

    expect($data['units_available'])->toBe(0)
        ->and($data['options'][0]['yours_already'])->toBe(4)
        ->and($data['note'])->toContain('YA TIENE 4 habitación(es) apartadas')
        ->and($data['note'])->toContain($group->displayCode())
        ->and($data['note'])->toContain('NUNCA le digas que no hay disponibilidad');
});

it('sin conversación ligada la disponibilidad no inventa habitaciones propias', function () {
    grupoDeAbril();

    $request = Request::create('/agent/availability-overview', 'GET', [
        'starts_at' => now()->addDays(6)->format('Y-m-d').' 14:00',
        'ends_at' => now()->addDays(7)->format('Y-m-d').' 11:00',
    ]);

    $data = app(AgentToolsController::class)
        ->availabilityOverview($request, app(AvailabilityService::class))
        ->getData(true);

    expect($data['options'][0]['yours_already'])->toBe(0)
        ->and($data['note'])->not->toContain('YA TIENE');
});

it('reactivar_apartado revive el grupo completo con su mismo folio', function () {
    $group = grupoDeAbril();

    // Vencen los cuatro apartados sin pago, como esa noche.
    foreach ($group->reservations as $reservation) {
        $reservation->forceFill(['hold_expires_at' => now()->subMinutes(5)])->saveQuietly();
    }
    $this->artisan('reservations:expire-holds')->assertSuccessful();

    $request = Request::create('/agent/reopen-hold', 'POST', [
        'code' => $group->displayCode(),
        'conversation_id' => test()->conversation->id,
    ]);

    $response = app(AgentToolsController::class)
        ->reopenHold($request, app(\App\Actions\Reservations\TransitionReservation::class));
    $data = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($data['code'])->toBe($group->displayCode())
        ->and($data['rooms_count'])->toBe(4)
        ->and(Reservation::query()
            ->where('reservation_group_id', $group->id)
            ->where('status', \App\Enums\ReservationStatus::Pending)
            ->count())->toBe(4);
});

it('el sistema no deja salir un "no aparece registrado" con el apartado vivo', function () {
    $group = grupoDeAbril();
    $brain = app(\App\Services\Agent\AgentBrain::class);

    // El texto exacto que recibió Abril seis minutos después de transferir.
    $texto = "Entiendo su preocupación. Revisando el sistema, el código de reserva {$group->displayCode()} no aparece registrado.\n"
        ."Además, para el domingo 20 de septiembre ya no hay disponibilidad.\n"
        .'Le ofrezco las siguientes alternativas con disponibilidad:';

    $salida = (fn () => $this->enforceLiveReservationClaims($texto, test()->conversation->refresh()))->call($brain);

    expect($salida)->not->toContain('no aparece registrado')
        ->and($salida)->toContain($group->displayCode())
        ->and($salida)->toContain('sigue registrado con 4 habitación(es)')
        ->and($salida)->toContain('está revisando tu pago');
});

it('tampoco deja decir que venció un apartado que sigue vivo', function () {
    grupoDeAbril();
    $brain = app(\App\Services\Agent\AgentBrain::class);

    $salida = (fn () => $this->enforceLiveReservationClaims(
        'Sus cabañas ya no están apartadas porque el tiempo venció.',
        test()->conversation->refresh(),
    ))->call($brain);

    expect($salida)->not->toContain('venció');
});

it('con el apartado ya vencido de verdad, el guarda no inventa que sigue vivo', function () {
    $group = grupoDeAbril();

    foreach ($group->reservations as $reservation) {
        $reservation->forceFill(['hold_expires_at' => now()->subHour()])->saveQuietly();
    }
    $this->artisan('reservations:expire-holds')->assertSuccessful();

    $brain = app(\App\Services\Agent\AgentBrain::class);
    $texto = 'Tu apartado venció y la habitación se liberó.';

    $salida = (fn () => $this->enforceLiveReservationClaims($texto, test()->conversation->refresh()))->call($brain);

    expect($salida)->toBe($texto);
});
