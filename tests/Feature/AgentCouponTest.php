<?php

use App\Actions\Reservations\CreateReservation;
use App\Http\Controllers\Agent\AgentToolsController;
use App\Models\Coupon;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Request;

// Pedido del hotel de cabañas (2026-09-11): el bot no tenía cómo aplicar
// cupones. Su cupón real se llama "VERANO 25" (con espacio), así que el
// huésped que escribe "verano25" también debe encontrarlo.

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Cabaña Luxury']);
    Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1000,
    ]);

    Coupon::create(['code' => 'VERANO 25', 'kind' => 'percent', 'value' => 10, 'min_nights' => 2, 'active' => true]);
});

function couponParams(int $nights, array $extra = []): array
{
    return [
        'rate_plan_id' => test()->plan->id,
        'starts_at' => now()->addDays(10)->format('Y-m-d').' 14:00',
        'ends_at' => now()->addDays(10 + $nights)->format('Y-m-d').' 11:00',
        ...$extra,
    ];
}

it('valida el cupón aunque el huésped lo escriba sin espacio y calcula el total', function () {
    $response = app(AgentToolsController::class)->checkCoupon(
        Request::create('/b', 'POST', couponParams(2, ['code' => 'verano25'])),
    );
    $data = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(200)
        ->and($data['code'])->toBe('VERANO 25')
        ->and($data['total_label'])->toBe('$1,800.00')
        ->and($data['quote_notice'][0])->toContain('Cabaña Luxury con el cupón VERANO 25');
});

it('dice el motivo exacto cuando el cupón no aplica', function () {
    $response = app(AgentToolsController::class)->checkCoupon(
        Request::create('/b', 'POST', couponParams(1, ['code' => 'VERANO 25'])),
    );

    expect($response->getStatusCode())->toBe(422)
        ->and(json_decode($response->getContent(), true)['message'])->toContain('al menos 2 noches');

    $unknown = app(AgentToolsController::class)->checkCoupon(Request::create('/b', 'POST', ['code' => 'INVENTADO']));
    expect($unknown->getStatusCode())->toBe(422);
});

it('crear_apartado aplica el cupón y congela el descuento', function () {
    $response = app(AgentToolsController::class)->storeHold(
        Request::create('/b', 'POST', couponParams(2, ['guest_name' => 'Karely Baray', 'coupon_code' => 'verano25'])),
        app(CreateReservation::class),
    );

    $reservation = Reservation::firstOrFail();

    expect($response->getStatusCode())->toBe(201)
        ->and($reservation->coupon_code)->toBe('VERANO 25')
        ->and((float) $reservation->discount_amount)->toBe(200.0)
        ->and((float) $reservation->total_amount)->toBe(1800.0);
});

it('un cupón que no aplica no crea el apartado y explica por qué', function () {
    $response = app(AgentToolsController::class)->storeHold(
        Request::create('/b', 'POST', couponParams(1, ['guest_name' => 'Karely Baray', 'coupon_code' => 'VERANO 25'])),
        app(CreateReservation::class),
    );

    expect($response->getStatusCode())->toBe(422)
        ->and(json_decode($response->getContent(), true)['message'])->toContain('al menos 2 noches')
        ->and(Reservation::count())->toBe(0);
});

