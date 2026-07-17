<?php

namespace App\Console\Commands;

use App\Actions\Experiences\GenerateExperienceSessions;
use Illuminate\Console\Command;

/**
 * Mantiene lleno el horizonte de venta de las experiencias con
 * programación semanal: cada día se materializa el día nuevo que entra a
 * la ventana. Los cambios de programación desde el panel regeneran al
 * momento; esto es el rodillo diario. Correr por tenant: tenants:run.
 */
class GenerateScheduledExperienceSessions extends Command
{
    protected $signature = 'experiences:generate-sessions';

    protected $description = 'Materializa las sesiones de experiencias según su programación semanal';

    public function handle(GenerateExperienceSessions $action): int
    {
        $stats = $action->handle();

        $this->info("Sesiones creadas: {$stats['created']}, actualizadas: {$stats['updated']}, podadas: {$stats['pruned']}");

        return self::SUCCESS;
    }
}
