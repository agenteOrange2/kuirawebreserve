<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\TransitionReservation;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\BookingLookupController;
use App\Models\Guest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Doble', 'capacity' => 4]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 950,
    ]);

    $this->reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(5)->setTime(15, 0),
        'ends_at' => now()->addDays(6)->setTime(12, 0),
        'confirmed' => true,
        'guest_name' => 'Ana García',
        'guest_phone' => '5511112233',
        'guest_email' => 'ana@example.com',
    ]);
});

function preRegister(array $params): \Illuminate\Http\JsonResponse
{
    return app(BookingLookupController::class)->preRegister(
        Request::create('/api/booking/reservation/pre-register', 'POST', $params),
    );
}

it('el huésped completa su pre-registro con código y teléfono correctos', function () {
    $response = preRegister([
        'code' => $this->reservation->code,
        'phone' => '11112233', // últimos 8 dígitos bastan, como en la consulta
        'guest_name' => 'Ana García López',
        'vehicle_plate' => 'ABC-123',
        'vehicle_desc' => 'Sedán gris',
        'eta' => '16:30',
        'guest_notes' => 'Llegamos con un bebé',
    ]);

    expect($response->getStatusCode())->toBe(200);

    $this->reservation->refresh();
    expect($this->reservation->guest_name)->toBe('Ana García López')
        ->and($this->reservation->vehicle_plate)->toBe('ABC-123')
        ->and($this->reservation->vehicle_desc)->toBe('Sedán gris')
        ->and(substr((string) $this->reservation->eta, 0, 5))->toBe('16:30')
        ->and($this->reservation->guest_notes)->toBe('Llegamos con un bebé');

    // La respuesta trae el bloque para re-precargar el formulario.
    $data = $response->getData(true);
    expect($data['pre_registration']['eta'])->toBe('16:30')
        ->and($data['pre_registration']['vehicle_plate'])->toBe('ABC-123');
});

it('rechaza con 404 genérico el teléfono equivocado y no toca nada', function () {
    $response = preRegister([
        'code' => $this->reservation->code,
        'phone' => '0000000000',
        'vehicle_plate' => 'ZZZ-999',
        'eta' => '18:00',
    ]);

    expect($response->getStatusCode())->toBe(404)
        ->and($this->reservation->refresh()->vehicle_plate)->toBeNull()
        ->and($this->reservation->eta)->toBeNull();
});

it('no aplica en una reserva cancelada ni en una completada', function () {
    app(TransitionReservation::class)->cancel($this->reservation);

    $params = ['code' => $this->reservation->code, 'phone' => '5511112233', 'eta' => '18:00'];

    expect(preRegister($params)->getStatusCode())->toBe(422);

    $this->reservation->forceFill(['status' => ReservationStatus::Completed])->saveQuietly();

    expect(preRegister($params)->getStatusCode())->toBe(422)
        ->and($this->reservation->refresh()->eta)->toBeNull();
});

it('no pisa el correo ya guardado del guest, pero completa el que falta', function () {
    $guestId = $this->reservation->guest_id;

    // Con correo ya capturado en el CRM, el del pre-registro no lo pisa.
    preRegister([
        'code' => $this->reservation->code,
        'phone' => '5511112233',
        'guest_email' => 'otro@example.com',
    ]);

    expect(Guest::findOrFail($guestId)->email)->toBe('ana@example.com');

    // Sin correo en la ficha, el pre-registro lo completa.
    Guest::findOrFail($guestId)->update(['email' => null]);

    preRegister([
        'code' => $this->reservation->code,
        'phone' => '5511112233',
        'guest_email' => 'otro@example.com',
    ]);

    expect(Guest::findOrFail($guestId)->email)->toBe('otro@example.com');
});
