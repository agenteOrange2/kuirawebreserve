<?php

use App\Actions\Reservations\CreateReservation;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
});

function pendingReservation(string $guestPhone, array $overrides = []): Reservation
{
    return app(CreateReservation::class)->handle(array_replace([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addHour(),
        'confirmed' => false,
        'source_channel' => 'web',
        'guest_name' => 'Ana García',
        'guest_phone' => $guestPhone,
    ], $overrides));
}

function whatsappConversation(string $contactPhone): Conversation
{
    $channel = Channel::firstOrCreate(
        ['property_id' => test()->property->id, 'type' => Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );

    return Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => $contactPhone,
        'status' => Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);
}

it('liga la reserva pendiente por los últimos 10 dígitos del teléfono (spec-reservas-avanzado §1.3)', function () {
    // El huésped tecleó su número con espacios y sin lada en el wizard;
    // desde WhatsApp llega como 521 + 10 dígitos.
    $reservation = pendingReservation('614 123 4567');
    $conversation = whatsappConversation('5216141234567');

    $conversation->linkReservationByPhone();

    expect($conversation->refresh()->reservation_id)->toBe($reservation->id)
        ->and($conversation->guest_id)->toBe($reservation->guest_id)
        ->and($conversation->lead_status)->toBe(Conversation::LEAD_HOLD);
});

it('con varias pendientes del mismo teléfono, liga la más reciente', function () {
    pendingReservation('6141234567');
    $latest = pendingReservation('6141234567', ['starts_at' => now()->addDays(2)]);
    $conversation = whatsappConversation('5216141234567');

    $conversation->linkReservationByPhone();

    expect($conversation->refresh()->reservation_id)->toBe($latest->id);
});

it('no liga nada si el teléfono no coincide con ninguna pendiente', function () {
    pendingReservation('6141234567');
    $conversation = whatsappConversation('5219998887766');

    $conversation->linkReservationByPhone();

    expect($conversation->refresh()->reservation_id)->toBeNull()
        ->and($conversation->lead_status)->toBe(Conversation::LEAD_NEW);
});

it('no liga reservas confirmadas: solo pendientes que esperan comprobante', function () {
    pendingReservation('6141234567', ['confirmed' => true, 'starts_at' => now()->addDays(3)]);
    $conversation = whatsappConversation('5216141234567');

    $conversation->linkReservationByPhone();

    expect($conversation->refresh()->reservation_id)->toBeNull();
});

it('respeta un vínculo existente (el del bot manda)', function () {
    $first = pendingReservation('6141234567');
    $second = pendingReservation('6141234567', ['starts_at' => now()->addDays(2)]);
    $conversation = whatsappConversation('5216141234567');
    $conversation->update(['reservation_id' => $first->id]);

    $conversation->linkReservationByPhone();

    expect($conversation->refresh()->reservation_id)->toBe($first->id)
        ->and($second->id)->not->toBe($first->id);
});
