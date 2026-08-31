<?php

use App\Actions\Reservations\CreateReservation;
use App\Http\Controllers\Tenant\QuickSearchController;
use App\Models\Guest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

/**
 * Búsqueda rápida del header (⌘K). Antes era una lista fija de dos enlaces
 * y lo tecleado no hacía nada; estas pruebas fijan que ahora encuentra
 * datos reales y que cada bloque respeta el permiso de su sección.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Cabaña Escondida',
        'capacity' => 4,
    ]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '104',
        'name' => 'Cabaña Escondida',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 3000,
    ]);

    foreach (['reservations.view', 'guests.view', 'rooms.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
});

function quickSearch(User $user, string $term): array
{
    $request = Request::create('/api/quick-search', 'GET', ['q' => $term]);
    $request->setUserResolver(fn () => $user);

    return json_decode(app(QuickSearchController::class)($request)->getContent(), true);
}

function groupNamed(array $payload, string $label): ?array
{
    return collect($payload['groups'])->firstWhere('label', $label);
}

it('encuentra una reserva por su código y por el nombre de quien reservó', function () {
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'starts_at' => now()->addDays(3)->setTime(15, 0),
        'guest_name' => 'Yaz Ramírez',
        'confirmed' => true,
    ]);

    $user = User::factory()->create();
    $user->givePermissionTo('reservations.view');

    $porCodigo = groupNamed(quickSearch($user, $reservation->displayCode()), 'Reservas');
    expect($porCodigo['items'][0]['title'])->toBe($reservation->displayCode())
        ->and($porCodigo['items'][0]['subtitle'])->toContain('Yaz Ramírez')
        // Cae en el historial, que es la única lista con buscador de servidor.
        ->and($porCodigo['items'][0]['url'])->toContain('/reservas/historial?q=');

    // Y por nombre, que es como la busca quien contesta el teléfono.
    expect(groupNamed(quickSearch($user, 'Ramírez'), 'Reservas')['items'])->toHaveCount(1);
});

it('cada bloque respeta el permiso de su sección', function () {
    Guest::create(['first_name' => 'Yaz', 'last_name' => 'Ramírez', 'phone' => '6141234567']);

    $soloReservas = User::factory()->create();
    $soloReservas->givePermissionTo('reservations.view');

    // Sin guests.view no aparecen huéspedes, aunque coincidan con lo buscado.
    expect(groupNamed(quickSearch($soloReservas, 'Ramírez'), 'Huéspedes'))->toBeNull();

    $conCrm = User::factory()->create();
    $conCrm->givePermissionTo('guests.view');
    expect(groupNamed(quickSearch($conCrm, 'Ramírez'), 'Huéspedes')['items'][0]['title'])
        ->toBe('Yaz Ramírez');
});

it('encuentra la habitación por número y lleva a su ficha', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('rooms.view');

    $group = groupNamed(quickSearch($user, '104'), 'Habitaciones');

    expect($group['items'][0]['subtitle'])->toContain('104')
        ->and($group['items'][0]['url'])->toContain('/habitaciones/'.$this->room->id);
});

it('con menos de dos letras no consulta nada', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reservations.view');

    expect(quickSearch($user, 'a')['groups'])->toBe([]);
});
