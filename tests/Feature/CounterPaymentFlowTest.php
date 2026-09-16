<?php

use App\Actions\Payments\IssuePaymentRequest;
use App\Actions\Reservations\CreateGroupReservation;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\RegisterReservationPayment;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\GroupReservationController;
use App\Http\Controllers\Tenant\ReservationController;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Request;

/**
 * El dinero que entra por mostrador valía menos que el verificado en /pagos:
 * la reserva se quedaba "pendiente" hasta que el barrido la confirmaba al
 * vencer el plazo, el cobro por transferencia seguía vivo por el mismo dinero
 * (y se podía aprobar otra vez) y el huésped no se enteraba de nada.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 4]);
    Room::factory()->count(2)->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 3000,
        'deposit_percent' => 50,
    ]);
});

function reservaPendiente(): Reservation
{
    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'starts_at' => now()->addDays(3)->setTime(14, 0),
        'ends_at' => now()->addDays(4)->setTime(11, 0),
        'confirmed' => false,
        'source_channel' => 'agent',
        'guest_name' => 'Kevin Andrés',
        'guest_phone' => '6565280146',
    ]);
}

function hiloDe(int $reservationId): Conversation
{
    $channel = Channel::firstOrCreate(
        ['property_id' => test()->property->id, 'type' => Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );

    return Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '5216565280146',
        'reservation_id' => $reservationId,
        'status' => Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);
}

function cobrarEnMostrador(Reservation $reservation, array $params): \Illuminate\Http\JsonResponse
{
    return app(ReservationController::class)->registerPayment(
        Request::create("/api/reservations/{$reservation->id}/payments", 'POST', $params),
        $reservation,
        app(RegisterReservationPayment::class),
    );
}

it('el anticipo en efectivo confirma la reserva y cierra el cobro por transferencia vivo', function () {
    $reservation = reservaPendiente();
    $request = app(IssuePaymentRequest::class)->handle($reservation);
    $conversation = hiloDe($reservation->id);

    $response = cobrarEnMostrador($reservation, ['amount' => 1500, 'method' => 'cash']);

    expect($response->getStatusCode())->toBe(200)
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->payment_status)->toBe(PaymentStatus::DepositPaid)
        // Nunca dos vías abiertas por el mismo dinero.
        ->and($request->refresh()->status)->toBe(PaymentRequest::STATUS_CANCELED)
        ->and($request->meta['superseded_by_payment_id'])->not->toBeNull()
        // Y el huésped recibe su comprobante por el mismo hilo.
        ->and($conversation->messages()->where('sender_type', 'system')->value('body'))
        ->toContain('Recibimos tu pago de $1,500.00 en efectivo')
        ->toContain('está confirmada')
        ->toContain('Saldo pendiente: $1,500.00');
});

it('liquidar deja la reserva sin saldo y lo dice', function () {
    $reservation = reservaPendiente();
    $conversation = hiloDe($reservation->id);

    cobrarEnMostrador($reservation, ['amount' => 3000, 'method' => 'card']);

    expect($reservation->refresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($conversation->messages()->where('sender_type', 'system')->value('body'))
        ->toContain('con tarjeta')
        ->toContain('Tu reserva quedó liquidada');
});

it('con el aviso apagado el huésped no recibe nada', function () {
    $reservation = reservaPendiente();
    $conversation = hiloDe($reservation->id);

    cobrarEnMostrador($reservation, ['amount' => 1500, 'method' => 'cash', 'notify_guest' => false]);

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and($conversation->messages()->where('sender_type', 'system')->count())->toBe(0);
});

it('el pago de un grupo avisa UNA vez, con el folio GRP-', function () {
    $group = app(CreateGroupReservation::class)->handle([
        'mode' => 'night',
        'starts_at' => now()->addDays(3)->setTime(14, 0),
        'ends_at' => now()->addDays(4)->setTime(11, 0),
        'guest_name' => 'Kevin Andrés',
        'lines' => [['room_type_id' => $this->roomType->id, 'rooms' => 2, 'adults' => 4]],
    ]);

    $conversation = hiloDe($group->reservations()->first()->id);

    app(GroupReservationController::class)->registerPayment(
        Request::create("/api/grupos/{$group->id}/payments", 'POST', ['amount' => 3000, 'method' => 'cash']),
        $group,
        app(RegisterReservationPayment::class),
    );

    $avisos = $conversation->messages()->where('sender_type', 'system')->get();

    expect($avisos)->toHaveCount(1)
        ->and($avisos->first()->body)
        ->toContain('Recibimos tu pago de $3,000.00 en efectivo')
        ->toContain($group->displayCode())
        ->toContain('Saldo pendiente: $3,000.00')
        ->and($group->reservations()->where('status', ReservationStatus::Confirmed)->count())->toBe(2);
});
