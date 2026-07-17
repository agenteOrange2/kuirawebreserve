<?php

use App\Actions\Experiences\CreateExperienceBooking;
use App\Exceptions\NoAvailabilityException;
use App\Models\Experience;
use App\Models\ExperienceBooking;
use App\Models\ExperienceSession;
use App\Models\Property;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->experience = Experience::factory()->create([
        'property_id' => $this->property->id,
        'pricing_mode' => 'per_person',
        'price' => 300,
        'min_people' => 2,
        'max_people' => 8,
    ]);
    $this->session = ExperienceSession::factory()->create([
        'experience_id' => $this->experience->id,
        'capacity' => 10,
    ]);
});

function bookExperience(array $overrides = []): ExperienceBooking
{
    return app(CreateExperienceBooking::class)->handle([
        'experience_session_id' => test()->session->id,
        'people' => 4,
        'guest_name' => 'Elliot Alderson',
        'guest_phone' => '5511112233',
        ...$overrides,
    ]);
}

it('reserva con total por persona congelado y folio EXP', function () {
    $booking = bookExperience();

    expect((float) $booking->total)->toBe(1200.0) // 4 x 300
        ->and($booking->status)->toBe(ExperienceBooking::STATUS_PENDING)
        ->and($booking->code)->toStartWith('EXP-'.now()->year)
        ->and($booking->guest_id)->not->toBeNull(); // alimenta el CRM
});

it('precio por grupo: el total no cambia con las personas', function () {
    $this->experience->update(['pricing_mode' => 'flat', 'price' => 800]);

    $booking = bookExperience(['people' => 6]);

    expect((float) $booking->total)->toBe(800.0);
});

it('hace cumplir el minimo y maximo de personas por reserva', function () {
    expect(fn () => bookExperience(['people' => 1]))->toThrow(InvalidArgumentException::class, 'mínimo')
        ->and(fn () => bookExperience(['people' => 9]))->toThrow(InvalidArgumentException::class, 'máximo');
});

it('el cupo es duro: no se sobrevende la sesion', function () {
    bookExperience(['people' => 4]);
    bookExperience(['people' => 4]); // 8 de 10

    expect(fn () => bookExperience(['people' => 3]))->toThrow(NoAvailabilityException::class);
});

it('las reservas canceladas liberan cupo', function () {
    $first = bookExperience(['people' => 8]);
    expect(fn () => bookExperience(['people' => 4]))->toThrow(NoAvailabilityException::class);

    $first->update(['status' => ExperienceBooking::STATUS_CANCELLED]);

    $second = bookExperience(['people' => 4]);
    expect($second->status)->toBe(ExperienceBooking::STATUS_PENDING);
});

it('no se reserva en sesiones canceladas ni pasadas', function () {
    $this->session->update(['status' => ExperienceSession::STATUS_CANCELLED]);
    expect(fn () => bookExperience())->toThrow(InvalidArgumentException::class);

    $this->session->update(['status' => ExperienceSession::STATUS_SCHEDULED, 'starts_at' => now()->subHour()]);
    expect(fn () => bookExperience())->toThrow(InvalidArgumentException::class);
});
