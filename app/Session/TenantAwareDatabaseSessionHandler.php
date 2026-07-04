<?php

namespace App\Session;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * Handler de sesiones que resuelve la conexión de DB en cada consulta en vez
 * de fijarla al construirse.
 *
 * Necesario porque Laravel instancia los controllers al recolectar middleware
 * (Route::gatherMiddleware -> controllerMiddleware), ANTES de que corra
 * InitializeTenancyByDomain. Los controllers de Fortify inyectan StatefulGuard
 * en el constructor, lo que resuelve session.store en ese momento y dejaría el
 * handler amarrado a la DB central aun en dominios de tenant. Con la conexión
 * diferida, la sesión siempre cae en la DB correcta: central en
 * kuirawebreserve.la y la del tenant en sus subdominios.
 */
class TenantAwareDatabaseSessionHandler extends DatabaseSessionHandler
{
    protected function getQuery()
    {
        return $this->container->make('db')
            ->connection(config('session.connection'))
            ->table($this->table)
            ->useWritePdo();
    }
}
