<?php

namespace App\Console\Commands;

use App\Actions\Payments\ReturnGuaranteeOnExit;
use App\Models\Stay;
use Illuminate\Console\Command;

/**
 * Acomoda las fianzas que la salida automática dejó colgadas antes de que
 * aplicara la regla "se devuelve al salir si no quedó nada por cobrar"
 * (cabañas, desde el 20/09). Sin --apply solo lista: son registros de dinero
 * y se revisan antes de escribirlos. La devolución se fecha en la salida de
 * cada estancia, que es cuando el dinero se le regresó al huésped.
 *
 * Correr por tenant: tenants:run guarantees:return-auto-closed --tenants=…
 */
class ReturnAutoClosedGuarantees extends Command
{
    protected $signature = 'guarantees:return-auto-closed {--apply : Registrar las devoluciones (sin esto solo lista)}';

    protected $description = 'Registra como devueltas las fianzas de salidas automáticas sin saldo pendiente';

    public function handle(ReturnGuaranteeOnExit $action): int
    {
        $stays = Stay::query()
            ->where('status', Stay::STATUS_COMPLETED)
            ->whereNotNull('auto_closed_at')
            ->whereNotNull('check_out_at')
            ->with('room:id,number')
            ->orderBy('check_out_at')
            ->get()
            ->filter(fn (Stay $stay) => $action->liveGuarantees($stay)->isNotEmpty());

        $rows = [];
        $toReturn = 0.0;
        $withBalance = 0;

        foreach ($stays as $stay) {
            $live = round((float) $action->liveGuarantees($stay)->sum(fn ($p) => $p->refundableAmount()), 2);
            $methods = $action->liveGuarantees($stay)->pluck('method')->unique()->implode(', ');
            $pending = $stay->folio()['grand_pending'];
            $applies = $pending <= 0;

            $rows[] = [
                $stay->id,
                $stay->room?->number,
                $stay->check_out_at?->format('d/m/Y H:i'),
                number_format($live, 2),
                $methods,
                number_format($pending, 2),
                $applies ? 'se devuelve' : 'con saldo: por resolver',
            ];

            if ($applies) {
                $toReturn += $live;

                if ($this->option('apply')) {
                    $action->handle($stay, $stay->check_out_at);
                }
            } else {
                $withBalance++;
            }
        }

        $this->table(['Estancia', 'Hab.', 'Salida', 'Fianza viva', 'Método', 'Saldo', 'Qué pasa'], $rows);

        $this->info(sprintf(
            '%s: %d fianzas por $%s se registran como devueltas; %d quedan por resolver (tienen saldo).',
            $this->option('apply') ? 'Aplicado' : 'Solo revisión',
            count($rows) - $withBalance,
            number_format($toReturn, 2),
            $withBalance,
        ));

        return self::SUCCESS;
    }
}
