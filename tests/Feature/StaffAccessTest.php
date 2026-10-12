<?php

use App\Http\Controllers\Tenant\UserController;
use App\Http\Middleware\EnsurePanelArea;
use App\Models\User;
use App\Services\StaffAccess;
use Database\Seeders\TenantRolesSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Roles y accesos (/usuarios/roles y /admin/tenants/{id}/roles): qué áreas
 * del panel ve cada rol. Cuida que la ruta caiga en el área correcta, que
 * lo oculto dé 403 aunque se teclee, que el propietario no se pueda tocar
 * y que administrar usuarios no sirva para hacerse dueño.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    $this->seed(TenantRolesSeeder::class);
});

function staffWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

function hideAreas(string $role, array $areas): void
{
    $model = Role::findByName($role);
    $model->forceFill(['hidden_areas' => json_encode($areas)])->save();
}

function passThroughAreas(string $path, User $user): int
{
    $request = Request::create('/'.$path, 'GET');
    $request->setUserResolver(fn () => $user);

    try {
        return (new EnsurePanelArea)->handle($request, fn () => response('ok'))->getStatusCode();
    } catch (HttpException $e) {
        return $e->getStatusCode();
    }
}

it('cada ruta cae en su área y gana el patrón más específico', function () {
    expect(StaffAccess::areaForPath('bandeja'))->toBe('bandeja')
        ->and(StaffAccess::areaForPath('api/inbox/12'))->toBe('bandeja')
        // La campana del personal no es la bandeja: queda abierta.
        ->and(StaffAccess::areaForPath('bandeja/avisos'))->toBeNull()
        ->and(StaffAccess::areaForPath('reservas/RES-2026-0001'))->toBe('reservas')
        ->and(StaffAccess::areaForPath('reservas/reportes'))->toBe('reservas-reportes')
        ->and(StaffAccess::areaForPath('reservas/reportes/pdf'))->toBe('reservas-reportes')
        ->and(StaffAccess::areaForPath('reservas/cuentas'))->toBe('cuentas')
        ->and(StaffAccess::areaForPath('reservas/ajustes'))->toBe('ajustes')
        ->and(StaffAccess::areaForPath('habitaciones/reportes'))->toBe('habitaciones-reportes')
        ->and(StaffAccess::areaForPath('habitaciones/14'))->toBe('habitaciones')
        ->and(StaffAccess::areaForPath('menu-digital/solicitudes'))->toBe('cocina')
        ->and(StaffAccess::areaForPath('limpieza/reportes/pdf'))->toBe('limpieza-reportes')
        // Lo que comparten varias pantallas lo cuida el permiso, no el área.
        ->and(StaffAccess::areaForPath('api/reservations'))->toBeNull()
        ->and(StaffAccess::areaForPath('dashboard'))->toBeNull()
        ->and(StaffAccess::areaForPath('perfil/contrasena'))->toBeNull();
});

it('cada área del catálogo corresponde a una página real del panel', function () {
    $pages = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => in_array('GET', $r->methods(), true) && str_starts_with((string) $r->getName(), 'tenant.'))
        ->map(fn ($r) => $r->uri());

    foreach (config('panel-areas.areas') as $key => $area) {
        $hit = $pages->contains(fn (string $uri) => StaffAccess::areaForPath(preg_replace('/\{[^}]+\}/', '1', $uri)) === $key);

        expect($hit)->toBeTrue("el área {$key} no tiene ninguna página");
    }
});

it('el menú del hotel solo usa áreas que existen', function () {
    $menu = file_get_contents(resource_path('js/composables/useMenu.ts'));
    preg_match_all("/area: '([^']+)'/", $menu, $m);

    expect($m[1])->not->toBeEmpty();
    foreach ($m[1] as $key) {
        expect(config("panel-areas.areas.{$key}"))->not->toBeNull("el menú usa el área {$key} que no está en config/panel-areas.php");
    }
});

it('sin nada guardado un rol ve todo lo que su permiso abre', function () {
    // Así se desplegó: ningún hotel pierde nada hasta que el dueño decida.
    expect(StaffAccess::hiddenAreasFor(staffWithRole('manager')))->toBe([]);
});

