<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use Illuminate\Console\Command;

/**
 * El archivo de la bandeja es una sala de espera, no un almacén: las
 * conversaciones archivadas se eliminan definitivamente (hilo completo)
 * pasado el periodo de retención. Correr por tenant: tenants:run.
 */
class PruneArchivedConversations extends Command
{
    protected $signature = 'conversations:prune-archived {--days=30 : Días que una conversación permanece en el archivo}';

    protected $description = 'Elimina definitivamente las conversaciones archivadas con más de N días';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(1, (int) $this->option('days')));
        $pruned = 0;

        Conversation::query()
            ->whereNotNull('archived_at')
            ->where('archived_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($conversations) use (&$pruned): void {
                foreach ($conversations as $conversation) {
                    $conversation->delete(); // los mensajes caen en cascada (FK)
                    $pruned++;
                }
            });

        $this->info("{$pruned} conversación(es) archivadas eliminadas.");

        return self::SUCCESS;
    }
}
