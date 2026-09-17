<?php

use App\Actions\Reservations\CreateReservation;
use App\Mail\GuestReservationMail;
use App\Models\Guest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Guests\ReservationContract;

// El bot y la FAQ prometían "te enviamos el contrato digital" y el sistema no
// mandaba ninguno: el aviso legal del hotel (que ES un contrato de hospedaje)
// vivía solo como liga. Casos reales cabañas: Montserrat 2026-09-13 ("no
// recibí el contrato al correo") y Damaris 2026-09-15, que preguntó dos veces.

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create(['name' => 'Cabañas Real de la Sierra']);
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Cabaña Escondida']);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 3000,
        'deposit_percent' => 50,
    ]);
});

function reservaConHuesped(): \App\Models\Reservation
{
    $guest = Guest::create([
        'first_name' => 'Damaris Michelle Emiliano Crispín',
        'phone' => '5216562025344',
        'email' => 'damaris.emiliano@icloud.com',
    ]);

    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'guest_id' => $guest->id,
        'starts_at' => now()->addDays(5)->setTime(14, 0),
        'ends_at' => now()->addDays(6)->setTime(11, 0),
        'confirmed' => true,
    ]);
}

function conContrato(): void
{
    test()->property->update(['settings' => array_merge(test()->property->settings ?? [], [
        'contract_text' => "## Seguridad\n- Prohibido fumar dentro de las cabañas.\n- Menores no pueden ingerir bebidas alcohólicas.\n## Orden\n- Cancelación con al menos 20 días de anticipación. Multa del 30%.\n- Depósito de \$1,500 por cabaña al ingreso, reembolsable en 24 horas tras salida.",
    ])]);
}

it('sin contrato capturado no se adjunta nada', function () {
    $reservation = reservaConHuesped();

    expect(app(ReservationContract::class)->available())->toBeFalse()
        ->and(app(ReservationContract::class)->pdf($reservation))->toBeNull()
        ->and((new GuestReservationMail($reservation, 'Confirmada', 'Reserva confirmada', true, true))->attachments())
        // Solo el calendario: el contrato no existe para este hotel.
        ->toHaveCount(1);
});

it('con el contrato del hotel genera el PDF de esa reserva', function () {
    conContrato();
    $reservation = reservaConHuesped();

    $contrato = app(ReservationContract::class);
    $pdf = $contrato->pdf($reservation);

    expect($contrato->available())->toBeTrue()
        ->and($pdf)->toBeString()
        // Un PDF de verdad empieza así.
        ->and(substr((string) $pdf, 0, 5))->toBe('%PDF-')
        ->and($contrato->filename($reservation))->toBe('contrato-'.strtolower($reservation->displayCode()).'.pdf');
});

it('el correo de confirmación lleva el contrato y el calendario', function () {
    conContrato();
    $reservation = reservaConHuesped();

    $adjuntos = (new GuestReservationMail($reservation, 'Confirmada', 'Reserva confirmada', true, true))->attachments();

    expect($adjuntos)->toHaveCount(2);
});

it('los avisos que no son la confirmación no adjuntan contrato', function () {
    conContrato();
    $reservation = reservaConHuesped();

    expect((new GuestReservationMail($reservation, 'Recibimos tu pago', 'Pago recibido'))->attachments())->toBeEmpty();
});
