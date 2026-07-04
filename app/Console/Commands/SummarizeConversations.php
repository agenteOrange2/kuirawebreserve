<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Services\Agent\AgentBrain;
use Illuminate\Console\Command;

/**
 * Resumen rodante: cuando una conversación con actividad queda inactiva,
 * condensa lo hablado para que el bot lo "recuerde" aunque el historial
 * crezca o el huésped regrese días después. Correr por tenant: tenants:run.
 */
class SummarizeConversations extends Command
{
    protected $signature = 'conversations:summarize {--limit=10 : Máximo de conversaciones por corrida}';

    protected $description = 'Genera el resumen rodante de conversaciones que quedaron inactivas';

    public function handle(AgentBrain $brain): int
    {
        if (! $brain->isConfigured()) {
            $this->info('Sin proveedor LLM disponible; nada que resumir.');

            return self::SUCCESS;
        }

        // Inactivas (10 min sin mensajes, máx. 48 h) con material nuevo
        // suficiente desde el último resumen.
        $candidates = Conversation::query()
            ->whereBetween('last_message_at', [now()->subHours(48), now()->subMinutes(10)])
            ->whereHas('messages', fn ($q) => $q
                ->whereIn('sender_type', ['visitor', 'bot', 'staff'])
                ->whereRaw('messages.id > COALESCE(conversations.summary_message_id, 0)'), '>=', 6)
            ->orderBy('last_message_at')
            ->take((int) $this->option('limit'))
            ->get();

        $done = 0;

        foreach ($candidates as $conversation) {
            if ($brain->summarize($conversation) !== null) {
                $done++;
            }
        }

        $this->info("Conversaciones resumidas: {$done}");

        return self::SUCCESS;
    }
}
