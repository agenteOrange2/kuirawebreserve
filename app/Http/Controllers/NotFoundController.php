<?php

namespace App\Http\Controllers;

use App\Support\ErrorPage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ruta de respaldo: cualquier dirección que no exista.
 *
 * Existe para que el 404 se vea DENTRO del panel. Cuando nadie atiende una
 * URL, Laravel la rechaza durante el ruteo y nunca llega a correr el grupo
 * `web`: sin sesión, quien está trabajando en el panel vería la pantalla
 * suelta de la marca en lugar de su menú. Al declararla como ruta de
 * verdad, el 404 pasa por sesión e Inertia y el empleado conserva el
 * contexto para irse a otra sección de un clic.
 *
 * Lo que pide JSON se sigue rechazando como 404 normal: la API del bot y
 * las llamadas del propio panel no saben leer una pantalla.
 */
class NotFoundController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if ($request->expectsJson() || $request->isJson() || $request->is('api/*')) {
            abort(404);
        }

        return Inertia::render('Error', ErrorPage::payload($request, 404))
            ->toResponse($request)
            ->setStatusCode(404);
    }
}