it('encuentra el cupón aunque su código guardado traiga un espacio no separable', function () {
    // Copiado desde el celular o una hoja de cálculo: "VERANO\u{00A0}25".
    Coupon::query()->delete();
    Coupon::create(['code' => "OTONO\u{00A0}30", 'kind' => 'percent', 'value' => 10, 'active' => true]);

    $response = app(AgentToolsController::class)->checkCoupon(
        Request::create('/b', 'POST', ['code' => 'otono 30']),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and(json_decode($response->getContent(), true)['code'])->toBe("OTONO\u{00A0}30");
});

it('reconoce el cupón que el huésped menciona sin decir "cupón" (caso real PACHEPACHE)', function () {
    Coupon::query()->delete();
    Coupon::create(['code' => 'PACHEPACHE', 'kind' => 'percent', 'value' => 30, 'active' => true]);

    $channel = \App\Models\Channel::firstOrCreate(
        ['property_id' => $this->property->id, 'type' => \App\Models\Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );
    $conversation = \App\Models\Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '5216560000000',
        'status' => \App\Models\Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);
    $conversation->messages()->create(['direction' => 'in', 'sender_type' => 'visitor', 'body' => 'Hola buenas tardes vengo del video del pache pache', 'created_at' => now()]);
    $conversation->messages()->create(['direction' => 'in', 'sender_type' => 'visitor', 'body' => 'Si, con el 30% de pache pache', 'created_at' => now()]);

    $brain = app(\App\Services\Agent\AgentBrain::class);
    $block = (fn () => $this->couponBlock($conversation))->call($brain);

    expect($block)->toContain('PACHEPACHE')
        ->and($block)->toContain('30% de descuento')
        ->and($block)->toContain('validar_cupon');

    // Sin mención, el prompt no carga nada.
    $otra = \App\Models\Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '5216560000001',
        'status' => \App\Models\Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);
    $otra->messages()->create(['direction' => 'in', 'sender_type' => 'visitor', 'body' => '¿Tienen alberca?', 'created_at' => now()]);

    expect((fn () => $this->couponBlock($otra))->call($brain))->toBe('');
});

function couponConversation(string ...$dijo): \App\Models\Conversation
{
    $channel = \App\Models\Channel::firstOrCreate(
        ['property_id' => test()->property->id, 'type' => \App\Models\Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );
    $conversation = \App\Models\Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '521656'.random_int(1000000, 9999999),
        'status' => \App\Models\Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);

    foreach ($dijo as $body) {
        $conversation->messages()->create(['direction' => 'in', 'sender_type' => 'visitor', 'body' => $body, 'created_at' => now()]);
    }

    return $conversation;
}

it('aplica el cupón que el huésped mencionó en el chat aunque el bot no lo pase', function () {
    Coupon::query()->delete();
    Coupon::create(['code' => 'PACHEPACHE', 'kind' => 'percent', 'value' => 30, 'active' => true]);

    // Lo dijo al saludar y aparta mucho después: sigue contando.
    $conversation = couponConversation(
        'Hola buenas tardes vengo del video del pache pache',
        '¿Tienen alberca?',
        'Sale, apartame la cabaña',
    );

    $response = app(AgentToolsController::class)->storeHold(
        Request::create('/b', 'POST', couponParams(2, ['guest_name' => 'Orange', 'conversation_id' => $conversation->id])),
        app(CreateReservation::class),
    );
    $data = json_decode($response->getContent(), true);
    $reservation = Reservation::firstOrFail();

    expect($response->getStatusCode())->toBe(201)
        ->and($data['coupon_applied'])->toBe('PACHEPACHE')
        ->and($reservation->coupon_code)->toBe('PACHEPACHE')
        ->and((float) $reservation->discount_amount)->toBe(600.0)
        ->and((float) $reservation->total_amount)->toBe(1400.0);
});

it('si el cupón mencionado no aplica, aparta sin él y explica por qué', function () {
    Coupon::query()->delete();
    Coupon::create(['code' => 'PACHEPACHE', 'kind' => 'percent', 'value' => 30, 'min_nights' => 3, 'active' => true]);

    $conversation = couponConversation('vengo del video del pache pache, quiero una noche');

    $response = app(AgentToolsController::class)->storeHold(
        Request::create('/b', 'POST', couponParams(1, ['guest_name' => 'Orange', 'conversation_id' => $conversation->id])),
        app(CreateReservation::class),
    );
    $data = json_decode($response->getContent(), true);
    $reservation = Reservation::firstOrFail();

    expect($response->getStatusCode())->toBe(201)
        ->and($data['message'])->toContain('no se aplicó')
        ->and($data['message'])->toContain('al menos 3 noches')
        ->and($reservation->coupon_code)->toBeNull()
        ->and((float) $reservation->total_amount)->toBe(1000.0);
});

