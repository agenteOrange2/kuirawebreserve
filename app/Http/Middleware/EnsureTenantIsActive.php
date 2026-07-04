<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea el acceso al panel de un hotel suspendido desde la plataforma
 * (sin borrar sus datos). En contexto central no hace nada, por lo que es
 * seguro en rutas universales (Fortify).
 */
class EnsureTenantIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (tenancy()->initialized && tenant()->isSuspended()) {
            abort(403, 'Este hotel está suspendido. Contacta al administrador de la plataforma.');
        }

        return $next($request);
    }
}
