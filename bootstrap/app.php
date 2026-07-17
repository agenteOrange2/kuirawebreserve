<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Las rutas centrales se registran una sola vez, ancladas al dominio
        // de APP_URL: si se registraran por cada central_domain se duplicarían
        // los nombres de ruta (rompe Wayfinder/Ziggy), y si quedaran sin
        // dominio harían sombra a routes/tenant.php en los subdominios.
        using: function () {
            $central = parse_url(config('app.url'), PHP_URL_HOST);

            Route::middleware('web')
                ->domain($central)
                ->group(base_path('routes/web.php'));

            // Webhooks de Meta: stateless (sin grupo 'web' = sin sesión/CSRF).
            Route::middleware('throttle:180,1')
                ->domain($central)
                ->group(base_path('routes/webhooks.php'));
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // El endpoint /broadcasting/auth debe inicializar tenancy para autorizar
    // canales con la sesión del tenant (y seguir funcionando en la central).
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['universal', 'web', \Stancl\Tenancy\Middleware\InitializeTenancyByDomain::class]],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Detrás del túnel/proxy de Cloudflare: sin esto Laravel no ve el
        // esquema https de X-Forwarded-Proto y generaría URLs http (mixed
        // content). El origen solo es alcanzable vía túnel, '*' es seguro.
        $middleware->trustProxies(at: '*');

        // Marcador para Stancl\Tenancy\Features\UniversalRoutes: las rutas con
        // este grupo funcionan tanto en dominio central como en tenants.
        $middleware->group('universal', []);

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            // Abilities de tokens Sanctum (Agent API).
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
            // Módulos por plan (spec-plan-maestro E1): module:pos, module:cobros…
            'module' => \App\Http\Middleware\EnsureModuleEnabled::class,
        ]);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
