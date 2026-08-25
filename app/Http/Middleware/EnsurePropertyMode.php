<?php

namespace App\Http\Middleware;

use App\Services\PropertyMode;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate por modo de operación de la propiedad (docs/spec-modo-motel.md):
 * `mode:motel` en un grupo de rutas lo deja existir SOLO en moteles.
 *
 * Es hermano de EnsureModuleEnabled pero responde a otra pregunta: el módulo
 * dice qué compró el hotel, el modo dice qué clase de negocio es. Un registro
 * de vehículos no se le vende a un hotel, sencillamente no le aplica — por
 * eso la página de bloqueo no ofrece "mejorar tu plan", solo explica que el
 * modo lo administra la plataforma.
 *
 * Acepta varios modos: `mode:motel,both`. Ojo con la firma variádica —
 * Laravel parte los parámetros por coma y los pasa como argumentos sueltos,
 * así que un `string $modes` se quedaría solo con el primero.
 */
class EnsurePropertyMode
{
    public function handle(Request $request, Closure $next, string ...$modes): Response
    {
        $current = app(PropertyMode::class)->mode();

        if (in_array($current, $modes, true)) {
            return $next($request);
        }

        $label = PropertyMode::labelFor($current);

        if ($request->expectsJson() || $request->is('api/*')) {
            abort(403, "Esta herramienta no aplica al modo de operación {$label}.");
        }

        return Inertia::render('tenant/FeatureNotForMode', [
            'currentMode' => $current,
            'currentModeLabel' => $label,
            'allowedModeLabels' => array_map(
                fn (string $mode) => PropertyMode::labelFor($mode),
                $modes,
            ),
        ])->toResponse($request)->setStatusCode(403);
    }
}