it('lo oculto da 403 aunque se teclee la URL y lo demás sigue abierto', function () {
    hideAreas('manager', ['bandeja']);
    $gerente = staffWithRole('manager');

    expect(passThroughAreas('bandeja', $gerente))->toBe(403)
        ->and(passThroughAreas('api/inbox/5', $gerente))->toBe(403)
        ->and(passThroughAreas('limpieza', $gerente))->toBe(200)
        ->and(passThroughAreas('bandeja/avisos', $gerente))->toBe(200)
        ->and(passThroughAreas('dashboard', $gerente))->toBe(200);
});

it('el propietario nunca tiene áreas ocultas', function () {
    hideAreas('owner', ['bandeja', 'plano']);

    expect(StaffAccess::hiddenAreasFor(staffWithRole('owner')))->toBe([]);
});

it('con dos roles, un área se oculta solo si los dos la esconden', function () {
    hideAreas('manager', ['bandeja', 'cortes']);
    hideAreas('front-desk', ['cortes']);
    $user = staffWithRole('manager');
    $user->assignRole('front-desk');

    expect(StaffAccess::hiddenAreasFor($user->fresh()))->toBe(['cortes']);
});

it('guarda áreas y permisos de un rol de fábrica', function () {
    $role = StaffAccess::save(Role::findByName('housekeeping'), [
        'label' => 'Camaristas',
        'description' => 'Turno de mañana',
        'permissions' => ['rooms.view', 'rooms.update-status'],
        'hidden_areas' => ['plano'],
    ]);

    expect($role->label)->toBe('Camaristas')
        ->and($role->permissions->pluck('name')->sort()->values()->all())->toBe(['rooms.update-status', 'rooms.view'])
        ->and(StaffAccess::hiddenAreasFor(staffWithRole('housekeeping')))->toBe(['plano'])
        ->and(StaffAccess::labelFor('housekeeping'))->toBe('Camaristas');
});

it('crea un rol propio asignable y lo elimina solo si nadie lo tiene', function () {
    $role = StaffAccess::save(null, [
        'label' => 'Recepción de noche',
        'permissions' => ['reservations.view'],
        'hidden_areas' => [],
    ]);

    expect($role->name)->toBe('recepcion-de-noche')
        ->and(StaffAccess::assignableRoles())->toContain('recepcion-de-noche')
        ->and(StaffAccess::assignableRoles())->not->toContain('agent');

    $user = staffWithRole('recepcion-de-noche');
    expect(fn () => StaffAccess::delete($role))->toThrow(ValidationException::class);

    $user->syncRoles(['front-desk']);
    StaffAccess::delete($role);
    expect(Role::where('name', 'recepcion-de-noche')->exists())->toBeFalse();
});

it('no deja editar al propietario, al bot ni repetir nombres', function () {
    $data = ['label' => 'X', 'permissions' => [], 'hidden_areas' => []];

    expect(fn () => StaffAccess::save(Role::findByName('owner'), $data))->toThrow(ValidationException::class)
        ->and(fn () => StaffAccess::save(Role::findByName('agent'), $data))->toThrow(ValidationException::class)
        ->and(fn () => StaffAccess::save(null, [...$data, 'label' => 'gerente']))->toThrow(ValidationException::class)
        ->and(fn () => StaffAccess::delete(Role::findByName('manager')))->toThrow(ValidationException::class);
});

it('administrar usuarios no alcanza para nombrar propietarios', function () {
    // El dueño le puede dar "Administrar usuarios" al gerente; aun así el
    // gerente no se puede hacer dueño ni tocar la cuenta del dueño.
    Role::findByName('manager')->givePermissionTo('users.manage');
    $gerente = staffWithRole('manager');
    $dueno = staffWithRole('owner');

    $request = Request::create('/api/users/'.$gerente->id, 'PATCH', ['role' => 'owner']);
    $request->setUserResolver(fn () => $gerente);
    expect(app(UserController::class)->update($request, $gerente)->getStatusCode())->toBe(403);

    $request = Request::create('/api/users/'.$dueno->id, 'PATCH', ['password' => 'otra-clave-123']);
    $request->setUserResolver(fn () => $gerente);
    expect(app(UserController::class)->update($request, $dueno)->getStatusCode())->toBe(403);

    // El dueño sí puede.
    $request = Request::create('/api/users/'.$gerente->id, 'PATCH', ['role' => 'owner']);
    $request->setUserResolver(fn () => $dueno);
    expect(app(UserController::class)->update($request, $gerente)->getStatusCode())->toBe(200)
        ->and($gerente->fresh()->hasRole('owner'))->toBeTrue();
});
