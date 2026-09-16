<?php

use App\Actions\Reservations\CreateReservation;
use App\Http\Controllers\Agent\AgentToolsController;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use App\Services\ReservationPolicy;
use Illuminate\Http\Request;

// Casos reales cabañas 2026-09-11: el bot duplicaba apartados al cambiar de
// forma de pago (Marcus Fenix) y cotizaba una cabaña ocupada con el precio
// de otra (Karely). Además, el hotel solo recibe transferencias de 9 a 5.

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Cabaña Luxury']);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 3500,
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

function guardHoldParams(): array
{
    return [
        'rate_plan_id' => test()->plan->id,
        'starts_at' => now()->addDays(5)->format('Y-m-d').' 14:00',
        'ends_at' => now()->addDays(6)->format('Y-m-d').' 11:00',
        'guest_name' => 'Marcus Fenix',
        'conversation_id' => test()->conversation->id,
    ];
}

it('crear_apartado devuelve el MISMO apartado si la conversación ya lo tiene', function () {
    $tools = app(AgentToolsController::class);

    $first = json_decode($tools->storeHold(Request::create('/b', 'POST', guardHoldParams()), app(CreateReservation::class))->getContent(), true);
    $this->conversation->update(['reservation_id' => Reservation::where('code', $first['code'])->value('id')]);

    // El huésped cambia de forma de pago y el bot vuelve a llamar la herramienta.
    $response = $tools->storeHold(Request::create('/b', 'POST', guardHoldParams()), app(CreateReservation::class));
    $second = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(200)
        ->and($second['code'])->toBe($first['code'])
        ->and($second['already_held'])->toBeTrue()
        ->and(Reservation::count())->toBe(1);
});

it('la cotización dice qué cabaña es y si está libre, pegada a su precio', function () {
    $tools = app(AgentToolsController::class);
    $params = ['rate_plan_id' => $this->plan->id, 'starts_at' => now()->addDays(5)->format('Y-m-d'), 'ends_at' => now()->addDays(6)->format('Y-m-d')];

    $free = json_decode($tools->availability(Request::create('/b', 'POST', $params), app(AvailabilityService::class))->getContent(), true);
    expect($free['room_type'])->toBe('Cabaña Luxury')
        ->and($free['quote_notice'][0])->toContain('Cabaña Luxury')
        ->and($free['quote_notice'][0])->toContain('disponible, total $3,500.00');

    // Ocupada: la primera línea lo dice y no trae precio que copiar.
    app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'starts_at' => $free['starts_at'],
        'ends_at' => $free['ends_at'],
        'guest_name' => 'Otro',
        'confirmed' => true,
    ]);

    $busy = json_decode($tools->availability(Request::create('/b', 'POST', $params), app(AvailabilityService::class))->getContent(), true);
    expect($busy['available'])->toBeFalse()
        ->and($busy['quote_notice'][0])->toContain('NO está disponible')
        ->and(implode(' ', $busy['quote_notice']))->not->toContain('3,500');
});

it('las transferencias respetan el horario del hotel', function () {
    $this->property->forceFill(['settings' => array_merge($this->property->settings ?? [], [
        'transfer_hours_enabled' => true,
        'transfer_hours_open' => '09:00',
        'transfer_hours_close' => '17:00',
    ])])->save();

    $this->travelTo(now()->setTime(18, 30));
    expect((new ReservationPolicy)->transferOpenNow())->toBeFalse();

    $this->travelTo(now()->setTime(10, 0));
    expect((new ReservationPolicy)->transferOpenNow())->toBeTrue()
        ->and((new ReservationPolicy)->transferHoursLabel())->toBe('de 9:00 AM a 5:00 PM');
});

it('sin horario configurado la transferencia se recibe siempre', function () {
    $this->travelTo(now()->setTime(23, 0));

    expect((new ReservationPolicy)->transferOpenNow())->toBeTrue()
        ->and((new ReservationPolicy)->transferHoursLabel())->toBeNull();
});

// Caso real cabañas 2026-09-13 (RES-2026-1727): el apartado vencía a las 8:50
// PM y el bot le dijo al huésped que transfiriera "mañana". El hotel decidió
// NO sostener apartados de noche: lo que corrige es decir la hora real.

it('dice hasta cuándo sigue apartada en palabras del huésped', function () {
    $this->travelTo(now()->setTime(18, 0));

    $policy = new ReservationPolicy;

    expect($policy->holdDeadlineLabel(now()->setTime(20, 50)))->toBe('hoy a las 8:50 PM')
        ->and($policy->holdDeadlineLabel(now()->addDay()->setTime(10, 0)))->toBe('mañana a las 10:00 AM');
});

it('fuera del horario de transferencias no deja que el bot prometa pagar mañana', function () {
    $this->property->update(['settings' => array_replace($this->property->settings ?? [], [
        'transfer_hours_enabled' => true,
        'transfer_hours_open' => '09:00',
        'transfer_hours_close' => '17:00',
        'bank_accounts' => [['bank' => 'BBVA', 'holder' => 'Hotel', 'clabe' => '0123', 'active' => true]],
    ])]);

    $this->travelTo(now()->setTime(18, 30));

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'starts_at' => now()->addDays(10)->format('Y-m-d').' 14:00',
        'ends_at' => now()->addDays(11)->format('Y-m-d').' 11:00',
        'guest_name' => 'Elliot Alderson',
        'confirmed' => false,
    ]);

    $response = app(AgentToolsController::class)->requestPayment(
        Request::create('/agent/payment-requests', 'POST', ['code' => $reservation->refresh()->displayCode(), 'metodo' => 'transferencia']),
        app(\App\Actions\Payments\IssuePaymentRequest::class),
    );
    $message = json_decode($response->getContent(), true)['message'];

    expect($response->getStatusCode())->toBe(422)
        ->and($message)->toContain('NO le prometas que puede transferir mañana')
        ->and($message)->toContain('queda guardado hasta hoy a las')
        ->and($message)->toContain('lo reactivo con el mismo código');
});
