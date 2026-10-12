<?php

use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Agent\AgentBrain;
use Carbon\CarbonImmutable;

/**
 * Auditoría de cabañas del 9 al 12 de octubre de 2026 (71 conversaciones).
 * Cada caso es una frase que de verdad le llegó a un huésped.
 */
function cerebroOct12(): AgentBrain
{
    return (new ReflectionClass(AgentBrain::class))->newInstanceWithoutConstructor();
}

// ------------------------------------------------ días de la semana

it('corrige los días de la semana que se colaban', function (string $hoy, string $dicho, string $espera) {
    test()->travelTo(CarbonImmutable::parse($hoy, 'America/Ciudad_Juarez'));

    expect(cerebroOct12()->sanitizeWeekdays($dicho))->toBe($espera);
})->with([
    // Conv. 1869: el 1 de enero que viene es viernes (el de 2026 fue jueves).
    ['2026-10-11 17:02', 'llegarían el miércoles 30 de diciembre y se van el jueves 1 de enero', 'llegarían el miércoles 30 de diciembre y se van el viernes 1 de enero'],
    // Conv. 1843: rango sin mes en la segunda fecha.
    ['2026-10-10 10:44', 'puedo apartarte para mañana domingo 11 de octubre al domingo 12.', 'puedo apartarte para mañana domingo 11 de octubre al lunes 12.'],
    // Conv. 1833.
    ['2026-10-09 15:19', 'El próximo domingo es el 12 de octubre.', 'El próximo lunes es el 12 de octubre.'],
    // Fin de año: "31 de diciembre al viernes 1" cambia de mes.
    ['2026-10-11 14:34', 'del jueves 31 de diciembre al sábado 1', 'del jueves 31 de diciembre al viernes 1'],
]);

it('no toca un número que no es fecha', function () {
    test()->travelTo(CarbonImmutable::parse('2026-10-11 12:00', 'America/Ciudad_Juarez'));

    $texto = 'Para el sábado 17 de octubre. El domingo 15 personas no caben en una sola cabaña.';

    expect(cerebroOct12()->sanitizeWeekdays($texto))->toBe($texto);
});

// ------------------------------------------------ palabras rotas

it('arregla las palabras rotas que salieron esos días', function (string $dicho, string $espera) {
    expect(cerebroOct12()->fixKnownLeaks($dicho))->toBe($espera);
})->with([
    ['¿De qué viernes estamos hablando y cuántos días se Stayingían para la cabaña?', '¿De qué viernes estamos hablando y cuántos días se quedarían para la cabaña?'],
    ['¿Eligen alguna en particular o les da igual cualquieravailable para la noche?', '¿Eligen alguna en particular o les da igual cualquiera disponible para la noche?'],
    ['Perfecto. Quedas registrado en la lista para esa noche. Tan pronto como haya cancellation te aviso por este chat y preparas el pago de la cabaña.', 'Perfecto. Quedas registrado en la lista para esa noche. Tan pronto como haya cancelación te aviso por este chat y preparas el pago de la cabaña.'],
    ['Sobre la distribución le confirmo con el personal. ¿Desea que le transferya para más detalles?', 'Sobre la distribución le confirmo con el personal. ¿Desea que le transfiera para más detalles?'],
    ['Paraapartarlo se hace por aquí con el link para las cabañas.', 'Para apartarlo se hace por aquí con el link para las cabañas.'],
    ['Al hacer la transferencia, por favorenvía el comprobante por este chat para que lo verifique.', 'Al hacer la transferencia, por favor envía el comprobante por este chat para que lo verifique.'],
    ['Paga cuando gustes yenvíame el comprobante por este chat para que el hotel lo verifique.', 'Paga cuando gustes y envíame el comprobante por este chat para que el hotel lo verifique.'],
]);

// ------------------------------------------------ meta-respuestas

it('reconoce cuando el modelo habla de su tarea en vez de contestar', function (string $texto) {
    expect(cerebroOct12()->metaTalk($texto))->toBeTrue();
})->with([
    // Lo que le llegó a la huésped de la conv. 1831.
    'No hay ningún mensaje de un asistente de hotel en la entrada que proporcionaste. Lo que veo es una llamada de herramienta ("consultar_disponibilidad") con parámetros de fecha y tarifa, no un mensaje para reescribir. ¿Podrías compartir el mensaje real que deseas que reescriba?',
    '{"name": "consultar_disponibilidad", "arguments": {"fecha": "2026-10-31"}}',
    'Traducción: Hola, con gusto le ayudo.',
]);

it('no confunde una respuesta normal con una meta-respuesta', function (string $texto) {
    expect(cerebroOct12()->metaTalk($texto))->toBeFalse();
})->with([
    'Cabaña Prisma, sábado 31 de octubre al domingo 1 de noviembre: disponible.',
    'Le comparto el link de pago; al pagar mándeme el comprobante por este chat.',
    'Con gusto le paso su mensaje al personal para que le confirme.',
]);

// ------------------------------------------------ precios por tipo

