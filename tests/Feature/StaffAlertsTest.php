<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\RegisterReservationPayment;
use App\Actions\Reservations\TransitionReservation;
use App\Events\RoomStatusChanged;
use App\Jobs\SendStaffNoticeMail;
use App\Mail\StaffNoticeMail;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\StaffNotification;
use App\Models\Stay;
use App\Services\StaffAlerts;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/**
 * Avisos al HOTEL por correo (2026-09-29). Hasta entonces el dueño de
 * cabañas solo se enteraba por la campana, y solo de reservas que no
 * entraban por mostrador: ni pagos, ni cancelaciones, ni salidas.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);
    Mail::fake();

    $this->property = Property::factory()->create([
        'settings' => ['staff_notice_emails' => ['dueno@hotel.test', 'socio@hotel.test']],
    ]);
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id, 'number' => '104']);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1000,
        'deposit_percent' => 50,
    ]);
});

function alertReservation(array $overrides = []): Reservation
{
    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDays(10)->setTime(15, 0),
        'ends_at' => now()->addDays(11)->setTime(11, 0),
        'guest_name' => 'Adrian Orozco',
        ...$overrides,
    ]);
}

function sentStaffMails(): \Illuminate\Support\Collection
{
    return Mail::sent(StaffNoticeMail::class);
}

it('una reserva nueva del asistente avisa por correo a todos, con el canal real', function () {
    $channel = Channel::create([
        'property_id' => $this->property->id,
        'type' => 'whatsapp',
        'external_id' => '1',
        'name' => 'WhatsApp',
        'mode' => 'auto',
        'active' => true,
    ]);

    $reservation = alertReservation(['source_channel' => 'agent']);
    Conversation::create([
        'channel_id' => $channel->id,
        'reservation_id' => $reservation->id,
        'contact_name' => 'Adrian',
        'status' => Conversation::STATUS_OPEN,
        'bot_enabled' => true,
        'last_message_at' => now(),
    ]);

    // En producción el job sale 30 s después, ya con la conversación ligada.
    (new SendStaffNoticeMail(StaffAlerts::EVENT_RESERVATION_NEW, ['reservation_id' => $reservation->id]))
        ->handle(app(StaffAlerts::class), app(\App\Services\TenantMailer::class));

    $mail = sentStaffMails()->last();

    expect($mail)->not->toBeNull()
        ->and($mail->hasTo('dueno@hotel.test'))->toBeTrue()
        ->and($mail->hasTo('socio@hotel.test'))->toBeTrue()
        ->and($mail->lines['Canal'])->toBe('Asistente IA · WhatsApp')
        ->and($mail->lines['Habitación'])->toContain('104');
});

it('la de mostrador llega por correo pero no suena la campana', function () {
    $reservation = alertReservation(['source_channel' => 'front_desk', 'confirmed' => true]);

    expect(sentStaffMails())->toHaveCount(1)
        ->and(sentStaffMails()->first()->lines['Canal'])->toBe('Mostrador')
        ->and(StaffNotification::where('subject_id', $reservation->id)->where('type', StaffNotification::TYPE_RESERVATION)->exists())->toBeFalse();
});

it('un evento apagado o sin correos no manda nada', function () {
    $this->property->update(['settings' => [
        'staff_notice_emails' => ['dueno@hotel.test'],
        'staff_notice_events' => ['reservation_new' => false],
    ]]);

    alertReservation(['source_channel' => 'web']);
    expect(sentStaffMails())->toHaveCount(0);

    $this->property->update(['settings' => ['staff_notice_emails' => []]]);
    alertReservation(['source_channel' => 'web', 'starts_at' => now()->addDays(20)->setTime(15, 0), 'ends_at' => now()->addDays(21)->setTime(11, 0)]);
    expect(sentStaffMails())->toHaveCount(0);
});

it('un correo que falla no rompe la reserva', function () {
    $this->mock(\App\Services\TenantMailer::class, function ($mock) {
        $mock->shouldReceive('mailer')->andThrow(new RuntimeException('SMTP caído'));
    });

    $reservation = alertReservation(['source_channel' => 'web']);

    expect($reservation->exists)->toBeTrue();
});

it('un pago avisa una vez con monto y saldo', function () {
    $reservation = alertReservation(['source_channel' => 'web']);
    Mail::fake();

    app(RegisterReservationPayment::class)->handle($reservation, ['amount' => 500, 'method' => 'cash', 'reference' => 'A-1']);

    $mails = sentStaffMails();

    expect($mails)->toHaveCount(1)
        ->and($mails->first()->lines['Monto'])->toBe('$500.00')
        ->and($mails->first()->lines['Saldo'])->toBe('$500.00')
        ->and($mails->first()->lines['Método'])->toBe('Efectivo');
});

it('cancelar avisa con el motivo y lo que ya se había pagado', function () {
    $reservation = alertReservation(['source_channel' => 'web', 'confirmed' => true]);
    app(RegisterReservationPayment::class)->handle($reservation, ['amount' => 500, 'method' => 'cash']);
    Mail::fake();

    app(TransitionReservation::class)->cancel($reservation->refresh(), null, reason: 'El huésped ya no viene');

    $mail = sentStaffMails()->first();

    expect(sentStaffMails())->toHaveCount(1)
        ->and($mail->lines['Motivo'])->toBe('El huésped ya no viene')
        ->and($mail->intro)->toContain('$500.00 pagado');
});

it('la hora de salida avisa una vez por estancia y no antes', function () {
    $due = Stay::create([
        'room_id' => $this->room->id,
        'rate_plan_id' => $this->plan->id,
        'guest_name' => 'Adrian Orozco',
        'check_in_at' => now()->subDay(),
        'planned_end_at' => now()->subMinutes(3),
        'status' => Stay::STATUS_ACTIVE,
        'amount' => 1000,
        'channel' => 'panel',
    ]);
    $later = Stay::create([
        'room_id' => Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id])->id,
        'rate_plan_id' => $this->plan->id,
        'guest_name' => 'Otra Persona',
        'check_in_at' => now()->subDay(),
        'planned_end_at' => now()->addHours(3),
        'status' => Stay::STATUS_ACTIVE,
        'amount' => 1000,
        'channel' => 'panel',
    ]);

    $this->artisan('stays:checkout-alerts')->assertSuccessful();
    $this->artisan('stays:checkout-alerts')->assertSuccessful();
    $this->artisan('stays:checkout-alerts')->assertSuccessful();

    expect(sentStaffMails())->toHaveCount(1)
        ->and(sentStaffMails()->first()->lines['Habitación'])->toBe('104')
        ->and(StaffNotification::where('subject_id', $due->id)->where('subject_type', $due->getMorphClass())->count())->toBe(1)
        ->and(StaffNotification::where('subject_id', $later->id)->where('subject_type', $later->getMorphClass())->exists())->toBeFalse();
});

it('un pago por pasarela avisa una vez aunque el webhook llegue repetido', function () {
    $reservation = alertReservation(['source_channel' => 'web']);
    $request = \App\Models\PaymentRequest::create([
        'reservation_id' => $reservation->id,
        'method' => \App\Models\PaymentRequest::METHOD_GATEWAY,
        'status' => \App\Models\PaymentRequest::STATUS_PENDING,
        'concept' => \App\Models\PaymentRequest::CONCEPT_DEPOSIT,
        'amount' => 500,
    ]);
    Mail::fake();

    app(\App\Actions\Payments\RegisterGatewayPayment::class)->handle($request, ['gateway_ref' => 'mp-1']);
    app(\App\Actions\Payments\RegisterGatewayPayment::class)->handle($request->refresh(), ['gateway_ref' => 'mp-1']);

    expect(sentStaffMails())->toHaveCount(1)
        ->and(sentStaffMails()->first()->lines['Método'])->toBe('Pago en línea');
});
