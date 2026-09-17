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
    // Los archivos van por su propio carril en Request::create.
    $files = [];

    if (isset($params['receipt'])) {
        $files['receipt'] = $params['receipt'];
        unset($params['receipt']);
    }

    return app(ReservationController::class)->registerPayment(
        Request::create("/api/reservations/{$reservation->id}/payments", 'POST', $params, [], $files),
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

// --------------------- el comprobante, pegado al abono desde la ficha

it('registra el anticipo con el comprobante que se sube en la ficha', function () {
    \Illuminate\Support\Facades\Storage::fake('local');

    $reservation = reservaPendiente();

    $response = cobrarEnMostrador($reservation, [
        'amount' => 2250,
        'method' => 'transfer',
        // Sin folio: el respaldo es la foto.
        'receipt' => \Illuminate\Http\UploadedFile::fake()->image('spei.jpg'),
    ]);

    $pago = \App\Models\Payment::latest('id')->first();

    expect($response->getStatusCode())->toBe(200)
        ->and((float) $pago->amount)->toEqual(2250.0)
        ->and($pago->getFirstMedia('receipt'))->not->toBeNull()
        ->and($pago->receiptPayload()['is_image'])->toBeTrue();
});

it('usa el comprobante que ya había llegado por el chat', function () {
    \Illuminate\Support\Facades\Storage::fake('local');

    $reservation = reservaPendiente();
    $conversation = hiloDe($reservation->id);

    $mensaje = $conversation->messages()->create([
        'direction' => 'in',
        'sender_type' => 'visitor',
        'body' => '[Imagen]',
        'created_at' => now(),
    ]);
    $media = $mensaje->addMediaFromString(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='))
        ->usingFileName('spei-chat.png')
        ->toMediaCollection('attachments');

    cobrarEnMostrador($reservation, [
        'amount' => 2250,
        'method' => 'transfer',
        'receipt_media_id' => $media->id,
    ]);

    $pago = \App\Models\Payment::latest('id')->first();

    expect($pago->getFirstMedia('receipt')?->file_name)->toBe('spei-chat.png');
});

it('una transferencia sin folio ni comprobante no se registra', function () {
    $reservation = reservaPendiente();

    $response = cobrarEnMostrador($reservation, ['amount' => 1000, 'method' => 'transfer']);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['message'])->toContain('folio')
        ->and(\App\Models\Payment::count())->toBe(0);
});

it('no se puede pegar el comprobante de la conversación de otra reserva', function () {
    \Illuminate\Support\Facades\Storage::fake('local');

    $ajena = reservaPendiente();
    $otroHilo = hiloDe($ajena->id);
    $mensaje = $otroHilo->messages()->create([
        'direction' => 'in', 'sender_type' => 'visitor', 'body' => '[Imagen]', 'created_at' => now(),
    ]);
    $media = $mensaje->addMediaFromString('x')->usingFileName('ajeno.png')->toMediaCollection('attachments');

    $mia = reservaPendiente();

    $response = cobrarEnMostrador($mia, [
        'amount' => 500,
        'method' => 'transfer',
        'receipt_media_id' => $media->id,
    ]);

    // Sin folio y sin comprobante válido: no pasa.
    expect($response->getStatusCode())->toBe(422)
        ->and(\App\Models\Payment::count())->toBe(0);
});
