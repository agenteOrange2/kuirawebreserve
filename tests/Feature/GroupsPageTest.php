<?php

use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\GroupsPageController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Cabaña Sencilla',
        'capacity' => 4,
    ]);
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

/**
 * Grupo con dos habitaciones en el estado pedido (lo que la tarjeta lee
 * para decir "alojado", "por confirmar"...).
 */
function grupoConEstado(ReservationStatus $status, array $overrides = []): ReservationGroup
{
    $group = ReservationGroup::create(array_replace([
        'property_id' => test()->property->id,
        'guest_name' => 'Familia Prueba',
    ], $overrides));

    $group->forceFill(['code' => ReservationGroup::formatCode($group->id, $group->created_at)])->saveQuietly();

    foreach (range(1, 2) as $i) {
        Reservation::create([
            'property_id' => test()->property->id,
            'room_type_id' => test()->roomType->id,
            'room_id' => test()->room->id,
            'rate_plan_id' => test()->plan->id,
            'reservation_group_id' => $group->id,
            'guest_name' => $group->guest_name,
            'num_people' => 2,
            'starts_at' => $overrides['starts_at'] ?? now()->addDays(5)->setTime(14, 0),
            'ends_at' => $overrides['ends_at'] ?? now()->addDays(6)->setTime(11, 0),
            'status' => $status,
            'total_amount' => 1500,
            'source_channel' => 'front_desk',
        ]);
    }

    return $group->refresh();
}

/** Props Inertia de la página de grupos. */
function propsDeGrupos(array $query = []): array
{
    $request = Request::create('/grupos', 'GET', $query);
    $request->headers->set('X-Inertia', 'true');
    // El paginador lee la página del request del contenedor, no del que
    // recibe el controlador. Va antes del user resolver: al rebindear,
    // Laravel reinstala el suyo (guard vacío en pruebas).
    app()->instance('request', $request);
    $request->setUserResolver(fn () => test()->user);

    return app(GroupsPageController::class)($request)->toResponse($request)->getData(true)['props'];
}

it('la lista pagina de 15 en 15 y los contadores miran todos los grupos', function () {
    foreach (range(1, 18) as $i) {
        grupoConEstado(ReservationStatus::Confirmed, ['guest_name' => "Grupo {$i}"]);
    }

    $props = propsDeGrupos();

    expect($props['groups']['data'])->toHaveCount(15)
        ->and($props['groups']['total'])->toBe(18)
        // Los contadores NO son de la página: son del hotel entero.
        ->and($props['stats']['total'])->toBe(18)
        ->and($props['stats']['active'])->toBe(18)
        ->and($props['stats']['rooms'])->toBe(36)
        ->and($props['stats']['value'])->toEqual(54000);

    $segunda = propsDeGrupos(['page' => 2]);
    expect($segunda['groups']['data'])->toHaveCount(3);
});

it('el filtro de estado entiende el estado derivado del grupo', function () {
    grupoConEstado(ReservationStatus::CheckedIn, ['guest_name' => 'Grupo Alojado']);
    grupoConEstado(ReservationStatus::Confirmed, ['guest_name' => 'Grupo Confirmado']);
    grupoConEstado(ReservationStatus::Pending, ['guest_name' => 'Grupo Por Confirmar']);
    grupoConEstado(ReservationStatus::Cancelled, ['guest_name' => 'Grupo Cancelado']);
    grupoConEstado(ReservationStatus::Completed, ['guest_name' => 'Grupo Finalizado']);

    $nombres = fn (array $props) => collect($props['groups']['data'])->pluck('guest_name')->all();

    expect($nombres(propsDeGrupos(['status' => 'checked_in'])))->toBe(['Grupo Alojado'])
        ->and($nombres(propsDeGrupos(['status' => 'confirmed'])))->toBe(['Grupo Confirmado'])
        ->and($nombres(propsDeGrupos(['status' => 'pending'])))->toBe(['Grupo Por Confirmar'])
        ->and($nombres(propsDeGrupos(['status' => 'cancelled'])))->toBe(['Grupo Cancelado'])
        ->and($nombres(propsDeGrupos(['status' => 'completed'])))->toBe(['Grupo Finalizado']);

    // "Por confirmar" del contador es el mismo criterio que el filtro.
    expect(propsDeGrupos()['stats']['pending'])->toBe(1);
});

it('busca por responsable, folio y por lo que trae dentro', function () {
    $rosa = grupoConEstado(ReservationStatus::Confirmed, ['guest_name' => 'Rosa Quintero']);
    grupoConEstado(ReservationStatus::Confirmed, ['guest_name' => 'Otro Responsable']);

    $porNombre = propsDeGrupos(['q' => 'Rosa']);
    expect($porNombre['groups']['total'])->toBe(1)
        ->and($porNombre['groups']['data'][0]['id'])->toBe($rosa->id);

    $porFolio = propsDeGrupos(['q' => $rosa->code]);
    expect($porFolio['groups']['total'])->toBe(1);

    // Por habitación: la cabaña vive en las reservas del grupo, no en él.
    $porHabitacion = propsDeGrupos(['q' => '101']);
    expect($porHabitacion['groups']['total'])->toBe(2);

    $porTipo = propsDeGrupos(['q' => 'Cabaña Sencilla']);
    expect($porTipo['groups']['total'])->toBe(2);
});

it('el rango de llegada mira la primera habitación del grupo', function () {
    grupoConEstado(ReservationStatus::Confirmed, [
        'guest_name' => 'Llega Pronto',
        'starts_at' => now()->addDays(2)->setTime(14, 0),
        'ends_at' => now()->addDays(3)->setTime(11, 0),
    ]);
    grupoConEstado(ReservationStatus::Confirmed, [
        'guest_name' => 'Llega Tarde',
        'starts_at' => now()->addDays(20)->setTime(14, 0),
        'ends_at' => now()->addDays(21)->setTime(11, 0),
    ]);

    $desde = propsDeGrupos(['from' => now()->addDays(10)->toDateString()]);
    expect($desde['groups']['total'])->toBe(1)
        ->and($desde['groups']['data'][0]['guest_name'])->toBe('Llega Tarde');

    $hasta = propsDeGrupos(['to' => now()->addDays(10)->toDateString()]);
    expect($hasta['groups']['total'])->toBe(1)
        ->and($hasta['groups']['data'][0]['guest_name'])->toBe('Llega Pronto');
});

it('la página de grupos no cobra consultas por grupo', function () {
    foreach (range(1, 15) as $i) {
        grupoConEstado(ReservationStatus::Confirmed, ['guest_name' => "Grupo {$i}"]);
    }

    DB::enableQueryLog();
    $props = propsDeGrupos();
    $consultas = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Antes venían los últimos 100 grupos completos; ahora son quince y
    // sus relaciones viajan en eager loads, no consulta por consulta.
    expect($props['groups']['data'])->toHaveCount(15)
        ->and($consultas)->toBeLessThanOrEqual(20);
});
