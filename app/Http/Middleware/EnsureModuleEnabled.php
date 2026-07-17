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

        return Inertia::render('tenant/ModuleDisabled', [
            'module' => [
                'key' => $module,
                'label' => $label,
                'description' => config("modules.{$module}.description"),
            ],
        ])->toResponse($request)->setStatusCode(403);
    }
}
