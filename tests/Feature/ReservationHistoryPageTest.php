<?php

use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\ReservationHistoryPageController;
use App\Http\Controllers\Tenant\ReservationsPageController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Sencilla']);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1000,
    ]);

    Permission::findOrCreate('reservations.manage', 'web');
    $this->user = User::factory()->create();
    $this->user->givePermissionTo('reservations.manage');
});

function makeHistoryReservation(array $overrides = []): Reservation
{
    return Reservation::create(array_replace([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Huésped Historial',
        'num_people' => 2,
        'starts_at' => now()->subDays(3)->setTime(15, 0),
        'ends_at' => now()->subDays(2)->setTime(12, 0),
        'status' => ReservationStatus::Completed,
        'total_amount' => 1000,
        'source_channel' => 'front_desk',
        'created_by' => test()->user->id,
    ], $overrides));
}

/** Props Inertia de un page-controller invocado como petición X-Inertia. */
function inertiaProps(string $controller, array $query = []): array
{
    $request = Request::create('/pagina', 'GET', $query);
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => test()->user);

    return app($controller)($request)->toResponse($request)->getData(true)['props'];
}

it('la lista de /reservas recorta el historial a 20 y expone el total real', function () {
    foreach (range(1, 23) as $i) {
        makeHistoryReservation(['guest_name' => "Huésped {$i}"]);
    }

    $props = inertiaProps(ReservationsPageController::class);

    expect($props['history'])->toHaveCount(20)
        ->and($props['historyTotal'])->toBe(23);
});

it('el historial completo pagina, filtra por estado y busca por huésped y código', function () {
    $completada = makeHistoryReservation(['guest_name' => 'Laura Completa']);
    $cancelada = makeHistoryReservation([
        'guest_name' => 'Pedro Cancelado',
        'status' => ReservationStatus::Cancelled,
        'cancellation_reason' => 'Cambio de planes',
    ]);
    // Una pendiente NUNCA debe salir en el historial.
    makeHistoryReservation([
        'guest_name' => 'Futuro Pendiente',
        'status' => ReservationStatus::Pending,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(2),
    ]);

    $todos = inertiaProps(ReservationHistoryPageController::class);
    expect($todos['reservations']['total'])->toBe(2)
        ->and(collect($todos['reservations']['data'])->pluck('guest_name'))
        ->not->toContain('Futuro Pendiente');

    $canceladas = inertiaProps(ReservationHistoryPageController::class, ['status' => 'cancelled']);
    expect($canceladas['reservations']['total'])->toBe(1)
        ->and($canceladas['reservations']['data'][0]['guest_name'])->toBe('Pedro Cancelado')
        ->and($canceladas['reservations']['data'][0]['cancellation_reason'])->toBe('Cambio de planes');

    $porNombre = inertiaProps(ReservationHistoryPageController::class, ['q' => 'Laura']);
    expect($porNombre['reservations']['total'])->toBe(1)
        ->and($porNombre['reservations']['data'][0]['id'])->toBe($completada->id);

    // Búsqueda por código mostrado (RES-AAAA-0000 se resuelve por id).
    $porCodigo = inertiaProps(ReservationHistoryPageController::class, ['q' => $cancelada->displayCode()]);
    expect($porCodigo['reservations']['total'])->toBe(1)
        ->and($porCodigo['reservations']['data'][0]['id'])->toBe($cancelada->id);
});

it('la apariencia del wizard resuelve defaults y respeta la personalización', function () {
    expect($this->property->wizardAppearance())->toBe([
        'bg_from' => '#03045e',
        'bg_to' => '#0c4a6e',
        'accent' => '#03045e',
        'theme' => 'light',
        'logo_url' => null,
    ]);

    $this->property->update([
        'settings' => array_merge($this->property->settings ?? [], [
            'wizard_bg_from' => '#111827',
            'wizard_bg_to' => '#1f2937',
            'wizard_accent' => '#b91c1c',
            'wizard_theme' => 'dark',
        ]),
    ]);

    $appearance = $this->property->fresh()->wizardAppearance();

    expect($appearance['bg_from'])->toBe('#111827')
        ->and($appearance['accent'])->toBe('#b91c1c')
        ->and($appearance['theme'])->toBe('dark')
        ->and($appearance['logo_url'])->toBeNull();
});
