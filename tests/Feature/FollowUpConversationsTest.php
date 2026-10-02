<?php

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;

/**
 * Reenganche de cotizaciones frías. Los tres casos vienen de la bandeja
 * real de cabañas (2026-08-28/30), donde el "¿sigues por ahí?" salió
 * cuando no debía o prometió algo que el bot acababa de negar.
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

function coldConversation(array $messages, string $phone = '+5216141234567'): Conversation
{
    $conversation = Conversation::create([
        'channel_id' => test()->channel->id,
        'contact_phone' => $phone,
        'status' => Conversation::STATUS_OPEN,
        'lead_status' => Conversation::LEAD_QUOTING,
        'bot_enabled' => true,
        'last_message_at' => now()->subHour(),
    ]);

    // Puerta de entrada al reenganche: la herramienta confirmó disponibilidad
    // real para fechas concretas. Sin esto no se persigue a nadie.
    $conversation->markFollowup(\App\Services\Agent\AgentBrain::REAL_QUOTE);

    foreach ($messages as [$direction, $body]) {
        $conversation->messages()->create([
            'direction' => $direction,
            'sender_type' => $direction === 'in' ? 'visitor' : 'bot',
            'body' => $body,
            'created_at' => now()->subHour(),
        ]);
    }

    return $conversation;
}

function lastBody(Conversation $conversation): string
{
    return (string) $conversation->messages()->latest('id')->first()->body;
}

it('reengancha al huésped que se quedó callado a media cotización', function () {
    $conversation = coldConversation([
        ['in', 'Hola, ¿precio de la cabaña?'],
        ['out', 'La Cabaña Escondida son $3,000 por noche. ¿Para qué fechas?'],
    ]);

    test()->artisan('conversations:follow-up')->assertSuccessful();

    expect($conversation->messages()->count())->toBe(3)
        ->and(lastBody($conversation))->toContain('¿Sigues por ahí?')
        ->and($conversation->refresh()->followupSent('quote_nudge'))->toBeTrue();
});

it('no persigue a quien nunca escribió (hilos que abre el bot en redes)', function () {
    $conversation = coldConversation([
        ['out', 'Hola, gracias por tu interés en Cabañas Real de la Sierra. ¿Cuántas personas viajan?'],
    ]);

    test()->artisan('conversations:follow-up')->assertSuccessful();

    expect($conversation->messages()->count())->toBe(1)
        ->and($conversation->refresh()->followupSent('quote_nudge'))->toBeFalse();
});

it('no persigue a quien ya se despidió o dijo que él avisa', function () {
    $conversation = coldConversation([
        ['in', '¿Me da precios de las cabañas?'],
        ['out', 'Los precios por noche son: - Cabaña Real: $4,500'],
        ['in', 'Aún no lo he empezado a planear pero en cuanto tenga la fecha se lo hago saber'],
        ['out', 'Perfecto, cuando guste. Buen día.'],
    ]);

    test()->artisan('conversations:follow-up')->assertSuccessful();

    expect($conversation->messages()->count())->toBe(4);
});

it('no le vuelve a tocar la puerta a quien acabamos de rechazar por falta de lugar', function () {
    // Caso real cabañas (conv. 550, 2026-09-12): se le dijo dos veces que no
    // había nada y dos horas después el bot le escribió "¿sigues por ahí?".
    $conversation = coldConversation([
        ['in', '¿Tiene disponible el 5 y 6 de septiembre?'],
        ['out', 'Lamento informarle que no hay disponibilidad para el fin de semana del 5 y 6 de septiembre.'],
    ]);

    test()->artisan('conversations:follow-up')->assertSuccessful();

    expect($conversation->messages()->count())->toBe(2)
        ->and($conversation->refresh()->followupSent('quote_nudge'))->toBeFalse();
});

/**
 * Pedido del hotel de cabañas (2026-09-12): dejar de escribirle a quien solo
 * preguntó y desapareció. El primer intento filtró por número de mensajes y
 * NO bastó: aquí se escribe en fragmentos ("Buenas tardes" / "Para 2
 * personas" / "Mañana" ya son tres), así que la puerta es haber llegado a
 * una cotización con disponibilidad confirmada.
 */
