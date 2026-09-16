<?php

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Property;
use App\Services\Agent\AgentBrain;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;

// Las tres fallas medidas en el corpus del VPS del 10 al 12 de septiembre
// (405 conversaciones reales): respuestas duplicadas y contradictorias por
// ráfaga, traspasos anunciados que nunca ocurrieron, y el bot contestando
// encima del personal y contradiciéndolo.

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();

    $channel = Channel::firstOrCreate(
        ['property_id' => $this->property->id, 'type' => 'whatsapp', 'external_id' => null],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );

    $this->conversation = Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '5216560000000',
        'status' => Conversation::STATUS_OPEN,
        'bot_enabled' => true,
        'last_message_at' => now(),
    ]);
});

function entrante(string $body): Message
{
    return test()->conversation->messages()->create([
        'direction' => 'in',
        'sender_type' => 'visitor',
        'body' => $body,
        'created_at' => now(),
    ]);
}

function saliente(string $body, string $senderType = 'bot'): Message
{
    return test()->conversation->messages()->create([
        'direction' => 'out',
        'sender_type' => $senderType,
        'body' => $body,
        'created_at' => now(),
    ]);
}

// ---------------------------------------------------------------- ráfagas

it('se calla cuando ya llegó un mensaje más nuevo del huésped', function () {
    // La ráfaga real: "Buen día" / "somos 8 personas" / "tendrá algo
    // disponible" en tres webhooks a un segundo uno del otro.
    $primero = entrante('Buen día');
    entrante('Somos 8 personas');

    $antes = $this->conversation->messages()->count();

    expect(app(AgentBrain::class)->replyTo($this->conversation, $primero))->toBeNull()
        ->and($this->conversation->messages()->count())->toBe($antes);
});

it('la corrida del último mensaje sí contesta a la ráfaga completa', function () {
    entrante('Buen día');
    $ultimo = entrante('Somos 8 personas');

    // Sin proveedor de IA configurado el cerebro cae a traspaso: lo que se
    // comprueba aquí es que esta corrida NO se retira, sí actúa.
    app(AgentBrain::class)->replyTo($this->conversation, $ultimo);

    expect($this->conversation->messages()->where('direction', 'out')->count())->toBe(1);
});

// -------------------------------------------------- el traspaso anunciado

it('convierte en traspaso real el que el bot solo anunció', function () {
    entrante('Quiero hablar con una persona');

    $brain = app(AgentBrain::class);
    $texto = 'Ya transferí su solicitud a una persona del hotel. Recibirá una llamada pronto.';

    $salida = (fn () => $this->enforceHandoffClaims($texto, test()->conversation))->call($brain);

    $this->conversation->refresh();

    expect($this->conversation->bot_enabled)->toBeFalse()
        ->and($this->conversation->status)->toBe(Conversation::STATUS_PENDING)
        // La promesa que el producto no puede cumplir se cae completa.
        ->and($salida)->not->toContain('llamada')
        ->and($salida)->not->toContain('transferí')
        ->and($salida)->toContain('persona del hotel');
});

it('deja intacto un mensaje de pago que habla de transferencia y del personal', function () {
    entrante('Cómo pago');

    $brain = app(AgentBrain::class);
    $texto = 'Datos para transferencia: Banco BBVA, cuenta 4152314577952941, monto $1,225.00. '
        .'Manda tu comprobante por aquí y el personal lo verifica.';

    $salida = (fn () => $this->enforceHandoffClaims($texto, test()->conversation))->call($brain);

    $this->conversation->refresh();

    expect($salida)->toBe($texto)
        ->and($this->conversation->bot_enabled)->toBeTrue()
        ->and($this->conversation->status)->toBe(Conversation::STATUS_OPEN);
});

// ------------------------------------------- lo que dijo el personal vale

it('marca el turno del personal para que el modelo no lo lea como suyo', function () {
    entrante('Hay lugar el sábado?');
    saliente('Para este sábado tenemos todo ocupado, solo disponible el domingo.', 'staff');

    $historial = (fn () => $this->history(test()->conversation))->call(app(AgentBrain::class));

    $turnoDelPersonal = collect($historial)->first(
        fn ($turno) => $turno instanceof AssistantMessage && str_contains($turno->content, 'todo ocupado'),
    );

    expect($turnoDelPersonal)->not->toBeNull()
        ->and($turnoDelPersonal->content)->toStartWith('[PERSONAL DEL HOTEL');
});

