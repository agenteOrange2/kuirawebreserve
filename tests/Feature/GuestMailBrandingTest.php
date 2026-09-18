<?php

use App\Actions\Reservations\CreateReservation;
use App\Mail\GuestNoticeMail;
use App\Mail\GuestReservationMail;
use App\Models\Guest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// El correo de confirmación llegaba firmado por "Kuirawebreserve" y repetía el
// nombre del hotel abajo: al huésped le escribe SU hotel, no la plataforma.
// Con logo va el logo; sin logo, el nombre.

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create(['name' => 'Cabañas Real de la Sierra']);
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Cabaña Prisma']);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 3500,
    ]);
});

function reservaParaCorreo(): \App\Models\Reservation
{
    $guest = Guest::create(['first_name' => 'Karla Villalobos', 'phone' => '5216561112233', 'email' => 'karla@example.com']);

    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'guest_id' => $guest->id,
        'starts_at' => now()->addDays(3)->setTime(14, 0),
        'ends_at' => now()->addDays(4)->setTime(11, 0),
        'confirmed' => true,
    ]);
}

it('sin logo el encabezado rotula al hotel, no a la plataforma', function () {
    $html = (new GuestReservationMail(reservaParaCorreo(), 'Te esperamos.', 'Reserva confirmada'))->render();

    expect($html)->toContain('Cabañas Real de la Sierra')
        ->and($html)->not->toContain('Kuirawebreserve')
        // El nombre del hotel ya no se repite como título del cuerpo: el
        // encabezado lo rotula y el mensaje entra directo.
        ->and($html)->not->toContain('<h1');
});

it('con logo el encabezado lleva la imagen del hotel en su propio dominio', function () {
    Storage::fake('public');
    $this->property->addMedia(UploadedFile::fake()->image('logo.png', 400, 160))->toMediaCollection('wizard_logo');

    $html = (new GuestReservationMail(reservaParaCorreo(), 'Te esperamos.', 'Reserva confirmada'))->render();

    expect($html)->toContain('/fotos/logo?v=')
        ->and($html)->toContain('alt="Cabañas Real de la Sierra"');
});

it('los avisos sueltos usan la misma identidad', function () {
    $html = (new GuestNoticeMail('Tu experiencia', 'Nos vemos el sábado.', 'EXP-0001', [
        ['label' => 'Personas', 'value' => '4'],
    ]))->render();

    expect($html)->toContain('Cabañas Real de la Sierra')
        ->and($html)->not->toContain('Kuirawebreserve');
});

it('el remitente visible es el hotel, salvo que el hotel haya puesto el suyo', function () {
    config(['mail.from.address' => 'reservas@kuirawebreserve.com', 'mail.from.name' => config('app.name')]);

    $mail = new GuestReservationMail(reservaParaCorreo(), 'Te esperamos.', 'Reserva confirmada');

    expect($mail->envelope()->from->name)->toBe('Cabañas Real de la Sierra')
        ->and($mail->envelope()->from->address)->toBe('reservas@kuirawebreserve.com')
        ->and($mail->envelope()->subject)->toContain('Cabañas Real de la Sierra');

    // Lo que el hotel capturó en /ajustes (TenantMailer lo pone en la config)
    // no se pisa.
    config(['mail.from.name' => 'Reservaciones Real de la Sierra']);

    expect($mail->envelope()->from->name)->toBe('Reservaciones Real de la Sierra');
});
