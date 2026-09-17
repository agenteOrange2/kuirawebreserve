<?php

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\ReservationGroup;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Payments\PaymentGuestNotifier;

// Caso real cabañas 2026-09-14 (Nancy, GRP-2026-0151): apartó 3 cabañas y el
// aviso de confirmación decía "Tu reserva RES-2026-1743 está confirmada:
// Cabaña Sencilla 2" — una sola cabaña, con folio de reserva suelta. Ella
// preguntó después por cuáles eran las suyas.

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create(['name' => 'Cabañas Real de la Sierra']);
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Cabaña Sencilla']);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 3000,
    ]);
});

function grupoDeTresCabanas(): array
{
    $group = ReservationGroup::create([
        'property_id' => test()->property->id,
        'code' => 'GRP-2026-0151',
        'guest_name' => 'Nancy',
    ]);

    $reservations = collect(['Cabaña Sencilla 2', 'Cabaña Sencilla 3', 'Cabaña Sencilla 4'])
        ->map(function (string $nombre, int $i) use ($group) {
            $room = Room::factory()->create([
                'property_id' => test()->property->id,
                'room_type_id' => test()->roomType->id,
                'name' => $nombre,
                'number' => '20'.$i,
            ]);

            $reservation = app(\App\Actions\Reservations\CreateReservation::class)->handle([
                'rate_plan_id' => test()->plan->id,
                'room_id' => $room->id,
                'starts_at' => now()->addDays(4)->setTime(14, 0),
                'ends_at' => now()->addDays(5)->setTime(11, 0),
                'guest_name' => 'Nancy',
                'confirmed' => true,
            ]);

            $reservation->update(['reservation_group_id' => $group->id]);

            return $reservation->refresh();
        });

    return [$group, $reservations];
}

function hiloDelGrupo(int $reservationId): Conversation
{
    $channel = Channel::firstOrCreate(
        ['property_id' => test()->property->id, 'type' => Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );

    return Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '5216560000000',
        'status' => Conversation::STATUS_OPEN,
        'reservation_id' => $reservationId,
        'last_message_at' => now(),
    ]);
}

it('el aviso del grupo nombra el folio GRP y todas las cabañas, una sola vez', function () {
    [$group, $reservations] = grupoDeTresCabanas();

    $conversation = hiloDelGrupo($reservations->first()->id);

    // El panel confirma habitación por habitación, en el mismo segundo.
    $reservations->each(fn ($r) => app(PaymentGuestNotifier::class)->reservationConfirmed($r));

    $avisos = $conversation->messages()->where('direction', 'out')->get();

    expect($avisos)->toHaveCount(1)
        ->and($avisos->first()->body)->toContain('GRP-2026-0151')
        ->and($avisos->first()->body)->toContain('3 habitaciones')
        ->and($avisos->first()->body)->toContain('Cabaña Sencilla 2')
        ->and($avisos->first()->body)->toContain('Cabaña Sencilla 3')
        ->and($avisos->first()->body)->toContain('Cabaña Sencilla 4')
        // El folio de una sola reserva ya no aparece: confunde al huésped.
        ->and($avisos->first()->body)->not->toContain($reservations->first()->displayCode());
});

it('mientras una habitación del grupo siga pendiente no se anuncia confirmado', function () {
    [$group, $reservations] = grupoDeTresCabanas();

    $conversation = hiloDelGrupo($reservations->first()->id);

    $reservations->last()->update(['status' => \App\Enums\ReservationStatus::Pending]);

    app(PaymentGuestNotifier::class)->reservationConfirmed($reservations->first()->refresh());

    // Cae al aviso suelto de esa reserva, no al del grupo.
    $aviso = $conversation->messages()->where('direction', 'out')->first();

    expect($aviso?->body)->not->toContain('Tu grupo GRP-2026-0151 está confirmado');
});
