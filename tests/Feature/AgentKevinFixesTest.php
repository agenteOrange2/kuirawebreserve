<?php

use App\Actions\Reservations\CreateGroupReservation;
use App\Actions\Reservations\CreateReservation;
use App\Http\Controllers\Agent\AgentToolsController;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Agent\AgentBrain;
use App\Services\AvailabilityService;
use Illuminate\Http\Request;

/**
 * Caso real cabañas 2026-09-14 (Kevin, conv. 744/755, GRP-2026-0152).
 *
 * En una hora y media el bot: ofreció Luxury, Prisma y Sencillas para un
 * sábado con las ocho cabañas confirmadas; propuso 3 cabañas ($9,000) para
 * 10 personas que caben en 2 con persona extra; cotizó $6,250 por 9 personas
 * y guardó el grupo en $6,000 con "1 persona" por cabaña; mandó dos
 * respuestas contradictorias a la misma ráfaga; y le dio el folio de una sola
 * habitación en vez del folio del grupo.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create(['name' => 'Cabañas Real de la Sierra']);

    $this->tipos = collect(['Cabaña Luxury' => 3500, 'Cabaña Sencilla 1' => 3000, 'Cabaña Sencilla 2' => 3000])
        ->map(function (int $precio, string $nombre) {
            $type = RoomType::factory()->create([
                'property_id' => $this->property->id,
                'name' => $nombre,
                'capacity' => 4,
            ]);

            Room::factory()->create([
                'property_id' => $this->property->id,
                'room_type_id' => $type->id,
                'included_occupancy' => 4,
                'max_occupancy' => 5,
                'extra_guest_fee' => 250,
            ]);

            $plan = RatePlan::factory()->create([
                'property_id' => $this->property->id,
                'room_type_id' => $type->id,
                'price' => $precio,
                'deposit_percent' => 50,
            ]);

            return ['type' => $type, 'plan' => $plan];
        });
});

/** La fecha de la que se habla, siempre futura: "26 de septiembre". */
function diaDicho(int $enDias = 10): array
{
    $date = now()->addDays($enDias);

    return [$date, $date->day.' de '.$date->locale('es')->isoFormat('MMMM')];
}

function ocupar(string $tipo, \Carbon\CarbonInterface $date): Reservation
{
    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->tipos[$tipo]['plan']->id,
        'starts_at' => $date->copy()->setTime(14, 0),
        'ends_at' => $date->copy()->addDay()->setTime(11, 0),
        'confirmed' => true,
        'source_channel' => 'front_desk',
        'guest_name' => 'Ya reservada',
    ]);
}

function sanear(string $texto, ?Conversation $conversation = null): string
{
    return (new ReflectionMethod(AgentBrain::class, 'enforceAvailabilityClaims'))
        ->invoke(app(AgentBrain::class), $texto, $conversation);
}

// ------------------------------------------- 1. disponibilidad de memoria

it('borra la cabaña que ofreció y no está libre, y dice qué sí queda', function () {
    [$date, $dicho] = diaDicho();
    ocupar('Cabaña Luxury', $date);

    $salida = sanear("¿Podría ser otra cabaña para el {$dicho}? Tenemos la Luxury disponible para ese día.");

    expect($salida)->toContain('ya no está disponible')
        ->and($salida)->toContain('Cabaña Luxury')
        ->and($salida)->not->toContain('Tenemos la Luxury disponible')
        // Y en lugar de dejarlo sin nada, le dice lo que sí hay.
        ->and($salida)->toContain('Cabaña Sencilla 1');
});

it('con todo ocupado dice que no queda ninguna, sin inventar alternativas', function () {
    [$date, $dicho] = diaDicho();
    $this->tipos->keys()->each(fn (string $tipo) => ocupar($tipo, $date));

    $salida = sanear("Tenemos la Luxury, Prisma o las Sencillas disponibles para el {$dicho}.");

    expect($salida)->toContain('no queda ninguna habitación libre');
});

it('también revisa las listas: el nombre va en su renglón y la fecha en el encabezado', function () {
    [$date, $dicho] = diaDicho();
    ocupar('Cabaña Sencilla 1', $date);

    $salida = sanear("Para el {$dicho} tenemos estas cabañas disponibles:\n- Cabaña Sencilla 1: \$3,000\n- Cabaña Luxury: \$3,500");

    expect($salida)->toContain('Cabaña Sencilla 1 ya no está disponible')
        ->and($salida)->not->toContain('Cabaña Sencilla 1: $3,000');
});

