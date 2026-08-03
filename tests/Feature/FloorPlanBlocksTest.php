<?php

use App\Http\Controllers\Tenant\FloorPlanController;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
        'status' => 'available',
    ]);

    Permission::findOrCreate('rooms.view', 'web');
    Permission::findOrCreate('rooms.update-status', 'web');
    $this->user = User::factory()->create();
    $this->user->givePermissionTo(['rooms.view', 'rooms.update-status']);
});

/** Props del plano tal como los recibe la página. */
function floorPlanProps(): array
{
    $request = Request::create('/plano', 'GET');
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => test()->user);

    return app(FloorPlanController::class)($request)
        ->toResponse($request)->getData(true)['props'];
}

it('el plano entrega los bloqueos vigentes de la habitación', function () {
    RoomBlock::create([
        'room_id' => $this->room->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(2),
        'reason' => 'Cambio de minisplit',
    ]);

    $room = floorPlanProps()['rooms'][0];

    expect($room['blocks'])->toHaveCount(1)
        ->and($room['blocks'][0]['active'])->toBeTrue()
        ->and($room['blocks'][0]['reason'])->toBe('Cambio de minisplit')
        // Un bloqueo NO mueve el semáforo: sigue disponible, y por eso el
        // plano necesita decirlo aparte.
        ->and($room['status'])->toBe('available');
});

it('un bloqueo futuro viaja al plano pero no se marca como activo', function () {
    RoomBlock::create([
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(5),
        'ends_at' => now()->addDays(8),
        'reason' => 'Pintura',
    ]);

    $room = floorPlanProps()['rooms'][0];

    expect($room['blocks'])->toHaveCount(1)
        ->and($room['blocks'][0]['active'])->toBeFalse();
});

it('los bloqueos que ya pasaron no se muestran', function () {
    RoomBlock::create([
        'room_id' => $this->room->id,
        'starts_at' => now()->subDays(10),
        'ends_at' => now()->subDays(8),
        'reason' => 'Ya terminó',
    ]);

    expect(floorPlanProps()['rooms'][0]['blocks'])->toBeEmpty();
});

it('una habitación sin bloqueos entrega la lista vacía, no null', function () {
    expect(floorPlanProps()['rooms'][0]['blocks'])->toBe([]);
});
