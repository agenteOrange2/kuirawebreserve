<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON legible en español: sin ó ni barras escapadas.
 *
 * No es cosmético. Estas respuestas se le pegan enteras al prompt del bot y
 * vuelven como resultado de cada herramienta: escapadas, el modelo lee
 * "habitación" en vez de "habitación" — gasta el triple de tokens en
 * cada acento y a los modelos chicos les cuesta devolver el texto limpio.
 */
class UnescapedJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $response->setEncodingOptions(
                $response->getEncodingOptions() | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        return $response;
    }
}
