<?php

use App\Actions\Reservations\CreateReservation;
use App\Models\Guest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;

// Caso real cabañas 2026-09-15: 7 teléfonos con dos fichas cada uno, y el
// historial repartido entre las dos (Aline Alonzo: 1 reserva en una y 4 en
// la otra). El mismo número entra escrito distinto según el camino:
// "5216562025344" desde WhatsApp, "+526562025344" desde el wizard y
// "656 202 5344" desde el mostrador. Buscar por el texto exacto abría
// ficha nueva cada vez.

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 3000,
    ]);
});

it('reconoce el mismo teléfono escrito de cualquier forma', function (string $escrito) {
    $damaris = Guest::create([
        'first_name' => 'Damaris Michelle Emiliano Crispín',
        'phone' => '5216562025344',
    ]);

    expect(Guest::findByContact($escrito)?->id)->toBe($damaris->id);
})->with([
    '+526562025344',
    '656 202 5344',
    '(656) 202-5344',
    '+52 656 202 5344',
    '526562025344',
]);

it('no usa un número de relleno como llave', function (string $relleno) {
    // En cabañas, Giovanny Estrada y Armando González tenían los dos
    // guardado 1234567890: como llave habría fundido sus fichas.
    Guest::create(['first_name' => 'Giovanny Estrada', 'phone' => $relleno]);

    expect(Guest::findByContact($relleno))->toBeNull();
})->with(['1234567890', '+521234567890', '0000000000', '1111111111']);

it('un teléfono corto solo une fichas si es el mismo', function () {
    $tere = Guest::create(['first_name' => 'Doña Tere', 'phone' => '+52 622222']);

    expect(Guest::findByContact('52622222')?->id)->toBe($tere->id)
        ->and(Guest::findByContact('622223'))->toBeNull();
});

it('no confunde a dos personas distintas', function () {
    Guest::create(['first_name' => 'Damaris', 'phone' => '5216562025344']);

    expect(Guest::findByContact('6561112233'))->toBeNull();
});

it('encuentra por correo aunque cambien las mayúsculas', function () {
    $damaris = Guest::create([
        'first_name' => 'Damaris',
        'email' => 'Damaris.emiliano@icloud.com',
    ]);

    expect(Guest::findByContact(null, 'damaris.emiliano@icloud.com')?->id)->toBe($damaris->id);
});

it('la segunda reserva del mismo huésped no abre una ficha nueva', function () {
    // Primero apartó por WhatsApp (el bot manda el número con lada del JID).
    app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'starts_at' => now()->addDays(3)->format('Y-m-d').' 14:00',
        'ends_at' => now()->addDays(4)->format('Y-m-d').' 11:00',
        'guest_name' => 'Damaris Michelle Emiliano Crispín',
        'guest_phone' => '5216562025344',
        'confirmed' => false,
    ]);

    // Luego el mostrador la capturó con el número como lo tiene en su lista.
    app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'starts_at' => now()->addDays(10)->format('Y-m-d').' 14:00',
        'ends_at' => now()->addDays(11)->format('Y-m-d').' 11:00',
        'guest_name' => 'Damaris Michelle Emiliano Crispín',
        'guest_phone' => '+52 656 202 5344',
        'guest_email' => 'damaris.emiliano@icloud.com',
        'confirmed' => false,
    ]);

    expect(Guest::count())->toBe(1)
        ->and(Guest::first()->reservations()->count())->toBe(2)
        // El correo que trajo la segunda se guarda en la ficha que ya existía.
        ->and(Guest::first()->email)->toBe('damaris.emiliano@icloud.com');
});
