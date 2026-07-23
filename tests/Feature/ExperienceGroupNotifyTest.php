<?php

use App\Actions\Experiences\CreateExperienceBooking;
use App\Actions\Payments\IssueGroupPayment;
use App\Actions\Reservations\CreateGroupReservation;
use App\Mail\GuestNoticeMail;
use App\Models\Experience;
use App\Models\ExperienceBooking;
use App\Models\ExperienceSession;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Payments\PaymentGuestNotifier;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    $this->property = Property::factory()->create();
});

it('la confirmación de pago de una experiencia envía correo al huésped', function () {
    Mail::fake();

    $experience = Experience::factory()->create(['property_id' => $this->property->id, 'price' => 400]);
    $session = ExperienceSession::factory()->create(['experience_id' => $experience->id, 'capacity' => 10]);
    $booking = app(CreateExperienceBooking::class)->handle([
        'experience_session_id' => $session->id,
        'people' => 2,
        'guest_name' => 'Elliot Alderson',
        'guest_email' => 'elliot@example.com',
        'guest_phone' => '5511112233',
    ]);

    $request = PaymentRequest::create([
        'experience_booking_id' => $booking->id,
        'concept' => PaymentRequest::CONCEPT_FULL,
        'amount' => 800,
        'currency' => 'MXN',
        'method' => PaymentRequest::METHOD_TRANSFER,
        'mode' => 'live',
        'status' => PaymentRequest::STATUS_PAID,
    ]);

    app(PaymentGuestNotifier::class)->paymentReceived($request);

    Mail::assertSent(GuestNoticeMail::class, fn ($mail) => $mail->hasTo('elliot@example.com'));
});

it('la confirmación de pago de un grupo envía correo al responsable', function () {
    Mail::fake();

    $roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    Room::factory()->count(2)->create(['property_id' => $this->property->id, 'room_type_id' => $roomType->id]);
    RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $roomType->id, 'price' => 1000]);

    $group = app(CreateGroupReservation::class)->handle([
        'mode' => 'night',
        'starts_at' => now()->addDays(10)->setTime(15, 0),
        'ends_at' => now()->addDays(11)->setTime(12, 0),
        'guest_name' => 'Familia García',
        'guest_phone' => '5544332211',
        'guest_email' => 'familia@example.com',
        'confirmed' => false,
        'lines' => [['room_type_id' => $roomType->id, 'rooms' => 2, 'adults' => 2]],
    ]);

    $request = app(IssueGroupPayment::class)->handle($group);
    $request->update(['status' => PaymentRequest::STATUS_PAID]);

    app(PaymentGuestNotifier::class)->paymentReceived($request->refresh());

    Mail::assertSent(GuestNoticeMail::class, fn ($mail) => $mail->hasTo('familia@example.com'));
});

it('sin email del huésped no truena ni envía correo', function () {
    Mail::fake();

    $experience = Experience::factory()->create(['property_id' => $this->property->id, 'price' => 400]);
    $session = ExperienceSession::factory()->create(['experience_id' => $experience->id, 'capacity' => 10]);
    $booking = app(CreateExperienceBooking::class)->handle([
        'experience_session_id' => $session->id,
        'people' => 1,
        'guest_name' => 'Sin correo',
        'guest_phone' => '5511112233',
    ]);

    $request = PaymentRequest::create([
        'experience_booking_id' => $booking->id,
        'concept' => PaymentRequest::CONCEPT_FULL,
        'amount' => 400,
        'currency' => 'MXN',
        'method' => PaymentRequest::METHOD_TRANSFER,
        'mode' => 'live',
        'status' => PaymentRequest::STATUS_PAID,
    ]);

    app(PaymentGuestNotifier::class)->paymentReceived($request);

    Mail::assertNothingSent();
});

it('un recorrido plus de una reserva no se puede cobrar por separado', function () {
    $experience = Experience::factory()->create(['property_id' => $this->property->id, 'price' => 400]);
    $session = ExperienceSession::factory()->create(['experience_id' => $experience->id, 'capacity' => 10]);
    $roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $roomType->id]);
    $plan = RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $roomType->id, 'price' => 1000]);

    $reservation = app(\App\Actions\Reservations\CreateReservation::class)->handle([
        'rate_plan_id' => $plan->id,
        'room_id' => $room->id,
        'starts_at' => now()->addDays(10)->setTime(15, 0),
        'ends_at' => now()->addDays(11)->setTime(12, 0),
        'confirmed' => false,
        'guest_name' => 'Elliot',
        'guest_phone' => '5511112233',
        'experiences' => [['session_id' => $session->id, 'people' => 2]],
    ]);

    $booking = ExperienceBooking::where('reservation_id', $reservation->id)->first();

    $request = \Illuminate\Http\Request::create("/api/experience-bookings/{$booking->id}/payment-request", 'POST');
    $response = app(\App\Http\Controllers\Tenant\ExperienceBookingController::class)
        ->issuePayment($request, $booking, app(\App\Actions\Experiences\IssueExperiencePayment::class));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['message'])->toContain('se cobra junto');
});

it('el borrado masivo solo elimina experiencias canceladas o completadas', function () {
    $experience = Experience::factory()->create(['property_id' => $this->property->id, 'price' => 400]);
    $session = ExperienceSession::factory()->create(['experience_id' => $experience->id, 'capacity' => 30]);

    $make = fn (string $status) => tap(app(CreateExperienceBooking::class)->handle([
        'experience_session_id' => $session->id,
        'people' => 1,
        'guest_name' => $status,
        'guest_phone' => '5511112233',
    ]), fn ($b) => $b->update(['status' => $status]));

    $cancelled = $make(ExperienceBooking::STATUS_CANCELLED);
    $completed = $make(ExperienceBooking::STATUS_COMPLETED);
    $confirmed = $make(ExperienceBooking::STATUS_CONFIRMED);

    // Con una viva en la lista, se rechaza todo (no borra nada).
    $bad = \Illuminate\Http\Request::create('/api/experience-bookings', 'DELETE', [
        'ids' => [$cancelled->id, $confirmed->id],
    ]);
    $badResponse = app(\App\Http\Controllers\Tenant\ExperienceBookingController::class)->destroyBulk($bad);
    expect($badResponse->getStatusCode())->toBe(422)
        ->and(ExperienceBooking::count())->toBe(3);

    // Solo muertas: borra.
    $ok = \Illuminate\Http\Request::create('/api/experience-bookings', 'DELETE', [
        'ids' => [$cancelled->id, $completed->id],
    ]);
    $okResponse = app(\App\Http\Controllers\Tenant\ExperienceBookingController::class)->destroyBulk($ok);
    expect($okResponse->getData(true)['deleted'])->toBe(2)
        ->and(ExperienceBooking::whereKey($confirmed->id)->exists())->toBeTrue();
});
