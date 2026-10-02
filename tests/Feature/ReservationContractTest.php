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

// ---------------------------------------------------------------------------
// El contrato a mano desde la ficha (cabañas 2026-09-18, Daysi Gómez
// RES-2026-1773): dictó su correo por WhatsApp, la reserva se capturó en
// mostrador sin ese correo, el contrato no salió y nadie se enteró; recepción
// terminó mandándole a mano un link del sitio como si fuera el contrato.
// ---------------------------------------------------------------------------

function reservaSinCorreo(): \App\Models\Reservation
{
    $guest = Guest::create([
        'first_name' => 'DAYSI GOMEZ RIVAS',
        'phone' => '+526565919826',
    ]);

    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'guest_id' => $guest->id,
        'starts_at' => now()->addDays(10)->setTime(16, 0),
        'ends_at' => now()->addDays(11)->setTime(11, 0),
        'confirmed' => true,
    ], notifyGuest: false);
}

it('la ficha dice que el contrato no ha salido y que falta el correo', function () {
    conContrato();
    $reservation = reservaSinCorreo();

    $request = \Illuminate\Http\Request::create("/reservas/{$reservation->id}", 'GET');
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => \App\Models\User::factory()->create());

    $props = app(\App\Http\Controllers\Tenant\ReservationShowPageController::class)
        ->show($request, $reservation)
        ->toResponse($request)
        ->getData(true)['props'];

    expect($props)->toHaveKey('contract')
        ->and($props['contract']['available'])->toBeTrue()
        ->and($props['contract']['email'])->toBeNull()
        ->and($props['contract']['sent_at'])->toBeNull();
});

it('enviar el contrato guarda en la ficha el correo que faltaba', function () {
    conContrato();
    \Illuminate\Support\Facades\Mail::fake();

    $reservation = reservaSinCorreo();
    $user = \App\Models\User::factory()->create();

    $request = \Illuminate\Http\Request::create(
        "/reservas/{$reservation->id}/contrato",
        'POST',
        ['email' => 'daisyhelenacalita@gmail.com'],
    );
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session.store'));

    app(\App\Http\Controllers\Tenant\ReservationContractController::class)
        ->send($request, $reservation);

    \Illuminate\Support\Facades\Mail::assertSent(
        GuestReservationMail::class,
        fn (GuestReservationMail $mail) => $mail->withContract
            && $mail->hasTo('daisyhelenacalita@gmail.com'),
    );

    // El correo dictado se queda en la ficha: los avisos siguientes ya salen
    // solos, sin que nadie lo vuelva a teclear.
    expect($reservation->guest->fresh()->email)->toBe('daisyhelenacalita@gmail.com');

    // Y queda en la historia de la reserva quién lo mandó y a dónde.
    expect(\Spatie\Activitylog\Models\Activity::query()
        ->where('subject_id', $reservation->id)
        ->where('description', 'like', 'Contrato de hospedaje enviado%')
        ->exists())->toBeTrue();
});

it('sin correo del huésped no manda nada y lo dice', function () {
    conContrato();
    \Illuminate\Support\Facades\Mail::fake();

    $reservation = reservaSinCorreo();

    $request = \Illuminate\Http\Request::create("/reservas/{$reservation->id}/contrato", 'POST');
    $request->setUserResolver(fn () => \App\Models\User::factory()->create());
    $request->setLaravelSession(app('session.store'));

    $response = app(\App\Http\Controllers\Tenant\ReservationContractController::class)
        ->send($request, $reservation);

    \Illuminate\Support\Facades\Mail::assertNothingSent();
    expect($response->getSession()->get('error'))->toContain('no tiene correo');
});

it('un contrato que no sale por falta de correo suena la campana', function () {
    conContrato();
    $reservation = reservaSinCorreo();

    app(\App\Services\Channels\DirectGuestMessenger::class)
        ->mailTo($reservation, 'Tu reserva está confirmada', 'Reserva confirmada', false, true);

    $aviso = \App\Models\StaffNotification::query()->latest('id')->first();

    expect($aviso)->not->toBeNull()
        ->and($aviso->title)->toContain('Contrato sin enviar')
        ->and($aviso->body)->toContain('no tiene correo en su ficha');
});

it('un aviso cualquiera sin correo no molesta a nadie', function () {
    conContrato();
    $reservation = reservaSinCorreo();

    // Sin contrato adjunto, que el huésped no tenga correo es normal.
    app(\App\Services\Channels\DirectGuestMessenger::class)
        ->mailTo($reservation, 'Recibimos tu pago', 'Pago recibido');

    expect(\App\Models\StaffNotification::query()->count())->toBe(0);
});

it('el contrato que sale solo en la confirmación también queda registrado', function () {
    conContrato();
    \Illuminate\Support\Facades\Mail::fake();

    $guest = Guest::create([
        'first_name' => 'Huésped Con Correo',
        'phone' => '5216561112233',
        'email' => 'huesped@example.com',
    ]);

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'guest_id' => $guest->id,
        'starts_at' => now()->addDays(12)->setTime(16, 0),
        'ends_at' => now()->addDays(13)->setTime(11, 0),
        'confirmed' => true,
    ]);

    // Nadie tocó el botón: la confirmación lo mandó sola. Si esto no queda
    // anotado, la ficha diría "Sin enviar" de un contrato que ya llegó y
    // recepción lo mandaría dos veces.
    expect(\Spatie\Activitylog\Models\Activity::query()
        ->where('subject_id', $reservation->id)
        ->where('description', 'Contrato de hospedaje enviado a huesped@example.com')
        ->exists())->toBeTrue();
});
