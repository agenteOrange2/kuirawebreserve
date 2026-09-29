<?php

namespace App\Console\Commands;

use App\Models\Stay;
use App\Services\StaffAlerts;
use Illuminate\Console\Command;

/**
 * La hora de salida de cada estancia avisa al hotel: "Hab. 104 sale a las
 * 11:00, toca revisarla". Antes nadie avisaba; el reloj la cerraba sola
 * (stays:auto-checkout) y la pasaba a sucia en silencio.
 *
 * Un aviso por estancia (StaffAlerts::checkoutDue deduplica). La ventana
 * de 30 minutos cubre una corrida perdida del scheduler sin avisar de
 * salidas de hace horas. Correr por tenant: tenants:run.
 */
class SendCheckoutAlerts extends Command
{
    protected $signature = 'stays:checkout-alerts';

    protected $description = 'Avisa al hotel de las estancias que llegaron a su hora de salida';

    public function handle(StaffAlerts $alerts): int
    {
        $sent = 0;

        Stay::query()
            ->active()
            ->whereBetween('planned_end_at', [now()->subMinutes(30), now()])
            ->with(['room', 'reservation', 'guest'])
            ->get()
            ->each(function (Stay $stay) use ($alerts, &$sent) {
                $sent += $alerts->checkoutDue($stay) ? 1 : 0;
            });

        $this->info("Avisos de salida: {$sent}");

        return self::SUCCESS;
    }
}