it('no persigue a quien pidió precios pero nunca llegó a una cotización real', function () {
    $conversation = coldConversation([
        ['in', 'Hola! Información?'],
        ['out', '¿Qué tipo de información buscas? Habitaciones, costos, recorridos...'],
        ['in', 'Precio?'],
        ['out', 'Los precios por noche son: - Cabaña Real: $4,500 - Cabaña Luxury: $3,500'],
        ['in', '¿Cuál es el máximo de personas?'],
        ['out', 'La Cabaña Real hasta 8; las demás hasta 5.'],
        ['in', 'Recorridos canam'],
        ['out', 'Tenemos 4 recorridos, desde $1,000 por grupo.'],
    ]);

    // Nunca hubo disponibilidad confirmada para fechas concretas.
    $conversation->update(['followups' => null]);

    test()->artisan('conversations:follow-up')->assertSuccessful();

    expect($conversation->messages()->count())->toBe(8)
        ->and($conversation->refresh()->followupSent('quote_nudge'))->toBeFalse();
});

it('el tope por mensajes sigue sirviendo de apagador del reenganche', function () {
    $this->property->update(['settings' => ['nudge_min_messages' => 999]]);

    $conversation = coldConversation([
        ['in', 'Hola, ¿precio de la cabaña?'],
        ['out', 'La Cabaña Escondida son $3,000 por noche. ¿Para qué fechas?'],
    ]);

    test()->artisan('conversations:follow-up')->assertSuccessful();

    expect($conversation->refresh()->followupSent('quote_nudge'))->toBeFalse();
});

it('sí reengancha a quien alcanzó a conversar de verdad', function () {
    $this->property->update(['settings' => ['nudge_min_messages' => 5]]);

    $conversation = coldConversation([
        ['in', 'Hola, precio de las cabañas'],
        ['out', 'La Cabaña Escondida son $3,000 por noche. ¿Para qué fechas?'],
        ['in', 'Para el 20 de octubre'],
        ['out', '¿Cuántas personas serían?'],
        ['in', 'Somos 4'],
        ['out', 'Perfecto, el total sería $3,000 por esa noche.'],
        ['in', '¿Y el anticipo?'],
        ['out', 'El anticipo es del 50%: $1,500.'],
        ['in', 'Déjame lo checo'],
        ['out', 'Con gusto, aquí quedo.'],
    ]);

    test()->artisan('conversations:follow-up')->assertSuccessful();

    expect(lastBody($conversation))->toContain('¿Sigues por ahí?')
        ->and($conversation->refresh()->followupSent('quote_nudge'))->toBeTrue();
});

it('respeta el silencio que pide el hotel antes de escribir', function () {
    // Dos horas de espera: a los 45 minutos la persona todavía está
    // decidiendo ("están checando las cabañas", caso real del 11-sep).
    $this->property->update(['settings' => ['nudge_silence_minutes' => 120]]);

    $conversation = coldConversation([
        ['in', 'Hola, ¿precio de la cabaña?'],
        ['out', 'La Cabaña Escondida son $3,000 por noche. ¿Para qué fechas?'],
    ]);
    $conversation->update(['last_message_at' => now()->subMinutes(45)]);

    test()->artisan('conversations:follow-up')->assertSuccessful();

    expect($conversation->refresh()->followupSent('quote_nudge'))->toBeFalse();

    // Pasadas las dos horas sí sale.
    $conversation->update(['last_message_at' => now()->subMinutes(150)]);

    test()->artisan('conversations:follow-up')->assertSuccessful();

    expect($conversation->refresh()->followupSent('quote_nudge'))->toBeTrue();
});

it('un hotel sin ajustes se comporta igual que siempre', function () {
    $conversation = coldConversation([
        ['in', 'Hola, ¿precio de la cabaña?'],
        ['out', 'La Cabaña Escondida son $3,000 por noche. ¿Para qué fechas?'],
    ]);

    test()->artisan('conversations:follow-up')->assertSuccessful();

    expect($conversation->refresh()->followupSent('quote_nudge'))->toBeTrue();
});

/**
 * Pedido de Hotel México (2026-09-30): el "¿sigues por ahí?" solo para quien
 * se quedó en el paso de dar sus datos. En su conversación de prueba el bot
 * contestó el precio por noche y el aviso salió igual.
 */
it('en modo "solo tras pedir datos" no persigue a quien solo preguntó el precio', function () {
    $this->property->update(['settings' => ['nudge_only_after_data_request' => true]]);

    $conversation = coldConversation([
        ['in', 'y si la quiero por 1 mes'],
        ['out', 'La Sencilla por un mes tiene un total de $14,160.00. Para apartarla necesito su nombre completo y correo electrónico. ¿Me los proporciona?'],
        ['in', '¿Qué precio tiene por noche?'],
        ['out', 'La Sencilla cuesta $590.00 por noche. ¿Para cuántas personas sería?'],
    ]);

    test()->artisan('conversations:follow-up')->assertSuccessful();

    expect($conversation->messages()->count())->toBe(4)
        ->and($conversation->refresh()->followupSent('quote_nudge'))->toBeFalse();
});