it('sin mención no hay descuento', function () {
    Coupon::query()->delete();
    Coupon::create(['code' => 'PACHEPACHE', 'kind' => 'percent', 'value' => 30, 'active' => true]);

    $conversation = couponConversation('Hola, quiero una cabaña para el fin');

    app(AgentToolsController::class)->storeHold(
        Request::create('/b', 'POST', couponParams(2, ['guest_name' => 'Sin cupón', 'conversation_id' => $conversation->id])),
        app(CreateReservation::class),
    );

    expect(Reservation::firstOrFail()->coupon_code)->toBeNull()
        ->and((float) Reservation::firstOrFail()->total_amount)->toBe(2000.0);
});

it('el aviso del cupón trae sus condiciones para decirlas antes de cotizar', function () {
    Coupon::query()->delete();
    Coupon::create([
        'code' => 'PACHEPACHE',
        'kind' => 'percent',
        'value' => 30,
        // Lunes a jueves, como la colaboración real.
        'weekdays' => [1, 2, 3, 4],
        'ends_at' => now()->addMonth(),
        'active' => true,
    ]);

    $conversation = couponConversation('vengo del video del pache pache');
    $brain = app(\App\Services\Agent\AgentBrain::class);

    expect((fn () => $this->couponBlock($conversation))->call($brain))
        ->toContain('PACHEPACHE')
        ->toContain('lunes, martes, miércoles y jueves')
        ->toContain('solo para estancias hasta el');
});

it('no aplica el cupón de lunes a jueves en una noche de domingo', function () {
    Coupon::query()->delete();
    Coupon::create(['code' => 'PACHEPACHE', 'kind' => 'percent', 'value' => 30, 'weekdays' => [1, 2, 3, 4], 'active' => true]);

    // Domingo 27 de septiembre de 2026, el caso que reportó el hotel.
    $domingo = \Illuminate\Support\Carbon::parse('2026-09-27 14:00');
    $conversation = couponConversation('vengo del video del pache pache');

    $response = app(AgentToolsController::class)->storeHold(
        Request::create('/b', 'POST', [
            'rate_plan_id' => $this->plan->id,
            'starts_at' => $domingo->format('Y-m-d H:i'),
            'ends_at' => $domingo->copy()->addDay()->setTime(11, 0)->format('Y-m-d H:i'),
            'guest_name' => 'Orange',
            'conversation_id' => $conversation->id,
        ]),
        app(CreateReservation::class),
    );
    $data = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(201)
        ->and($data['message'])->toContain('no se aplicó')
        ->and($data['message'])->toContain('lunes, martes, miércoles y jueves')
        ->and(Reservation::firstOrFail()->coupon_code)->toBeNull();
});

it('la vigencia acota las FECHAS DE LA ESTANCIA, no solo el día en que se aparta', function () {
    Coupon::query()->delete();
    // Caso real: cupón vigente hasta el 15/10 y el huésped pide el 20/10.
    Coupon::create([
        'code' => 'PACHEPACHE',
        'kind' => 'percent',
        'value' => 30,
        'ends_at' => '2026-10-15',
        'active' => true,
    ]);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-11 13:50'));

    $tools = app(AgentToolsController::class);

    $tarde = $tools->checkCoupon(Request::create('/b', 'POST', [
        'code' => 'pache pache', 'rate_plan_id' => $this->plan->id,
        'starts_at' => '2026-10-20 14:00', 'ends_at' => '2026-10-21 11:00',
    ]));
    expect($tarde->getStatusCode())->toBe(422)
        ->and(json_decode($tarde->getContent(), true)['message'])->toContain('hasta el 15/10/2026');

    // La última noche válida es la del 14 al 15: esa sí aplica.
    $aTiempo = $tools->checkCoupon(Request::create('/b', 'POST', [
        'code' => 'pache pache', 'rate_plan_id' => $this->plan->id,
        'starts_at' => '2026-10-14 14:00', 'ends_at' => '2026-10-15 11:00',
    ]));
    expect($aTiempo->getStatusCode())->toBe(200);

    // Y una estancia que se pasa de la vigencia a media estancia, tampoco.
    $cruza = $tools->checkCoupon(Request::create('/b', 'POST', [
        'code' => 'pache pache', 'rate_plan_id' => $this->plan->id,
        'starts_at' => '2026-10-15 14:00', 'ends_at' => '2026-10-17 11:00',
    ]));
    expect($cruza->getStatusCode())->toBe(422);
});

