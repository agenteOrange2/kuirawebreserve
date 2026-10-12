<?php

namespace App\Http\Middleware;

use App\Services\StaffAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra las áreas del panel que el rol del usuario tiene ocultas
 * (/usuarios/roles). Esconder el renglón del menú no basta: la URL tecleada
 * o un enlace desde otra pantalla también tiene que dar 403. Va en los dos
 * grupos con sesión del panel del hotel; el área sale de la ruta según
 * config/panel-areas.php, así no hay que marcar ruta por ruta.
 */
class EnsurePanelArea
{
    public function handle(Request $request, Closure $next): Response
    {
        $hidden = StaffAccess::hiddenAreasFor($request->user());
        $area = $hidden ? StaffAccess::areaForRequest($request) : null;

        if ($area !== null && in_array($area, $hidden, true)) {
            $label = config("panel-areas.areas.{$area}.label", $area);

            abort(403, "Tu rol no tiene acceso a {$label}.");
        }

        return $next($request);
    }
}
