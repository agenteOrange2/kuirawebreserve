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

// ------------------------- Conversación del 17-sep-2026 (sábado 19 lleno)
//
// El huésped pidió el sábado; con las ocho cabañas ocupadas el guardián
// reescribió la respuesta y salió esto, tal cual, al WhatsApp del huésped:
//
//   "Para el sábado 19 de septiembre, Cabaña Real ya no está disponible. Ese
//    día no queda ninguna habitación libre: dile la verdad y ofrécele otra
//    fecha. [...] ¿Qué tipo de cabaña le interesa? Tenemos: hasta 6 personas,
//    $4,500 - Cabaña Luxury: hasta 4 personas, $3,500 - Cabaña Prisma: ..."
//
// Tres fallas en un mensaje: una instrucción interna filtrada, la
// contradicción (no hay nada / aquí está la lista) y todo amontonado.

function listaDelDia(string $dicho): string
{
    return "Para el {$dicho} le comparto las opciones. ¿Qué tipo de cabaña le interesa? Tenemos:\n"
        ."- Cabaña Luxury: hasta 4 personas, \$3,500\n"
        ."- Cabaña Sencilla 1: hasta 4 personas, \$3,000\n"
        .'- Cabaña Sencilla 2: hasta 4 personas, $3,000';
}

it('nunca le manda al huésped una instrucción para el modelo', function () {
    [$date, $dicho] = diaDicho();
    $this->tipos->keys()->each(fn (string $tipo) => ocupar($tipo, $date));

    $salida = sanear(listaDelDia($dicho));

    expect($salida)->not->toContain('dile la verdad')
        ->and($salida)->not->toContain('ofrécele')
        ->and($salida)->not->toContain('no inventes')
        ->and($salida)->toContain('no queda ninguna habitación libre');
});

it('con el día lleno no deja ni una cabaña ofrecida abajo', function () {
    [$date, $dicho] = diaDicho();
    $this->tipos->keys()->each(fn (string $tipo) => ocupar($tipo, $date));

    $salida = sanear(listaDelDia($dicho));

    // La contradicción del caso real: decía "no queda ninguna" y enseguida
    // listaba las tres.
    expect($salida)->not->toContain('Cabaña Luxury')
        ->and($salida)->not->toContain('Cabaña Sencilla 1')
        ->and($salida)->not->toContain('Cabaña Sencilla 2')
        // Y sin el encabezado colgando de la nada.
        ->and($salida)->not->toContain('Tenemos:');
});

it('no deja pedazos huérfanos del renglón que borró', function () {
    [$date, $dicho] = diaDicho();
    ocupar('Cabaña Luxury', $date);

    $salida = sanear(listaDelDia($dicho));

    // Antes borraba "- Cabaña Luxury:" y dejaba suelto "hasta 4 personas,
    // $3,500", que el huésped leía como si fuera de otra cabaña. El nombre
    // sigue apareciendo una vez, en la frase que dice que ya no está.
    expect($salida)->not->toContain('- Cabaña Luxury')
        ->and($salida)->not->toContain('hasta 4 personas, $3,500')
        ->and($salida)->toContain('Cabaña Luxury ya no está disponible')
        // Las que sí quedan libres conservan su renglón completo.
        ->and($salida)->toContain('- Cabaña Sencilla 1: hasta 4 personas, $3,000')
        ->and($salida)->toContain('- Cabaña Sencilla 2: hasta 4 personas, $3,000');
});

it('respeta los renglones: no amontona la lista en un párrafo', function () {
    [$date, $dicho] = diaDicho();
    ocupar('Cabaña Luxury', $date);

    $salida = sanear(listaDelDia($dicho));

    expect($salida)->toContain("\n- Cabaña Sencilla 1")
        ->and($salida)->toContain("\n- Cabaña Sencilla 2")
        // El pegoste del caso real: los renglones unidos con " - ".
        ->and($salida)->not->toContain('$3,000 - Cabaña Sencilla 2');
});

it('en prosa borra solo la frase de la cabaña ocupada, no el párrafo entero', function () {
    [$date, $dicho] = diaDicho();
    ocupar('Cabaña Luxury', $date);

    $salida = sanear("Para el {$dicho} tenemos la Cabaña Luxury disponible. La alberca abre de 9:00 AM a 10:30 PM.");

    expect($salida)->not->toContain('Cabaña Luxury disponible')
        ->and($salida)->toContain('La alberca abre de 9:00 AM a 10:30 PM.');
});

// ------------------- Traspaso prematuro (cabañas, conv. 992, 17-sep-2026)
//
// Tras dos fechas llenas, el huésped escribió "para el 26 de septiembre?" y
// el bot lo transfirió con una persona. Un minuto después, cuando volvió a
// preguntar, contestó él solo la disponibilidad correcta: nunca hizo falta
// molestar al hotel.