it('detecta un precio por noche que no es el del tipo', function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    $property = Property::factory()->create(['name' => 'Cabañas Real de la Sierra']);

    foreach (['Cabaña Real' => 4500, 'Cabaña Luxury' => 3500, 'Cabaña Prisma' => 3500, 'Cabaña Escondida' => 3000, 'Cabaña Sencilla 1' => 3000, 'Cabaña Sencilla 2' => 3000] as $nombre => $precio) {
        $type = RoomType::factory()->create(['property_id' => $property->id, 'name' => $nombre]);
        Room::factory()->create(['property_id' => $property->id, 'room_type_id' => $type->id]);
        RatePlan::factory()->create(['property_id' => $property->id, 'room_type_id' => $type->id, 'price' => $precio]);
    }

    $brain = cerebroOct12();

    // Conv. 1833 y 1855.
    expect($brain->nightlyPriceMismatch("- Luxury, Prisma, Escondida ($3,500/noche): incluyen asador.\n- Sencillas 1 a 4 ($3,000/noche)"))
        ->toContain('Cabaña Escondida $3,000')
        ->and($brain->nightlyPriceMismatch('- Cabaña Luxury, Prisma, Escondida o Sencillas: $3,000 por noche (incluyen 4 personas)'))
        ->not->toBeNull()
        // Bien agrupado y con persona extra: no es error.
        ->and($brain->nightlyPriceMismatch("- Cabaña Luxury y Cabaña Prisma: $3,500 por noche\n- Cabaña Escondida y Cabañas Sencillas: $3,000 por noche\n- Cabaña Real con 2 personas extra: $5,000 por noche"))
        ->toBeNull();
});

// ------------------------------------------------ ligas de pago muertas

it('detecta la liga de un cobro ya pagado', function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $pagado = \App\Models\PaymentRequest::create([
        'concept' => 'deposit', 'amount' => 1500, 'currency' => 'MXN', 'method' => 'mercadopago',
        'status' => \App\Models\PaymentRequest::STATUS_PAID,
    ]);
    $vivo = \App\Models\PaymentRequest::create([
        'concept' => 'balance', 'amount' => 1500, 'currency' => 'MXN', 'method' => 'mercadopago',
        'status' => \App\Models\PaymentRequest::STATUS_PENDING,
    ]);

    $brain = cerebroOct12();

    expect($brain->stalePaymentLink("Ahora te mando el link para liquidar:\nhttps://hotel.test/pago/{$pagado->uuid}"))
        ->toContain('ya está pagada')
        ->and($brain->stalePaymentLink("Liga para el saldo: https://hotel.test/pago/{$vivo->uuid}"))
        ->toBeNull();
});

// ------------------------------------------------ disponibilidad sin consultar

it('reconoce cuando afirma que hay lugar', function (string $texto) {
    expect(cerebroOct12()->claimsUnverifiedAvailability($texto))->toBeTrue();
})->with([
    '¡Hola! Qué gusto atenderte. Sí tenemos cabañas para esas fechas. Aquí están los precios por noche:', // conv. 1862
    '¡Perfecto! El martes 27 de octubre ya está disponible. ¿Serían cuántas noches?', // conv. 1875
    '¡Hola! Sí, la Cabaña Escondida para el sábado 28 de noviembre sigue disponible.', // conv. 1832
]);

it('no confunde preguntas, negativas ni la alberca con una oferta', function (string $texto) {
    expect(cerebroOct12()->claimsUnverifiedAvailability($texto))->toBeFalse();
})->with([
    '¿Te gustaría verificar si hay disponibilidad para esas fechas?',
    'La Cabaña Luxury no está disponible para esas fechas.',
    'Sí, la alberca está disponible de 9:00 AM a 10:30 PM.',
    'Estos son nuestros precios por noche: Cabaña Real $4,500.',
]);

// ------------------------------------------------ el apartado nombra su cabaña

it('agrega la cabaña apartada cuando la respuesta no la nombra', function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    $property = Property::factory()->create(['name' => 'Cabañas Real de la Sierra']);

    $planes = [];
    foreach (['Cabaña Sencilla 2', 'Cabaña Sencilla 4'] as $nombre) {
        $type = RoomType::factory()->create(['property_id' => $property->id, 'name' => $nombre]);
        Room::factory()->create(['property_id' => $property->id, 'room_type_id' => $type->id]);
        $planes[$nombre] = RatePlan::factory()->create(['property_id' => $property->id, 'room_type_id' => $type->id, 'price' => 3000]);
    }

    $date = now()->addDays(30);
    $reservation = app(\App\Actions\Reservations\CreateReservation::class)->handle([
        'rate_plan_id' => $planes['Cabaña Sencilla 4']->id,
        'starts_at' => $date->copy()->setTime(14, 0),
        'ends_at' => $date->copy()->addDay()->setTime(11, 0),
        'source_channel' => 'agent',
        'guest_name' => 'Jaz',
    ]);

    $channel = \App\Models\Channel::firstOrCreate(
        ['property_id' => $property->id, 'type' => \App\Models\Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );
    $conversation = \App\Models\Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '5216560000000',
        'status' => \App\Models\Conversation::STATUS_OPEN,
        'reservation_id' => $reservation->id,
        'last_message_at' => now(),
    ]);

    $brain = cerebroOct12();
    $dicho = 'Tu apartado está listo. Puedes pagar el anticipo de $1,500.00 en este link.';

    // Lo que le llegó a la huésped (conv. 1847): sin cabaña.
    expect($brain->ensureHoldNamesRoom($dicho, $conversation, ['hold']))
        ->toContain('es para la Cabaña Sencilla 4')
        // Si no se apartó nada en este turno, no se toca.
        ->and($brain->ensureHoldNamesRoom($dicho, $conversation, ['availability']))->toBe($dicho)
        // Si ya nombra la cabaña correcta, tampoco.
        ->and($brain->ensureHoldNamesRoom('Tu apartado de la Cabaña Sencilla 4 está listo.', $conversation, ['hold']))
        ->toBe('Tu apartado de la Cabaña Sencilla 4 está listo.');
});