it('lo que sí está libre se queda tal cual', function () {
    [, $dicho] = diaDicho();

    $texto = "La Cabaña Luxury está disponible para el {$dicho}.";

    expect(sanear($texto))->toBe($texto);
});

it('no le niega al huésped la cabaña que él mismo tiene apartada', function () {
    [$date, $dicho] = diaDicho();
    $reserva = ocupar('Cabaña Luxury', $date);

    $channel = Channel::firstOrCreate(
        ['property_id' => $this->property->id, 'type' => Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );
    $conversation = Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '5216560000000',
        'reservation_id' => $reserva->id,
        'status' => Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);

    $texto = "Tu Cabaña Luxury sigue disponible para ti el {$dicho}.";

    expect(sanear($texto, $conversation))->toBe($texto);
});

// ------------------------------------- 2. el grupo cobra la persona extra

it('el apartado de grupo reparte las personas y cobra la persona extra', function () {
    $date = now()->addDays(8);

    $payload = json_decode(app(AgentToolsController::class)->storeGroupHold(
        Request::create('/agent/group-holds', 'POST', [
            'starts_at' => $date->copy()->setTime(14, 0)->toIso8601String(),
            'ends_at' => $date->copy()->addDay()->setTime(11, 0)->toIso8601String(),
            'guest_name' => 'Kevin Andrés Rios Longoria',
            'guests' => 9,
            'lines' => [
                ['room_type_id' => $this->tipos['Cabaña Sencilla 1']['type']->id, 'rooms' => 1],
                ['room_type_id' => $this->tipos['Cabaña Sencilla 2']['type']->id, 'rooms' => 1],
            ],
        ]),
        app(CreateGroupReservation::class),
    )->getContent(), true);

    // 2 cabañas a $3,000 + 1 persona extra de $250 = $6,250 (antes: $6,000
    // con "1 persona" en cada cabaña).
    expect($payload['rooms_count'])->toBe(2)
        ->and($payload['guests'])->toBe(9)
        ->and($payload['total'])->toEqual(6250)
        ->and(collect($payload['rooms'])->pluck('people')->sort()->values()->all())->toBe([4, 5])
        ->and(Reservation::sum('num_people'))->toEqual(9);
});

it('sin personas, cada habitación lleva las que incluye', function () {
    $date = now()->addDays(8);

    $payload = json_decode(app(AgentToolsController::class)->storeGroupHold(
        Request::create('/agent/group-holds', 'POST', [
            'starts_at' => $date->copy()->setTime(14, 0)->toIso8601String(),
            'guest_name' => 'Sin dato',
            'lines' => [
                ['room_type_id' => $this->tipos['Cabaña Sencilla 1']['type']->id, 'rooms' => 1],
                ['room_type_id' => $this->tipos['Cabaña Sencilla 2']['type']->id, 'rooms' => 1],
            ],
        ]),
        app(CreateGroupReservation::class),
    )->getContent(), true);

    expect($payload['guests'])->toBe(8)
        ->and($payload['total'])->toEqual(6000);
});

it('dice cuántas caben cuando el grupo no cabe, en vez de apartar de más', function () {
    $date = now()->addDays(8);

    $response = app(AgentToolsController::class)->storeGroupHold(
        Request::create('/agent/group-holds', 'POST', [
            'starts_at' => $date->copy()->setTime(14, 0)->toIso8601String(),
            'guest_name' => 'Grupo enorme',
            'guests' => 15,
            'lines' => [
                ['room_type_id' => $this->tipos['Cabaña Sencilla 1']['type']->id, 'rooms' => 1],
                ['room_type_id' => $this->tipos['Cabaña Sencilla 2']['type']->id, 'rooms' => 1],
            ],
        ]),
        app(CreateGroupReservation::class),
    );

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['message'])->toContain('caben hasta 10')
        ->and(Reservation::count())->toBe(0);
});

// ------------------------------- 3. la combinación con menos habitaciones

it('ofrece la combinación con persona extra cuando sale más barata', function () {
    $date = now()->addDays(9);

    $data = app(AgentToolsController::class)->availabilityOverview(
        Request::create('/agent/availability-overview', 'GET', [
            'starts_at' => $date->format('Y-m-d').' 14:00',
            'ends_at' => $date->copy()->addDay()->format('Y-m-d').' 11:00',
            'guests' => 10,
        ]),
        app(AvailabilityService::class),
    )->getData(true);

    // 3 cabañas de 4 incluidas ($9,500) contra 2 de máximo 5 con dos
    // personas extra ($6,500).
    expect($data['suggested_combination'])->toHaveCount(3)
        ->and($data['combination_with_extras_rooms'])->toBe(2)
        ->and($data['combination_with_extras_total'])->toEqual(6500)
        ->and($data['note'])->toContain('Cabe en menos habitaciones con persona extra')
        ->and(collect($data['combination_with_extras'])->sum('extra_guests'))->toBe(2);
});