function esPrematuro(string $dijoElHuesped, string $motivo = ''): bool
{
    $channel = Channel::firstOrCreate(
        ['property_id' => test()->property->id, 'type' => Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );
    $conversation = Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '52165'.random_int(10000000, 99999999),
        'status' => Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);
    $conversation->messages()->create([
        'direction' => 'in',
        'sender_type' => 'guest',
        'body' => $dijoElHuesped,
        'created_at' => now(),
    ]);

    return (new ReflectionMethod(AgentBrain::class, 'handoffIsPremature'))
        ->invoke(app(AgentBrain::class), $conversation, $motivo);
}

it('preguntar por una fecha no justifica transferir', function (string $dijo) {
    expect(esPrematuro($dijo))->toBeTrue();
})->with([
    'la pregunta del caso real' => ['para el 26 de septiembre?'],
    'disponibilidad a secas' => ['no tienes disponibilidad?'],
    'precio' => ['cuánto cuesta la cabaña más grande'],
    'fecha con más texto' => ['oye y para el 3 de octubre habría lugar?'],
]);

it('lo que sí necesita una persona se sigue transfiriendo', function (string $dijo, string $motivo) {
    expect(esPrematuro($dijo, $motivo))->toBeFalse();
})->with([
    'pide hablar con alguien' => ['quiero hablar con una persona', ''],
    'se queja' => ['tengo una queja del servicio de ayer', ''],
    'reclama un pago' => ['ya pagué y no aparece mi reserva', ''],
    'un evento' => ['quiero cotizar una boda para 80 personas', ''],
    'restricción interna' => ['para el 26 de septiembre?', 'revisión de recepción'],
    'el motivo habla de un pago' => ['para el 26 de septiembre?', 'insiste en que ya hizo el depósito'],
    'sin mensajes del huésped' => ['', ''],
]);

// ------------- "para el sabado" (cabañas, conv. 917, RES-2026-1758)
//
// El miércoles 16-sep el huésped pidió "una cabaña para el sabado". El bot
// le vendió el VIERNES 18 llamándolo "sábado 18 de septiembre", pagó $1,500
// de anticipo y el error se descubrió el jueves 17, con el sábado 19 lleno.
// El servidor no veía NINGUNA fecha en ese mensaje, así que ni el prompt le
// dictaba cuál era ni el guardián podía compararla.

function fechasPedidas(string $dijo): array
{
    return array_keys(
        (new ReflectionMethod(AgentBrain::class, 'datesMentioned'))
            ->invoke(app(AgentBrain::class), $dijo, true),
    );
}

it('un día de la semana suelto ya es una fecha', function (string $dijo, string $espera) {
    // Miércoles 16 de septiembre de 2026, el día de la conversación real.
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-16 12:29'));

    expect(fechasPedidas($dijo))->toContain($espera);
})->with([
    'el mensaje real' => ['Precio para una cabaña para 3 personas dos adultos y un menor para el sabado', '2026-09-19'],
    'con acento' => ['me interesa el sábado', '2026-09-19'],
    'este viernes' => ['este viernes tienen lugar?', '2026-09-18'],
    'el próximo domingo' => ['el proximo domingo', '2026-09-20'],
    'el mismo día de hoy cuenta' => ['el miercoles', '2026-09-16'],
]);

it('no confunde una costumbre con una fecha', function (string $dijo) {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-16 12:29'));

    expect(fechasPedidas($dijo))->toBe([]);
})->with([
    'plural genérico' => ['abren los sabados?'],
    'sin determinante' => ['normalmente vengo en sabado'],
    // Itinerario que se repite: la conversación ya tenía su fecha y
    // resolverla a la de esta semana la pisaría (AgentDateAnchorTest).
    'entrada y salida juntas' => ['Ok entraría el sábado alas 2pm y salgo el domingo alas 11'],
]);

it('con la fecha resuelta, el prompt se la dicta al modelo', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-16 12:29'));

    $channel = Channel::firstOrCreate(
        ['property_id' => $this->property->id, 'type' => Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );
    $conversation = Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '5216565518468',
        'status' => Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);
    $conversation->messages()->create([
        'direction' => 'in',
        'sender_type' => 'guest',
        'body' => 'Precio para una cabaña para 3 personas para el sabado',
        'created_at' => now(),
    ]);

    $bloque = (new ReflectionMethod(AgentBrain::class, 'requestedDatesBlock'))
        ->invoke(app(AgentBrain::class), $conversation);

    expect($bloque)->toContain('sábado 19 de septiembre de 2026')
        ->and($bloque)->toContain('2026-09-19')
        ->and($bloque)->not->toContain('18 de septiembre');
});

