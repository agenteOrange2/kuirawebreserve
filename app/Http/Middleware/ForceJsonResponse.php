<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fuerza respuestas JSON en APIs consumidas por máquinas (plugin WP,
 * sitios conectados): sin esto, un abort(401/403) devuelve la página de
 * error HTML de Laravel y el consumidor no puede leer el motivo
 * (spec-plugin-wp §1.4).
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