it('conserva lo que dijo el personal aunque se salga de la ventana de 20 mensajes', function () {
    saliente('Para este sábado tenemos todo ocupado, solo disponible el domingo.', 'staff');

    // 30 mensajes después, el compromiso del personal ya no cabría en la
    // ventana por id — y es justo la línea que el bot no debe contradecir.
    foreach (range(1, 15) as $i) {
        entrante("pregunta {$i}");
        saliente("respuesta {$i}");
    }

    $historial = (fn () => $this->history(test()->conversation))->call(app(AgentBrain::class));

    expect(collect($historial)->contains(
        fn ($turno) => $turno instanceof AssistantMessage && str_contains($turno->content, 'todo ocupado'),
    ))->toBeTrue();
});

// ------------------------------------------------ el embudo deja de ser ciego

it('marca como cotizando en cuanto el bot suelta un precio', function () {
    // El hotel tiene su lista de tarifas en las instrucciones de texto libre,
    // así que el bot contesta "¿precios?" sin tocar una herramienta y el lead
    // se quedaba en `new`: 252 de 382 conversaciones cotizadas del VPS. Sin
    // esa marca, el seguimiento que recupera al 24% nunca las perseguía.
    $brain = app(AgentBrain::class);
    $texto = 'Los precios por noche son: - Cabaña Real: $4,500 - Cabaña Luxury: $3,500';

    (fn () => $this->markQuotedLead(test()->conversation, $texto))->call($brain);

    expect($this->conversation->refresh()->lead_status)->toBe(Conversation::LEAD_QUOTING);
});

it('no mueve el embudo con una respuesta que no trae precio', function () {
    $brain = app(AgentBrain::class);

    (fn () => $this->markQuotedLead(test()->conversation, 'La salida es a las 11:00 AM.'))->call($brain);

    expect($this->conversation->refresh()->lead_status)->toBe(Conversation::LEAD_NEW);
});

it('nunca hace retroceder un apartado que ya existe', function () {
    $this->conversation->update(['lead_status' => Conversation::LEAD_HOLD]);

    $brain = app(AgentBrain::class);

    (fn () => $this->markQuotedLead(test()->conversation, 'El anticipo es de $2,250.00'))->call($brain);

    expect($this->conversation->refresh()->lead_status)->toBe(Conversation::LEAD_HOLD);
});

// ------------------------------------------ el plazo real del apartado manda

function apartadoVigente(\Carbon\CarbonInterface $vence): \App\Models\Reservation
{
    $type = \App\Models\RoomType::factory()->create(['property_id' => test()->property->id, 'name' => 'Cabaña Luxury']);
    \App\Models\Room::factory()->create(['property_id' => test()->property->id, 'room_type_id' => $type->id]);
    $plan = \App\Models\RatePlan::factory()->create(['property_id' => test()->property->id, 'room_type_id' => $type->id, 'price' => 3500]);

    $reservation = app(\App\Actions\Reservations\CreateReservation::class)->handle([
        'rate_plan_id' => $plan->id,
        'starts_at' => now()->addDays(10)->format('Y-m-d').' 14:00',
        'ends_at' => now()->addDays(11)->format('Y-m-d').' 11:00',
        'guest_name' => 'Elliot Alderson',
        'confirmed' => false,
    ]);

    $reservation->update(['hold_expires_at' => $vence]);

    return $reservation->refresh();
}

it('quita la promesa de pagar mañana cuando el apartado vence hoy', function () {
    // Caso real RES-2026-1727 (2026-09-13): vencía a las 8:50 PM y el bot le
    // dijo al huésped que podía hacer la transferencia mañana.
    $this->travelTo(now()->setTime(18, 50));

    $reservation = apartadoVigente(now()->addHours(2));
    $this->conversation->update(['reservation_id' => $reservation->id]);

    $texto = "Perfecto, tu apartado queda guardado.\nPuedes hacer la transferencia mañana dentro del horario de 9:00 AM a 5:00 PM.";

    $salida = (fn () => $this->enforceHoldDeadlineClaims($texto, test()->conversation->refresh()))->call(app(AgentBrain::class));

    expect($salida)->not->toContain('transferencia mañana')
        ->and($salida)->toContain('queda guardado hasta hoy a las 8:50 PM')
        ->and($salida)->toContain('lo reactivo con el mismo código');
});