// ---- El apartado se verifica contra la fecha pedida, y el día suelto se confirma

function conversacionQueDijo(string $dijo): Conversation
{
    $channel = Channel::firstOrCreate(
        ['property_id' => test()->property->id, 'type' => Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );
    $conversation = Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '52165'.random_int(10000000, 99999999),
        'status' => Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);
    $conversation->messages()->create([
        'direction' => 'in',
        'sender_type' => 'guest',
        'body' => $dijo,
        'created_at' => now(),
    ]);

    return $conversation;
}

function chocaLaFecha(Conversation $c, string $startsAt): ?string
{
    return (new ReflectionMethod(AgentBrain::class, 'holdDateMismatch'))
        ->invoke(app(AgentBrain::class), $c, $startsAt);
}

it('no aparta un viernes a quien pidió el sábado', function () {
    // Miércoles 16: "el sábado" es el 19, no el 18.
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-16 12:29'));
    $c = conversacionQueDijo('Precio para una cabaña para el sabado');

    $reclamo = chocaLaFecha($c, '2026-09-18 14:00');

    expect($reclamo)->not->toBeNull()
        ->and($reclamo)->toContain('2026-09-19')
        ->and($reclamo)->toContain('sábado 19 de septiembre');
});

it('la fecha correcta pasa sin estorbo', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-16 12:29'));
    $c = conversacionQueDijo('Precio para una cabaña para el sabado');

    expect(chocaLaFecha($c, '2026-09-19 14:00'))->toBeNull();
});

it('si en su último mensaje no dijo fecha, no se estorba el apartado', function () {
    // "Ok, ese" tras elegir una alternativa que ofreció el bot.
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-16 12:29'));
    $c = conversacionQueDijo('la 1 por favor');

    expect(chocaLaFecha($c, '2026-09-27 14:00'))->toBeNull();
});

it('cuando solo dijo el día, el prompt exige confirmarle la fecha', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-16 12:29'));
    $c = conversacionQueDijo('Precio para una cabaña para el sabado');

    $bloque = (new ReflectionMethod(AgentBrain::class, 'requestedDatesBlock'))
        ->invoke(app(AgentBrain::class), $c);

    expect($bloque)->toContain('solo dijo el DÍA DE LA SEMANA')
        ->and($bloque)->toContain('No apartes hasta que él confirme')
        ->and($bloque)->toContain('sábado 19 de septiembre');
});

it('si dio la fecha con número, no se le pide confirmarla de más', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-16 12:29'));
    $c = conversacionQueDijo('quiero el 19 de septiembre');

    $bloque = (new ReflectionMethod(AgentBrain::class, 'requestedDatesBlock'))
        ->invoke(app(AgentBrain::class), $c);

    expect($bloque)->toContain('19 de septiembre')
        ->and($bloque)->not->toContain('solo dijo el DÍA DE LA SEMANA');
});

// ---- El bot no promete mover una reserva: eso lo hace una persona
//
// Caso real (conv. 917, RES-2026-1758): al huésped que pagó por el viernes
// creyendo que era sábado le contestó "Entonces las fechas quedan: entrada
// sábado 19 de septiembre", con el sábado LLENO y sin herramienta para
// cambiar nada.

function saneaReagenda(string $texto, ?Conversation $c = null): string
{
    return (new ReflectionMethod(AgentBrain::class, 'enforceRescheduleClaims'))
        ->invoke(app(AgentBrain::class), $texto, $c ?? conversacionQueDijo('Es para el sabado 19'));
}

it('borra la promesa de mover la reserva y pasa con el personal', function () {
    $c = conversacionQueDijo('Disculpe hay un error, es para el sabado 19');

    $salida = saneaReagenda(
        "Tiene razón, me disculpo. Entonces las fechas quedan: entrada sábado 19 de septiembre.\n"
        .'Le pido confirmación para hacer la corrección de su reserva.',
        $c,
    );

    expect($salida)->not->toContain('las fechas quedan')
        ->and($salida)->not->toContain('hacer la corrección de su reserva')
        ->and($salida)->toContain('El cambio de fecha lo hace una persona del hotel')
        // Y la conversación queda en manos del personal, con el bot apagado.
        ->and($c->fresh()->bot_enabled)->toBeFalse()
        ->and($c->fresh()->status)->toBe(Conversation::STATUS_PENDING);
});