it('no la ofrece cuando no mejora nada', function () {
    $date = now()->addDays(9);

    $data = app(AgentToolsController::class)->availabilityOverview(
        Request::create('/agent/availability-overview', 'GET', [
            'starts_at' => $date->format('Y-m-d').' 14:00',
            'ends_at' => $date->copy()->addDay()->format('Y-m-d').' 11:00',
            'guests' => 4,
        ]),
        app(AvailabilityService::class),
    )->getData(true);

    expect($data['combination_with_extras'])->toBe([])
        ->and($data['note'])->not->toContain('persona extra');
});

// -------------------------------------------- 4. las respuestas dobles

it('un mensaje de texto nuevo descarta la respuesta a medio hacer; una foto no', function () {
    $channel = Channel::firstOrCreate(
        ['property_id' => $this->property->id, 'type' => Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );
    $conversation = Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '5216560000000',
        'status' => Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);

    $primero = $conversation->messages()->create([
        'direction' => 'in', 'sender_type' => 'visitor', 'body' => '27 de septiembre', 'created_at' => now(),
    ]);

    $newer = fn () => (new ReflectionMethod(AgentBrain::class, 'newerTextInbound'))
        ->invoke(app(AgentBrain::class), $conversation, $primero);

    expect($newer())->toBeFalse();

    // Una foto sola no despierta al bot: si por ella se tirara la respuesta,
    // el huésped se quedaría sin contestación.
    $conversation->messages()->create([
        'direction' => 'in', 'sender_type' => 'visitor', 'body' => '[Imagen]', 'created_at' => now(),
    ]);

    expect($newer())->toBeFalse();

    $conversation->messages()->create([
        'direction' => 'in', 'sender_type' => 'visitor', 'body' => 'Dos sencillas', 'created_at' => now(),
    ]);

    expect($newer())->toBeTrue();
});

// ------------------------------------------------- 5. el folio del grupo

it('el aviso del apartado usa el folio del grupo y habla en plural', function () {
    $date = now()->addDays(8);

    $group = app(CreateGroupReservation::class)->handle([
        'mode' => 'night',
        'starts_at' => $date->copy()->setTime(14, 0),
        'ends_at' => $date->copy()->addDay()->setTime(11, 0),
        'guest_name' => 'Kevin Andrés Rios Longoria',
        'lines' => [
            ['room_type_id' => $this->tipos['Cabaña Sencilla 1']['type']->id, 'rooms' => 1, 'adults' => 5],
            ['room_type_id' => $this->tipos['Cabaña Sencilla 2']['type']->id, 'rooms' => 1, 'adults' => 4],
        ],
    ]);

    $reservation = $group->reservations()->first();
    $reservation->update(['hold_expires_at' => now()->addHours(2)]);

    $notice = app(\App\Services\ReservationPolicy::class)->holdDeadlineNotice($reservation->refresh());

    expect($notice)->toContain($group->displayCode())
        ->and($notice)->toContain('Tu grupo')
        ->and($notice)->toContain('las habitaciones se liberan')
        ->and($notice)->not->toContain('RES-');
});

// --------------------------- 6. una sola cabaña también cobra los extras

it('el apartado de UNA cabaña cobra la persona extra y respeta el cupo', function () {
    $date = now()->addDays(7);

    $apartar = fn (?int $personas) => app(AgentToolsController::class)->storeHold(
        Request::create('/agent/holds', 'POST', array_filter([
            'rate_plan_id' => $this->tipos['Cabaña Sencilla 1']['plan']->id,
            'starts_at' => $date->copy()->setTime(14, 0)->toIso8601String(),
            'ends_at' => $date->copy()->addDay()->setTime(11, 0)->toIso8601String(),
            'guest_name' => 'Kevin Andrés',
            'adults' => $personas,
        ])),
        app(CreateReservation::class),
    );

    $con5 = json_decode($apartar(5)->getContent(), true);

    // $3,000 de la cabaña + $250 de la quinta persona.
    expect($con5['people'])->toBe(5)
        ->and($con5['total'])->toEqual(3250);

    // Y nadie aparta para 9 en una cabaña de 5: se dice, no se aparta.
    $con9 = $apartar(9);

    expect($con9->getStatusCode())->toBe(422)
        ->and(Reservation::count())->toBe(1);
});
