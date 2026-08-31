<?php

namespace App\Console\Commands;

use App\Models\WaitlistEntry;
use Illuminate\Console\Command;

/**
 * Higiene de la lista de espera (módulo lista-espera): una entrada cuyas
 * fechas ya pasaron dejó de ser un prospecto — nadie va a reservar para
 * ayer. Se marca expirada para que la lista muestre solo lo accionable;
 * las convertidas no se tocan (son el historial de lo que sí cerró).
 * Correr por tenant: tenants:run.
 */
class ExpireWaitlistEntries extends Command
{
    protected $signature = 'waitlist:expire';

    protected $description = 'Marca expiradas las entradas de la lista de espera cuyas fechas ya pasaron';

    public function handle(): int
    {
        $expired = WaitlistEntry::query()
            ->whereIn('status', [WaitlistEntry::STATUS_WAITING, WaitlistEntry::STATUS_NOTIFIED])
            ->whereDate('ends_at', '<', now()->toDateString())
            ->update(['status' => WaitlistEntry::STATUS_EXPIRED, 'updated_at' => now()]);

        $this->info("Entradas de lista de espera expiradas: {$expired}");

        return self::SUCCESS;
    }
}
