<?php

use App\Http\Controllers\Tenant\InboxController;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Guest;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\Request;

/*
 * La bandeja cargaba 100 conversaciones y el buscador filtraba SOLO esas.
 * Con 957 activas, un hilo de tres días antes no salía ni escribiendo el
 * nombre completo: parecía eliminado (Alonso Jurado, 17-sep-2026).
 */

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->channel = Channel::create([
        'property_id' => $this->property->id,
        'type' => Channel::TYPE_WHATSAPP_EVOLUTION,
        'external_id' => '1',
        'name' => 'WhatsApp',
        'mode' => 'auto',
        'active' => true,
    ]);
    $this->user = User::factory()->create();
});

/** Props de la bandeja tal como las recibe la pantalla. */
function inboxProps(array $query = []): array
{
    $request = Request::create('/bandeja', 'GET', $query);
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => test()->user);

    return app(InboxController::class)->index($request)
        ->toResponse($request)->getData(true)['props'];
}

/** @return array<int, int> */
function inboxIds(array $query = []): array
{
    return collect(inboxProps($query)['conversations'])->pluck('id')->all();
}

function inboxChat(array $overrides = []): Conversation
{
    return Conversation::create(array_replace([
        'channel_id' => test()->channel->id,
        'contact_phone' => '5216141230000',
        'status' => Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ], $overrides));
}

/** Llena la bandeja con conversaciones más recientes que la buscada. */
function fillInbox(int $n = 120): void
{
    foreach (range(1, $n) as $i) {
        inboxChat([
            'contact_name' => "Relleno {$i}",
            'contact_phone' => '52161499'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
            'last_message_at' => now()->subMinutes($i),
        ]);
    }
}

it('encuentra por nombre un hilo que quedó fuera de la tanda cargada', function () {
    $alonso = inboxChat([
        'contact_name' => 'Alonso Jurado',
        'last_message_at' => now()->subDays(3),
    ]);
    fillInbox();

    // Sin buscar no aparece: la lista es una ventana de las más recientes.
    expect(inboxIds())->not->toContain($alonso->id)
        ->and(inboxProps()['pagination']['has_more'])->toBeTrue();

    // Buscándolo, sí.
    expect(inboxIds(['q' => 'Alonso']))->toContain($alonso->id);
});

it('busca en toda la bandeja: resueltas y archivadas incluidas', function () {
    $archivada = inboxChat([
        'contact_name' => 'Alonso Jurado',
        'status' => Conversation::STATUS_RESOLVED,
        'archived_at' => now()->subDay(),
        'last_message_at' => now()->subDays(5),
    ]);

    expect(inboxIds())->not->toContain($archivada->id)
        ->and(inboxIds(['q' => 'Alonso']))->toContain($archivada->id);
});

it('encuentra por teléfono aunque se escriba con espacios o guiones', function () {
    $chat = inboxChat([
        'contact_name' => 'Alonso Jurado',
        'contact_phone' => '19152222027',
    ]);

    expect(inboxIds(['q' => '915 222 2027']))->toContain($chat->id)
        ->and(inboxIds(['q' => '915-222-2027']))->toContain($chat->id);
});

it('encuentra al huésped por su nombre completo y por su teléfono de ficha', function () {
    $guest = Guest::create([
        'first_name' => 'Alonso',
        'last_name' => 'Jurado',
        'phone' => '(915) 222-2027',
    ]);
    $chat = inboxChat(['guest_id' => $guest->id, 'contact_phone' => 'lid-9988776655']);

    expect(inboxIds(['q' => 'Alonso Jurado']))->toContain($chat->id)
        ->and(inboxIds(['q' => '9152222027']))->toContain($chat->id);
});

it('encuentra por el texto de un mensaje del hilo, no solo por el último', function () {
    $chat = inboxChat(['contact_name' => 'Visitante']);
    $chat->messages()->create([
        'direction' => 'in',
        'sender_type' => 'guest',
        'body' => 'Voy a mandar el comprobante por la cabaña Encino',
        'created_at' => now()->subHour(),
    ]);
    $chat->messages()->create([
        'direction' => 'out',
        'sender_type' => 'staff',
        'body' => 'Quedamos al pendiente',
        'created_at' => now(),
    ]);

    expect(inboxIds(['q' => 'Encino']))->toContain($chat->id);
});

it('una letra suelta no es una búsqueda', function () {
    $archivada = inboxChat(['contact_name' => 'Alonso', 'archived_at' => now()]);

    // Con un solo carácter la bandeja sigue siendo la de siempre.
    expect(inboxIds(['q' => 'a']))->not->toContain($archivada->id)
        ->and(inboxProps(['q' => 'a'])['filters']['q'])->toBe('');
});

it('dice cuántas hay y carga de 100 en 100 sin perder lo ya cargado', function () {
    fillInbox(150);

    $first = inboxProps();

    expect($first['conversations'])->toHaveCount(100)
        ->and($first['pagination']['total'])->toBe(150)
        ->and($first['pagination']['loaded'])->toBe(100)
        ->and($first['pagination']['has_more'])->toBeTrue();

    $second = inboxProps(['ver' => 200]);

    expect($second['conversations'])->toHaveCount(150)
        ->and($second['pagination']['has_more'])->toBeFalse()
        // El tope evita que un enlace manipulado pida la bandeja entera.
        ->and(inboxProps(['ver' => 99999])['filters']['ver'])->toBe(1000);
});

it('la pestaña de resueltas se arma en el servidor, no con lo que cupo', function () {
    $resuelta = inboxChat([
        'contact_name' => 'Resuelta vieja',
        'status' => Conversation::STATUS_RESOLVED,
        'last_message_at' => now()->subDays(9),
    ]);
    fillInbox();

    // En Activas no estorba, y en Resueltas aparece aunque sea la más vieja.
    expect(inboxIds())->not->toContain($resuelta->id)
        ->and(inboxIds(['estado' => 'resolved']))->toBe([$resuelta->id])
        ->and(inboxProps(['estado' => 'resolved'])['pagination']['total'])->toBe(1);
});
