<?php

namespace App\Console\Commands;

use App\Services\VehicleRegistry;
use Illuminate\Console\Command;

/**
 * Llena el registro de vehículos con lo que ya estaba capturado antes de que
 * existiera: las placas sueltas de las estancias y los vehículos del CRM.
 *
 * Vive como comando y no dentro de la migración a propósito: una migración
 * que llama a modelos se rompe en cuanto el modelo cambia. Se corre por
 * tenant con `php artisan tenants:run vehicles:backfill`.
 */
class BackfillVehicles extends Command
{
    protected $signature = 'vehicles:backfill';

    protected $description = 'Crea las fichas de vehículo de las placas ya registradas en estancias y en el CRM.';

    public function handle(VehicleRegistry $registry): int
    {
        $created = $registry->backfill();

        $this->info("Fichas de vehículo creadas: {$created}");

        return self::SUCCESS;
    }
}