it('detecta las formas de prometerlo', function (string $frase) {
    expect(saneaReagenda($frase))->toContain('una persona del hotel');
})->with([
    'las fechas quedan' => ['Entonces las fechas quedan: entrada el sábado 19.'],
    'te la cambio' => ['Con gusto le cambio la reserva al domingo 20.'],
    'la muevo' => ['Ya la muevo para esas fechas, no se preocupe.'],
    'reagendar' => ['Puedo reagendar su estancia sin costo.'],
    'corrijo' => ['Corrijo las fechas de su apartado ahora mismo.'],
]);

it('no estorba lo que el bot sí puede decir', function (string $frase) {
    expect(saneaReagenda($frase))->toBe($frase);
})->with([
    'decir la verdad' => ['No puedo cambiar las fechas de su reserva; lo paso con el personal.'],
    'reactivar un apartado' => ['Si gusta lo reactivo con el mismo código si la cabaña sigue libre.'],
    'confirmar una reserva nueva' => ['Su apartado RES-2026-1800 queda para el sábado 19 de septiembre.'],
    'hablar de la alberca' => ['La alberca abre de 9:00 AM a 10:30 PM todos los días.'],
    'cotizar otra fecha' => ['Para el domingo 20 de septiembre sí tenemos la Cabaña Prisma disponible.'],
]);

// ------------------------------------- 8. la salida no es una noche (1011)

/**
 * Caso real cabañas 2026-09-17, conv. 1011. Una familia preguntó TRES veces
 * por el viernes 18 (llegada) al sábado 19 (salida). El modelo contestó bien
 * las tres —"¡Buenas noticias! Para el viernes 18 al sábado 19 de septiembre
 * sí hay disponibilidad"— y el guardián le borró la lista y le respondió
 * "Para el sábado 19 de septiembre no queda ninguna habitación libre".
 *
 * Dos cosas rotas: en "viernes 18 al sábado 19 de septiembre" el mes solo va
 * en la SEGUNDA fecha, así que el extractor únicamente veía el 19; y el 19 es
 * la SALIDA, no una noche. El hotel estaba lleno el 19 y libre el 18.
 */
it('no juzga por el día de salida: "del 18 al 19" es la noche del 18', function () {
    $llegada = now()->addDays(10);
    $salida = $llegada->copy()->addDay();

    // El día de SALIDA está lleno; la noche que se pide está libre.
    $this->tipos->keys()->each(fn (string $tipo) => ocupar($tipo, $salida));

    $dicho = 'Para el '.$llegada->locale('es')->isoFormat('dddd D')
        .' al '.$salida->locale('es')->isoFormat('dddd D [de] MMMM');

    $texto = "¡Buenas noticias! {$dicho} sí hay disponibilidad. Tenemos estas opciones:\n"
        ."- Cabaña Sencilla 1: \$3,000 por noche\n"
        ."- Cabaña Sencilla 2: \$3,000 por noche";

    expect(sanear($texto))->toBe($texto);
});

it('sigue atrapando el rango cuya NOCHE está ocupada', function () {
    $llegada = now()->addDays(10);
    $salida = $llegada->copy()->addDay();

    // Ahora sí: la noche que se pide está ocupada (la salida da igual).
    ocupar('Cabaña Sencilla 1', $llegada);

    $dicho = 'Para el '.$llegada->locale('es')->isoFormat('dddd D')
        .' al '.$salida->locale('es')->isoFormat('dddd D [de] MMMM');

    $salidaTexto = sanear("{$dicho} tenemos disponibles:\n- Cabaña Sencilla 1: \$3,000\n- Cabaña Luxury: \$3,500");

    expect($salidaTexto)->toContain('Cabaña Sencilla 1 ya no está disponible')
        ->and($salidaTexto)->not->toContain('Cabaña Sencilla 1: $3,000')
        // La que sí está libre esa noche se queda.
        ->and($salidaTexto)->toContain('Cabaña Luxury: $3,500');
});

it('en una estancia de varias noches exige que esté libre TODAS', function () {
    $llegada = now()->addDays(10);
    $enMedio = $llegada->copy()->addDay();
    $salida = $llegada->copy()->addDays(3);

    // Libre la primera noche, ocupada la segunda: no se puede ofrecer.
    ocupar('Cabaña Sencilla 1', $enMedio);

    $dicho = 'Del '.$llegada->locale('es')->isoFormat('dddd D')
        .' al '.$salida->locale('es')->isoFormat('dddd D [de] MMMM');

    $texto = sanear("{$dicho} tenemos disponibles:\n- Cabaña Sencilla 1: \$3,000\n- Cabaña Luxury: \$3,500");

    expect($texto)->toContain('Cabaña Sencilla 1 ya no está disponible')
        ->and($texto)->toContain('Cabaña Luxury: $3,500');
});