it('le da al bot el veredicto por fecha y corrige lo que prometa de más', function () {
    Coupon::query()->delete();
    Coupon::create([
        'code' => 'PACHEPACHE',
        'kind' => 'percent',
        'value' => 30,
        'weekdays' => [1, 2, 3, 4],
        'ends_at' => '2026-10-15',
        'active' => true,
    ]);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-11 13:55'));

    // Caso real: pide el 20 de octubre (martes, pero fuera de vigencia).
    $conversation = couponConversation(
        'Hola, vengo del video de pache pache para el 30%',
        'Me interesa la cabaña real para el 20 de octubre',
    );
    $brain = app(\App\Services\Agent\AgentBrain::class);

    $block = (fn () => $this->couponBlock($conversation))->call($brain);
    expect($block)->toContain('Para el 20/10/2026 el cupón NO aplica')
        ->and($block)->toContain('hasta el 15/10/2026');

    // Y si aun así lo promete, el mensaje sale con la corrección pegada.
    $prometido = 'Perfecto, el 20 de octubre es martes, así que aplica el 30% de descuento.';
    $corregido = (fn () => $this->enforceCouponClaims($prometido, $conversation))->call($brain);

    expect($corregido)->toContain('no aplica para el 20/10/2026')
        ->and($corregido)->toContain('hasta el 15/10/2026');

    // Una fecha buena (martes 13/10) no se toca.
    $bueno = 'Para el 13 de octubre aplica el 30% de descuento.';
    expect((fn () => $this->enforceCouponClaims($bueno, $conversation))->call($brain))->toBe($bueno);
});

it('no toca los mensajes cuando el huésped no mencionó ningún cupón', function () {
    Coupon::query()->delete();
    Coupon::create(['code' => 'PACHEPACHE', 'kind' => 'percent', 'value' => 30, 'active' => true]);

    $conversation = couponConversation('Hola, quiero una cabaña para el 20 de octubre');
    $brain = app(\App\Services\Agent\AgentBrain::class);
    $texto = 'La Cabaña Real cuesta $4,500 por noche para el 20 de octubre.';

    expect((fn () => $this->enforceCouponClaims($texto, $conversation))->call($brain))->toBe($texto)
        ->and((fn () => $this->couponBlock($conversation))->call($brain))->toBe('');
});

