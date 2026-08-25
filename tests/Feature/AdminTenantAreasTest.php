<?php

use App\Http\Controllers\Admin\TenantAreaController;
use App\Models\Tenant;
use Illuminate\Support\Facades\Route;

/**
 * La ficha del hotel se partió en áreas con URL propia
 * (/admin/tenants/{id}/…). Estas pruebas cuidan la estructura: que las
 * siete rutas existan, que las URLs viejas de agentes-ia sigan llevando a
 * algún lado, y que la identidad del hotel viaje a todas las sub-vistas
 * (es lo que pinta la cabecera compartida).
 */
it('cada área del hotel tiene su propia URL', function () {
    $areas = [
        'admin.tenants.show' => 'admin/tenants/{tenant}',
        'admin.tenants.plan' => 'admin/tenants/{tenant}/plan',
        'admin.tenants.modules' => 'admin/tenants/{tenant}/modulos',
        'admin.tenants.team' => 'admin/tenants/{tenant}/equipo',
        'admin.tenants.assistant' => 'admin/tenants/{tenant}/asistente',
        'admin.tenants.channels' => 'admin/tenants/{tenant}/canales',
        'admin.tenants.payments' => 'admin/tenants/{tenant}/cobros',
    ];

    foreach ($areas as $name => $uri) {
        expect(Route::has($name))->toBeTrue("falta la ruta {$name}")
            ->and(Route::getRoutes()->getByName($name)->uri())->toBe($uri);
    }
});

it('las sub-vistas no se comen el id del tenant', function () {
    // "plan" y "modulos" van declaradas antes de tenants/{tenant}: si se
    // invirtiera el orden, /admin/tenants/plan abriría la ficha de un
    // hotel llamado "plan".
    $rutas = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'admin/tenants/'))
        ->filter(fn ($r) => in_array('GET', $r->methods(), true))
        ->values();

    $primeraFicha = $rutas->search(fn ($r) => $r->uri() === 'admin/tenants/{tenant}');
    $primerArea = $rutas->search(fn ($r) => $r->uri() === 'admin/tenants/{tenant}/plan');

    expect($primerArea)->toBeLessThan($primeraFicha);
});

it('las URLs viejas de agentes-ia siguen llevando al hotel', function () {
    foreach (['admin.ai.tenants.context', 'admin.ai.channels'] as $name) {
        expect(Route::has($name))->toBeTrue("falta el redirect {$name}");
    }
});

it('la identidad del hotel viaja a todas las áreas', function () {
    $tenant = new Tenant([
        'id' => 'hoteldemo',
        'name' => 'Hotel Demo',
        'plan' => 'profesional',
    ]);

    $shell = TenantAreaController::shell($tenant);

    expect($shell['tenant']['id'])->toBe('hoteldemo')
        ->and($shell['tenant']['name'])->toBe('Hotel Demo')
        ->and($shell['tenant']['plan_label'])->toBe(config('plans.profesional.label'))
        ->and($shell['tenant']['suspended'])->toBeFalse()
        // El catálogo de planes va también: la cabecera trae el modal de
        // "Editar hotel" en todas las áreas.
        ->and($shell['plans'])->toHaveCount(count(config('plans')));
});

it('un hotel sin nombre se muestra por su subdominio', function () {
    $shell = TenantAreaController::shell(new Tenant(['id' => 'sinnombre', 'plan' => 'esencial']));

    expect($shell['tenant']['name'])->toBe('sinnombre');
});
