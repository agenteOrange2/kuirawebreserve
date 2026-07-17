<?php

use App\Actions\Experiences\CreateExperienceBooking;
use App\Actions\Experiences\GenerateExperienceSessions;
use App\Actions\Payments\IssueGroupPayment;
use App\Actions\Reservations\CreateGroupReservation;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\TransitionReservation;
use App\Exceptions\NoAvailabilityException;
use App\Models\Experience;
use App\Models\ExperienceBooking;
use App\Models\ExperienceSession;
use App\Models\ExperienceSlot;
use App\Models\ExperienceVehicle;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
});

// ── Programación semanal → sesiones materializadas ──

it('materializa sesiones según días de operación, horarios y vehículos', function () {
    $experience = Experience::factory()->create([
        'property_id' => $this->property->id,
        'operating_days' => [5, 6, 7], // vie-dom
    ]);
    $razer = ExperienceVehicle::factory()->create(['property_id' => $this->property->id, 'capacity' => 4]);
    $camioneta = ExperienceVehicle::factory()->create(['property_id' => $this->property->id, 'capacity' => 6]);
    ExperienceSlot::factory()->create([
        'experience_id' => $experience->id,
        'start_time' => '10:00',
        'vehicle_ids' => [$razer->id, $camioneta->id],
        'capacity' => null, // cupo = suma de vehículos
    ]);

    $stats = app(GenerateExperienceSessions::class)->handle();

    $sessions = $experience->sessions()->get();

    expect($stats['created'])->toBeGreaterThan(0)
        ->and($sessions)->not->toBeEmpty();

    foreach ($sessions as $session) {
        expect(in_array($session->starts_at->isoWeekday(), [5, 6, 7], true))->toBeTrue()
            ->and($session->starts_at->format('H:i'))->toBe('10:00')
            ->and($session->capacity)->toBe(10) // 4 + 6
            ->and($session->experience_slot_id)->not->toBeNull()
            ->and($session->starts_at->isFuture())->toBeTrue();
    }

    // Idempotente: volver a correr no duplica nada.
    $again = app(GenerateExperienceSessions::class)->handle();
    expect($again['created'])->toBe(0)
        ->and($experience->sessions()->count())->toBe($sessions->count());
});

it('el override de cupo manda sobre los vehículos y la flota actualiza sesiones futuras sin bajar de lo vendido', function () {
    $experience = Experience::factory()->create([
        'property_id' => $this->property->id,
        'operating_days' => [1, 2, 3, 4, 5, 6, 7],
        'price' => 100,
    ]);
    $razer = ExperienceVehicle::factory()->create(['property_id' => $this->property->id, 'capacity' => 4]);
    $camioneta = ExperienceVehicle::factory()->create(['property_id' => $this->property->id, 'capacity' => 6]);
    $slot = ExperienceSlot::factory()->create([
        'experience_id' => $experience->id,
        'start_time' => '16:00',
        'vehicle_ids' => [$razer->id, $camioneta->id],
        'capacity' => 8, // override manual
    ]);

    app(GenerateExperienceSessions::class)->handle();
    expect($experience->sessions()->first()->capacity)->toBe(8);

    // Sin override: el cupo vuelve a ser la suma de vehículos vivos.
    $slot->update(['capacity' => null]);
    app(GenerateExperienceSessions::class)->handle();
    expect($experience->sessions()->first()->capacity)->toBe(10);

    // Se venden 6 lugares en una sesión y se descompone la camioneta (6):
    // la capacidad nueva sería 4, pero nunca baja de lo ya vendido.
    $session = $experience->sessions()->orderBy('starts_at')->first();
    app(CreateExperienceBooking::class)->handle([
        'experience_session_id' => $session->id,
        'people' => 6,
        'guest_name' => 'Grupo grande',
        'confirmed' => true,
    ]);
    $camioneta->update(['active' => false]);

    app(GenerateExperienceSessions::class)->handle();

    expect($session->fresh()->capacity)->toBe(6) // clavado en lo vendido
        ->and($experience->sessions()->orderByDesc('starts_at')->first()->capacity)->toBe(4); // las vacías sí bajan
});

it('poda sesiones futuras de días quitados solo si nadie ha reservado', function () {
    $experience = Experience::factory()->create([
        'property_id' => $this->property->id,
        'operating_days' => [1, 2, 3, 4, 5, 6, 7],
        'price' => 100,
    ]);
    ExperienceSlot::factory()->create(['experience_id' => $experience->id, 'start_time' => '12:00', 'capacity' => 10]);

    app(GenerateExperienceSessions::class)->handle();
    $before = $experience->sessions()->count();

    // Una sesión de lunes ya tiene gente; los lunes salen de la programación.
    $mondaySession = $experience->sessions()->get()->first(fn ($s) => $s->starts_at->isoWeekday() === 1);
    app(CreateExperienceBooking::class)->handle([
        'experience_session_id' => $mondaySession->id,
        'people' => 2,
        'guest_name' => 'Ya reservado',
        'confirmed' => true,
    ]);

    $experience->update(['operating_days' => [2, 3, 4, 5, 6, 7]]);
    app(GenerateExperienceSessions::class)->handle($experience->fresh());

    $mondays = $experience->sessions()->get()->filter(fn ($s) => $s->starts_at->isoWeekday() === 1);

    expect($experience->sessions()->count())->toBeLessThan($before)
        ->and($mondays)->toHaveCount(1) // solo sobrevive la vendida
        ->and($mondays->first()->id)->toBe($mondaySession->id);
});

