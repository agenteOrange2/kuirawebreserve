<?php

use App\Actions\Reservations\CreateReservation;
use App\Http\Controllers\Agent\AgentToolsController;
use App\Http\Controllers\Tenant\BookingLookupController;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Request;

/**
 * Caso real cabañas 2026-09-13 (RES-2026-1724): el bot apartaba por WhatsApp
 * sin guardar el número en la ficha del huésped, y la consulta pública
 * /reserva —a la que el aviso de confirmación manda "con el teléfono con el
 * que reservaste"— nunca encontraba la reserva. Las 8 reservas vivas sin
 * teléfono eran todas del bot.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Cabaña Luxury']);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 3500,
    ]);
});

function conversacionPor(string $tipo, string $contacto): Conversation
{
    $channel = Channel::firstOrCreate(
        ['property_id' => test()->property->id, 'type' => $tipo, 'external_id' => $tipo],
        ['name' => $tipo, 'mode' => 'auto', 'active' => true],
    );

    return Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => $contacto,
        'status' => Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);
}

function apartarPorBot(Conversation $conversation): array
{
    $response = app(AgentToolsController::class)->storeHold(Request::create('/b', 'POST', [
        'rate_plan_id' => test()->plan->id,
        'starts_at' => now()->addDays(5)->format('Y-m-d').' 14:00',
        'ends_at' => now()->addDays(6)->format('Y-m-d').' 11:00',
        'guest_name' => 'Raquel Vega',
        'guest_email' => 'raquel@example.com',
        'conversation_id' => $conversation->id,
    ]), app(CreateReservation::class));

    return json_decode($response->getContent(), true);
}

it('el apartado del bot por WhatsApp guarda el número en la ficha del huésped', function () {
    $conversation = conversacionPor(Channel::TYPE_WHATSAPP_EVOLUTION, '5216565302316');

    $payload = apartarPorBot($conversation);
    $reservation = Reservation::where('code', $payload['code'])->firstOrFail();

    expect($reservation->guest?->phone)->toBe('5216565302316')
        ->and($reservation->guest?->email)->toBe('raquel@example.com');
});

it('en Messenger no se guarda el id del hilo como si fuera teléfono', function () {
    // En Messenger contact_phone es el PSID, no un número.
    $conversation = conversacionPor('messenger', '24681357902468135');

    $payload = apartarPorBot($conversation);
    $reservation = Reservation::where('code', $payload['code'])->firstOrFail();

    expect($reservation->guest?->phone)->toBeNull();
});

it('la consulta pública encuentra la reserva con el WhatsApp aunque la ficha no tenga teléfono', function () {
    // Reserva vieja, de antes del arreglo: ficha solo con correo.
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(5)->setTime(14, 0),
        'ends_at' => now()->addDays(6)->setTime(11, 0),
        'confirmed' => true,
        'guest_name' => 'Raquel Vega',
        'guest_email' => 'raquel@example.com',
    ]);

    conversacionPor(Channel::TYPE_WHATSAPP_EVOLUTION, '5216565302316')
        ->update(['reservation_id' => $reservation->id]);

    $buscar = fn (string $phone) => app(BookingLookupController::class)->find(
        Request::create('/api/booking/reservation', 'GET', ['code' => $reservation->code, 'phone' => $phone]),
    );

    expect($reservation->guest?->phone)->toBeNull()
        // El número como lo escribe una persona: 10 dígitos, sin lada.
        ->and($buscar('656 530 2316')->getStatusCode())->toBe(200)
        // Otro número sigue sin abrirla: el teléfono es la llave.
        ->and($buscar('6560000000')->getStatusCode())->toBe(404);
});
