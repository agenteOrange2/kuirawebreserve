<?php

namespace App\Console\Commands;

use App\Services\Admin\PlatformAlertScanner;
use Illuminate\Console\Command;

/**
 * Revisa los hoteles y actualiza los avisos del panel de plataforma
 * (/admin/notificaciones). Corre en la central, no por tenant: el escáner
 * entra a la base de cada hotel por su cuenta.
 */
class ScanPlatformAlerts extends Command
{
    protected $signature = 'admin:scan-alerts';

    protected $description = 'Actualiza los avisos del panel de plataforma (cuota de IA, registros nuevos, canales mudos...)';

    public function handle(PlatformAlertScanner $scanner): int
    {
        $result = $scanner->scan();

        $this->info("Abiertos: {$result['open']} · nuevos: {$result['created']} · resueltos: {$result['resolved']}");

        return self::SUCCESS;
    }
}
