<?php

use App\Http\Controllers\Tenant\PushSubscriptionController;
use App\Jobs\SendStaffPush;
use App\Models\Property;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\StaffNotifier;
use App\Services\WebPushSender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->user = User::factory()->create();

    config()->set('webpush.public_key', 'llave-publica');
    config()->set('webpush.private_key', 'llave-privada');
});

function subscribeRequest(User $user, string $endpoint): Request
{
    $request = Request::create('/api/push-subscriptions', 'POST', [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => 'clave-publica-del-navegador', 'auth' => 'token'],
    ]);
    $request->setUserResolver(fn () => $user);

    return $request;
}

it('guarda la suscripción del navegador', function () {
    app(PushSubscriptionController::class)->store(
        subscribeRequest($this->user, 'https://fcm.googleapis.com/fcm/send/abc'),
        app(WebPushSender::class),
    );

    $subscription = PushSubscription::first();

    expect($subscription)->not->toBeNull()
        ->and($subscription->user_id)->toBe($this->user->id)
        ->and($subscription->endpoint)->toContain('fcm.googleapis.com');
});

it('volver a suscribir el mismo dispositivo no lo duplica', function () {
    $endpoint = 'https://fcm.googleapis.com/fcm/send/abc';
    $controller = app(PushSubscriptionController::class);

    $controller->store(subscribeRequest($this->user, $endpoint), app(WebPushSender::class));
    $controller->store(subscribeRequest($this->user, $endpoint), app(WebPushSender::class));

    // Si duplicara, el mismo aviso le llegaría dos veces al mismo teléfono.
    expect(PushSubscription::count())->toBe(1);
});

it('un usuario puede tener varios dispositivos y todos cuentan', function () {
    $controller = app(PushSubscriptionController::class);

    $controller->store(subscribeRequest($this->user, 'https://fcm.googleapis.com/fcm/send/celular'), app(WebPushSender::class));
    $controller->store(subscribeRequest($this->user, 'https://fcm.googleapis.com/fcm/send/recepcion'), app(WebPushSender::class));

    expect(PushSubscription::where('user_id', $this->user->id)->count())->toBe(2);
});

it('sin llaves VAPID la función queda apagada, sin tronar', function () {
    config()->set('webpush.public_key', null);
    config()->set('webpush.private_key', null);

    $sender = app(WebPushSender::class);

    expect($sender->isConfigured())->toBeFalse()
        ->and($sender->send(['title' => 'Hola']))->toBe(0);
});

it('nadie desconecta el dispositivo de otra persona', function () {
    $otro = User::factory()->create();
    $endpoint = 'https://fcm.googleapis.com/fcm/send/del-otro';

    app(PushSubscriptionController::class)->store(
        subscribeRequest($otro, $endpoint),
        app(WebPushSender::class),
    );

    $request = Request::create('/api/push-subscriptions', 'DELETE', ['endpoint' => $endpoint]);
    $request->setUserResolver(fn () => $this->user);

    app(PushSubscriptionController::class)->destroy($request);

    expect(PushSubscription::count())->toBe(1);
});

it('crear un aviso encola el push en vez de mandarlo en la petición', function () {
    Queue::fake();

    app(StaffNotifier::class)->notify(type: 'message', title: 'Mensaje nuevo');

    // El huésped que escribió no tiene por qué esperar a que Google conteste.
    Queue::assertPushed(SendStaffPush::class);
});

it('el nombre del dispositivo se lee, no es el user agent crudo', function () {
    $request = subscribeRequest($this->user, 'https://fcm.googleapis.com/fcm/send/x');
    $request->headers->set(
        'User-Agent',
        'Mozilla/5.0 (Linux; Android 13; SM-A536B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Mobile Safari/537.36',
    );

    app(PushSubscriptionController::class)->store($request, app(WebPushSender::class));

    $listing = Request::create('/api/push-subscriptions', 'GET');
    $listing->setUserResolver(fn () => $this->user);

    $devices = app(PushSubscriptionController::class)->index($listing)->getData(true)['devices'];

    expect($devices)->toHaveCount(1)
        ->and($devices[0]['name'])->toBe('Android · Chrome');
});

it('se puede quitar un dispositivo por id, sin tenerlo a la mano', function () {
    app(PushSubscriptionController::class)->store(
        subscribeRequest($this->user, 'https://fcm.googleapis.com/fcm/send/perdido'),
        app(WebPushSender::class),
    );

    $request = Request::create('/api/push-subscriptions', 'DELETE', [
        'id' => PushSubscription::first()->id,
    ]);
    $request->setUserResolver(fn () => $this->user);

    app(PushSubscriptionController::class)->destroy($request);

    expect(PushSubscription::count())->toBe(0);
});

it('no se puede quitar por id el dispositivo de otra persona', function () {
    $otro = User::factory()->create();
    app(PushSubscriptionController::class)->store(
        subscribeRequest($otro, 'https://fcm.googleapis.com/fcm/send/ajeno'),
        app(WebPushSender::class),
    );

    $request = Request::create('/api/push-subscriptions', 'DELETE', [
        'id' => PushSubscription::first()->id,
    ]);
    $request->setUserResolver(fn () => $this->user);

    app(PushSubscriptionController::class)->destroy($request);

    expect(PushSubscription::count())->toBe(1);
});