// ---------------------------------------- el número del chat es la llave

it('no pisa el número de WhatsApp con el teléfono que teclea el huésped', function () {
    // Caso real cabañas 2026-09-14 (Kevin, conv. 744 → 755): tecleó
    // 6565280146, la herramienta lo guardó encima de 5216565280146 y su
    // siguiente mensaje abrió una conversación nueva sin memoria.
    $handoff = false;
    $used = [];
    $reason = '';

    $tools = (fn () => $this->toolset($handoff, test()->conversation, false, $used, $reason))->call(app(AgentBrain::class));
    $tool = collect($tools)->first(fn ($tool) => $tool->name() === 'identificar_huesped');

    $tool->handle('6560000000', 'Kevin Andrés');

    expect($this->conversation->refresh()->contact_phone)->toBe('5216560000000')
        ->and($this->conversation->contact_name)->toBe('Kevin Andrés');
});

// --------------------------------------------- el pago lo confirma el sistema

it('no da por pagado un anticipo que el sistema no registró', function () {
    // Caso real RES-2026-1728 (2026-09-14): comprobante sin verificar y el
    // bot escribió "Anticipo de $1,500 pagado".
    $reservation = apartadoVigente(now()->addHour());
    $this->conversation->update(['reservation_id' => $reservation->id]);

    $texto = "El personal verificará su comprobante de transferencia. Le confirmo:\n"
        ."- Cabaña Escondida para el viernes 18 de septiembre\n"
        ."- Anticipo de \$1,500 pagado\n"
        .'- Queda un saldo de $1,500 que se cubre una semana antes de la llegada';

    $salida = (fn () => $this->enforcePaymentClaims($texto, test()->conversation->refresh()))->call(app(AgentBrain::class));

    expect($salida)->not->toContain('pagado')
        ->and($salida)->toContain('Cabaña Escondida')
        ->and($salida)->toContain('Queda un saldo')
        ->and($salida)->toContain('quede registrado');
});

it('quita la amenaza de cancelar cuando ya hay dinero, y deja el pago que sí existe', function () {
    $reservation = apartadoVigente(now()->addHour());
    $reservation->update(['payment_status' => \App\Enums\PaymentStatus::DepositPaid]);
    $this->conversation->update(['reservation_id' => $reservation->id]);

    $texto = 'Tu anticipo de $1,500 quedó registrado. Si no se paga el saldo a tiempo, la reserva se cancela. ¿Algo más?';

    $salida = (fn () => $this->enforcePaymentClaims($texto, test()->conversation->refresh()))->call(app(AgentBrain::class));

    expect($salida)->toContain('quedó registrado')
        ->and($salida)->not->toContain('se cancela')
        ->and($salida)->toContain('¿Algo más?');
});

it('deja intacto lo que habla del pago en futuro', function () {
    $reservation = apartadoVigente(now()->addHour());
    $this->conversation->update(['reservation_id' => $reservation->id]);

    $texto = 'Una vez que el pago sea verificado, recibirás tu contrato digital por correo.';

    $salida = (fn () => $this->enforcePaymentClaims($texto, test()->conversation->refresh()))->call(app(AgentBrain::class));

    expect($salida)->toBe($texto);
});

it('deja la promesa de mañana si el apartado de verdad aguanta hasta mañana', function () {
    $this->travelTo(now()->setTime(18, 50));

    $reservation = apartadoVigente(now()->addDay()->setTime(10, 0));
    $this->conversation->update(['reservation_id' => $reservation->id]);

    $texto = 'Puedes hacer la transferencia mañana a partir de las 9:00 AM.';

    $salida = (fn () => $this->enforceHoldDeadlineClaims($texto, test()->conversation->refresh()))->call(app(AgentBrain::class));

    expect($salida)->toBe($texto);
});
