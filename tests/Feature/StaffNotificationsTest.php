<?php

use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\StaffNotificationController;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\StaffNotification;
use App\Models\User;
use App\Services\StaffNotifier;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->user = User::factory()->create();
    $this->channel = Channel::create([
        'property_id' => $this->property->id,
        'type' => Channel::TYPE_WHATSAPP_EVOLUTION,
        'external_id' => '1',
        'name' => 'WhatsApp',
        'mode' => 'auto',
        'active' => true,
    ]);
});

function noticeConversation(string $phone = '+5216141234567'): Conversation
{
    return Conversation::create([
        'channel_id' => test()->channel->id,
        'contact_phone' => $phone,
        'contact_name' => 'Doña Mari',
        'status' => Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);
}

it('un mensaje entrante genera aviso en la campana', function () {
    $conversation = noticeConversation();

    $conversation->messages()->create([
        'direction' => 'in',
        'sender_type' => 'guest',
        'body' => '¿Tienen habitación para hoy?',
        'created_at' => now(),
    ]);

    $notice = StaffNotification::first();

    expect($notice)->not->toBeNull()
        ->and($notice->type)->toBe(StaffNotification::TYPE_MESSAGE)
        ->and($notice->title)->toBe('Doña Mari')
        ->and($notice->url)->toBe('/bandeja');
});

it('las respuestas del staff no generan aviso: no son novedad para nadie', function () {
    $conversation = noticeConversation();

    $conversation->messages()->create([
        'direction' => 'out',
        'sender_type' => 'staff',
        'body' => 'Claro que sí',
        'created_at' => now(),
    ]);

    expect(StaffNotification::count())->toBe(0);
});

it('cinco mensajes seguidos del mismo huésped dejan UN aviso, no cinco', function () {
    $conversation = noticeConversation();

    foreach (range(1, 5) as $i) {
        $conversation->messages()->create([
            'direction' => 'in',
            'sender_type' => 'guest',
            'body' => "Mensaje {$i}",
            'created_at' => now()->addSeconds($i),
        ]);
    }

    expect(StaffNotification::count())->toBe(1)
        // Y muestra el ÚLTIMO, no el primero.
        ->and(StaffNotification::first()->body)->toContain('Mensaje 5');
});

it('si ya lo leyeron, un mensaje nuevo levanta otro aviso', function () {
    $conversation = noticeConversation();

    $conversation->messages()->create([
        'direction' => 'in', 'sender_type' => 'guest',
        'body' => 'Primero', 'created_at' => now(),
    ]);
    StaffNotification::query()->update(['read_at' => now()]);

    $conversation->messages()->create([
        'direction' => 'in', 'sender_type' => 'guest',
        'body' => 'Segundo', 'created_at' => now()->addMinute(),
    ]);

    expect(StaffNotification::count())->toBe(2)
        ->and(StaffNotification::unread()->count())->toBe(1);
});

it('una reserva del wizard avisa, pero una del mostrador no', function () {
    $roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $roomType->id,
        'number' => '101',
    ]);
    $plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $roomType->id,
        'price' => 1000,
    ]);

    $base = [
        'property_id' => $this->property->id,
        'room_type_id' => $roomType->id,
        'room_id' => $room->id,
        'rate_plan_id' => $plan->id,
        'num_people' => 2,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(2),
        'status' => ReservationStatus::Confirmed,
        'total_amount' => 1000,
    ];

    // La del mostrador la sabe quien la capturó.
    \App\Models\Reservation::create($base + ['guest_name' => 'Mostrador', 'source_channel' => 'front_desk']);

    expect(StaffNotification::where('type', StaffNotification::TYPE_RESERVATION)->count())->toBe(0);
});

it('la campana solo entrega los avisos que le tocan al usuario', function () {
    $otro = User::factory()->create();
    $notifier = app(StaffNotifier::class);

    $notifier->notify(type: 'message', title: 'Para todos');
    $notifier->notify(type: 'message', title: 'Solo para el otro', userId: $otro->id);

    $request = Request::create('/api/staff-notifications', 'GET');
    $request->setUserResolver(fn () => $this->user);

    $data = app(StaffNotificationController::class)->index($request)->getData(true);

    expect($data['notifications'])->toHaveCount(1)
        ->and($data['notifications'][0]['title'])->toBe('Para todos')
        ->and($data['unread'])->toBe(1);
});

it('marcar todo como leído deja el contador en cero', function () {
    $notifier = app(StaffNotifier::class);
    $notifier->notify(type: 'message', title: 'Uno');
    $notifier->notify(type: 'reservation', title: 'Dos');

    $request = Request::create('/api/staff-notifications/read-all', 'POST');
    $request->setUserResolver(fn () => $this->user);

    app(StaffNotificationController::class)->readAll($request);

    expect(StaffNotification::unread()->count())->toBe(0);
});