it('no aparta hasta que el huésped da correo y elige cómo paga, y manda el aviso legal', function () {
    // Ajustes del hotel (caso real cabañas 2026-09-11).
    $this->property->forceFill(['settings' => array_merge($this->property->settings ?? [], [
        'agent_require_email' => true,
        'agent_require_payment_choice' => true,
        'legal_notice_url' => 'https://cabanasrealdelasierra.com/aviso-legal/',
        'cash_payment_enabled' => true,
    ])])->save();
    $this->plan->update(['deposit_percent' => 50]);

    $tools = app(AgentToolsController::class);
    $base = couponParams(1, ['guest_name' => 'Efren Sosa']);

    // Sin correo: no aparta y pide correo + aviso legal.
    $sinCorreo = $tools->storeHold(Request::create('/b', 'POST', $base), app(CreateReservation::class));
    expect($sinCorreo->getStatusCode())->toBe(422)
        ->and(json_decode($sinCorreo->getContent(), true)['message'])->toContain('CORREO')
        ->and(json_decode($sinCorreo->getContent(), true)['message'])->toContain('aviso-legal')
        ->and(Reservation::count())->toBe(0);

    // Con correo pero sin elegir pago: tampoco aparta.
    $sinPago = $tools->storeHold(
        Request::create('/b', 'POST', [...$base, 'guest_email' => 'efren@correo.com']),
        app(CreateReservation::class),
    );
    expect($sinPago->getStatusCode())->toBe(422)
        ->and(json_decode($sinPago->getContent(), true)['message'])->toContain('cómo va a pagar')
        ->and(Reservation::count())->toBe(0);

    // Con las dos cosas: se aparta y el resultado trae el aviso legal.
    $ok = $tools->storeHold(
        Request::create('/b', 'POST', [...$base, 'guest_email' => 'efren@correo.com', 'metodo_pago' => 'transferencia']),
        app(CreateReservation::class),
    );
    $data = json_decode($ok->getContent(), true);

    expect($ok->getStatusCode())->toBe(201)
        ->and($data['legal_notice'])->toContain('https://cabanasrealdelasierra.com/aviso-legal/')
        ->and($data['legal_notice'])->toContain('confírmeme de leído')
        ->and(Reservation::count())->toBe(1)
        ->and(Reservation::firstOrFail()->guest?->email)->toBe('efren@correo.com');
});

it('un hotel sin esos ajustes aparta como siempre', function () {
    $tools = app(AgentToolsController::class);

    $response = $tools->storeHold(
        Request::create('/b', 'POST', couponParams(1, ['guest_name' => 'Sin requisitos'])),
        app(CreateReservation::class),
    );

    expect($response->getStatusCode())->toBe(201)
        ->and(Reservation::count())->toBe(1);
});

it('la promesa del descuento se borra y la verdad va primero', function () {
    // PACHEPACHE real de cabañas: 30% de lunes a jueves, hasta el 15/10.
    $cupon = \App\Models\Coupon::create([
        'property_id' => $this->property->id,
        'code' => 'PACHEPACHE',
        'kind' => 'percent',
        'value' => 30,
        'active' => true,
        'weekdays' => [1, 2, 3, 4],
        'starts_at' => now()->subDays(5),
        'ends_at' => now()->addDays(30),
    ]);

    $channel = \App\Models\Channel::firstOrCreate(
        ['property_id' => $this->property->id, 'type' => 'whatsapp', 'external_id' => null],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );
    $conversation = \App\Models\Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '5216560000000',
        'status' => \App\Models\Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);

    // El huésped pregunta por un SÁBADO, donde el cupón no aplica.
    $sabado = now()->next(\Carbon\CarbonInterface::SATURDAY);
    $fecha = $sabado->day.' de '.$sabado->locale('es')->isoFormat('MMMM');

    $conversation->messages()->create([
        'direction' => 'in',
        'sender_type' => 'visitor',
        'body' => "¿El cupón PACHEPACHE aplica para el {$fecha}?",
        'created_at' => now(),
    ]);

    $salida = (new ReflectionMethod(\App\Services\Agent\AgentBrain::class, 'enforceCouponClaims'))
        ->invoke(app(\App\Services\Agent\AgentBrain::class),
            "¡Claro! Con PACHEPACHE tienes 30% de descuento para el {$fecha}. La Cabaña Luxury quedaría en \$2,450.",
            $conversation);

    expect($salida)->toStartWith('El cupón PACHEPACHE no aplica')
        // La promesa ya no viaja en el mismo mensaje.
        ->and($salida)->not->toContain('30% de descuento')
        ->and($salida)->toContain('apartar sin el descuento')
        // Lo que no era promesa se conserva.
        ->and($salida)->toContain('Cabaña Luxury');

    expect($cupon->fresh()->code)->toBe('PACHEPACHE');
});
