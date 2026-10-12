<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate de módulos por plan (spec-plan-maestro E1): `module:pos` en un grupo
 * de rutas lo apaga por completo para hoteles cuyo plan no incluye el módulo
 * (salvo override del admin). Página amable para navegación, 403 JSON para
 * la API. Apagado NO borra datos: solo desaparece el área.
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $tenant = tenant();

        if ($tenant && $tenant->hasModule($module)) {
            return $next($request);
        }

        $label = config("modules.{$module}.label", $module);

        if ($request->expectsJson() || $request->is('api/*')) {
            abort(403, "El módulo {$label} no está incluido en tu plan.");
        }

        // Un huésped sin sesión (wizard de grupos o experiencias, menú,
        // encuesta) NO es el hotel: la página de "no incluido en tu plan"
        // va dentro del panel y le enseñaba el menú lateral, el nombre del
        // plan y "Volver al dashboard". Al wizard de grupos o experiencias
        // se llega desde el sitio del hotel: se le manda al de habitaciones.
        if (! $request->user()) {
            if ($request->is('reservar/*') && $tenant?->hasModule('motor-web')) {
                return redirect('/reservar');
            }

            abort(404);
        }

        return Inertia::render('tenant/ModuleDisabled', [
            'module' => [
                'key' => $module,
                'label' => $label,
                'description' => config("modules.{$module}.description"),
            ],
        ])->toResponse($request)->setStatusCode(403);
    }
}