it('en modo "solo tras pedir datos" sí le escribe a quien dejamos en el paso de sus datos', function () {
    $this->property->update(['settings' => ['nudge_only_after_data_request' => true]]);

    $conversation = coldConversation([
        ['in', 'Pero quiero la semana completa'],
        ['out', 'La Sencilla por una semana tiene un total de $3,510.50. Para apartarla necesito su nombre completo y correo electrónico. ¿Me los proporciona?'],
    ]);

    test()->artisan('conversations:follow-up')->assertSuccessful();

    expect(lastBody($conversation))->toContain('Quedé pendiente de tus datos para apartar tu habitación')
        ->and($conversation->refresh()->followupSent('quote_nudge'))->toBeTrue();
});

/**
 * Recordatorio y vencimiento de apartados. Caso real cabañas 2026-09-13
 * (RES-2026-1727): el recordatorio prometía "aviso a recepción para que lo
 * confirmen" —no existía ningún aviso— y la conv. 605 recibió "venció" dos
 * minutos después de que el personal le prometiera confirmarle al día
 * siguiente.
 */
function apartadoEnConversacion(array $estado): array
{
    $type = \App\Models\RoomType::factory()->create(['property_id' => test()->property->id]);
    \App\Models\Room::factory()->create(['property_id' => test()->property->id, 'room_type_id' => $type->id]);
    $plan = \App\Models\RatePlan::factory()->create(['property_id' => test()->property->id, 'room_type_id' => $type->id, 'price' => 3500]);

    $reservation = app(\App\Actions\Reservations\CreateReservation::class)->handle([
        'rate_plan_id' => $plan->id,
        'starts_at' => now()->addDays(10)->format('Y-m-d').' 14:00',
        'ends_at' => now()->addDays(11)->format('Y-m-d').' 11:00',
        'guest_name' => 'Elliot Alderson',
        'confirmed' => false,
    ]);
    $reservation->update($estado);

    $conversation = Conversation::create([
        'channel_id' => test()->channel->id,
        'contact_phone' => '+5216140000000',
        'status' => Conversation::STATUS_OPEN,
        'lead_status' => Conversation::LEAD_HOLD,
        'bot_enabled' => true,
        'reservation_id' => $reservation->id,
        'last_message_at' => now(),
    ]);

    return [$conversation, $reservation->refresh()];
}

it('el recordatorio dice la hora real y ya no promete avisar a recepción', function () {
    test()->travelTo(now()->setTime(18, 0));

    [$conversation] = apartadoEnConversacion(['hold_expires_at' => now()->addMinutes(6)]);

    test()->artisan('conversations:follow-up')->assertSuccessful();

    $body = lastBody($conversation);

    expect($body)->toContain('Recuerda: tu apartado')
        ->and($body)->toContain('queda guardado hasta hoy a las')
        ->and($body)->toContain('lo reactivo con el mismo código')
        ->and($body)->not->toContain('aviso a recepción');
});

it('sin intervención del personal, el apartado vencido sí recibe su aviso', function () {
    test()->travelTo(now()->setTime(18, 0));

    [$conversation] = apartadoEnConversacion([
        'status' => \App\Enums\ReservationStatus::Cancelled,
        'hold_expires_at' => now()->subMinutes(3),
    ]);

    test()->artisan('conversations:follow-up')->assertSuccessful();

    expect($conversation->refresh()->followupSent('hold_expired'))->toBeTrue()
        ->and(lastBody($conversation))->toContain('venció');
});

it('no le dice "venció" a quien el personal ya atendió sobre ese apartado', function () {
    test()->travelTo(now()->setTime(18, 0));

    [$conversation] = apartadoEnConversacion([
        'status' => \App\Enums\ReservationStatus::Cancelled,
        'hold_expires_at' => now()->subMinutes(3),
    ]);

    $conversation->messages()->create([
        'direction' => 'out',
        'sender_type' => 'staff',
        'body' => 'En caso de realizar el depósito mañana, se te confirmará nuevamente la reserva.',
        'created_at' => now(),
    ]);

    test()->artisan('conversations:follow-up')->assertSuccessful();

    expect($conversation->refresh()->followupSent('hold_expired'))->toBeFalse()
        ->and($conversation->messages()->where('sender_type', 'bot')->count())->toBe(0);
});