// ── Experiencias como plus de una reserva de habitación ──

function experienceSetupForReservation(): array
{
    $roomType = RoomType::factory()->create(['property_id' => test()->property->id]);
    $room = Room::factory()->create(['property_id' => test()->property->id, 'room_type_id' => $roomType->id]);
    $plan = RatePlan::factory()->create([
        'property_id' => test()->property->id,
        'room_type_id' => $roomType->id,
        'price' => 1000,
    ]);
    $experience = Experience::factory()->create([
        'property_id' => test()->property->id,
        'pricing_mode' => 'per_person',
        'price' => 450,
    ]);
    $session = ExperienceSession::factory()->create([
        'experience_id' => $experience->id,
        'starts_at' => now()->addDays(10)->setTime(17, 0),
        'capacity' => 10,
    ]);

    return [$plan, $room, $session];
}

it('la experiencia elegida suma al total, queda congelada y nace la reserva EXP- ligada', function () {
    [$plan, $room, $session] = experienceSetupForReservation();

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $plan->id,
        'room_id' => $room->id,
        'starts_at' => now()->addDays(10)->setTime(15, 0),
        'ends_at' => now()->addDays(11)->setTime(12, 0),
        'confirmed' => false,
        'guest_name' => 'Elliot Alderson',
        'guest_phone' => '5511112233',
        'experiences' => [['session_id' => $session->id, 'people' => 2]],
    ]);

    // 1000 (noche) + 900 (2 x 450)
    expect((float) $reservation->total_amount)->toBe(1900.0)
        ->and($reservation->experiences)->toHaveCount(1)
        ->and($reservation->experiences[0]['people'])->toBe(2)
        ->and((float) $reservation->experiences[0]['total'])->toBe(900.0);

    $booking = ExperienceBooking::find($reservation->experiences[0]['experience_booking_id']);

    expect($booking->reservation_id)->toBe($reservation->id)
        ->and($booking->status)->toBe(ExperienceBooking::STATUS_PENDING)
        ->and((float) $booking->total)->toBe(900.0);

    // Confirmar la reserva confirma el tour; cancelarla libera su cupo.
    app(TransitionReservation::class)->confirm($reservation, notifyGuest: false);
    expect($booking->fresh()->status)->toBe(ExperienceBooking::STATUS_CONFIRMED);

    app(TransitionReservation::class)->cancel($reservation->fresh());
    expect($booking->fresh()->status)->toBe(ExperienceBooking::STATUS_CANCELLED);
});

it('sin cupo en el tour revienta la reserva completa: tampoco se aparta la habitación', function () {
    [$plan, $room, $session] = experienceSetupForReservation();
    $session->update(['capacity' => 2]);

    expect(fn () => app(CreateReservation::class)->handle([
        'rate_plan_id' => $plan->id,
        'room_id' => $room->id,
        'starts_at' => now()->addDays(10)->setTime(15, 0),
        'ends_at' => now()->addDays(11)->setTime(12, 0),
        'confirmed' => false,
        'guest_name' => 'Grupo grande',
        'guest_phone' => '5599887766',
        'experiences' => [['session_id' => $session->id, 'people' => 5]],
    ]))->toThrow(NoAvailabilityException::class);

    expect(Reservation::count())->toBe(0)
        ->and(ExperienceBooking::count())->toBe(0);
});

// ── Experiencias colgadas de un grupo (GRP-) ──

it('el grupo suma sus experiencias al total y el cobro consolidado trae su reparto', function () {
    $roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    Room::factory()->count(2)->create(['property_id' => $this->property->id, 'room_type_id' => $roomType->id]);
    RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $roomType->id,
        'price' => 1000,
        'deposit_percent' => 20,
    ]);
    $experience = Experience::factory()->create([
        'property_id' => $this->property->id,
        'pricing_mode' => 'flat',
        'price' => 1500,
    ]);
    $session = ExperienceSession::factory()->create([
        'experience_id' => $experience->id,
        'starts_at' => now()->addDays(10)->setTime(17, 0),
        'capacity' => 12,
    ]);

    $group = app(CreateGroupReservation::class)->handle([
        'mode' => 'night',
        'starts_at' => now()->addDays(10)->setTime(15, 0),
        'ends_at' => now()->addDays(11)->setTime(12, 0),
        'guest_name' => 'Familia García',
        'guest_phone' => '5544332211',
        'confirmed' => false,
        'lines' => [['room_type_id' => $roomType->id, 'rooms' => 2, 'adults' => 2]],
        'experiences' => [['session_id' => $session->id, 'people' => 8]],
    ]);

    $booking = $group->experienceBookings()->first();

    // 2 x 1000 (cuartos) + 1500 (tour por grupo, precio flat).
    expect($group->totalAmount())->toBe(3500.0)
        ->and($booking->reservation_group_id)->toBe($group->id)
        ->and($booking->reservation_id)->toBeNull()
        ->and((float) $booking->total)->toBe(1500.0);

    // Cobro consolidado: anticipos de cuartos (2 x 200) + tour completo.
    $request = app(IssueGroupPayment::class)->handle($group);

    expect((float) $request->amount)->toBe(1900.0)
        ->and($request->meta['breakdown'])->toHaveCount(2)
        ->and($request->meta['experience_breakdown'])->toHaveCount(1)
        ->and((float) array_sum($request->meta['experience_breakdown']))->toBe(1500.0);
});
