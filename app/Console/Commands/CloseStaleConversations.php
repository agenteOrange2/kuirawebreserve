<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Models\Conversation;
use App\Services\Agent\AgentBrain;
use App\Services\ReservationPolicy;
use Illuminate\Console\Command;

/**
 * Cierra solas las conversaciones que llegaron, preguntaron y se fueron.
 *
 * La bandeja se había vuelto un almacén: en cabañas entraron 985
 * conversaciones en 30 días y el personal alcanzó a marcar 37 como
 * resueltas, así que "abierta" ya no distinguía a nadie. Este comando deja
 * en la bandeja SOLO a quien vale la pena perseguir.
 *
 * NO se cierra (cada exclusión tiene su motivo):
 * - Cotización real: la herramienta confirmó disponibilidad para fechas
 *   concretas (AgentBrain::REAL_QUOTE). Es lo único que distingue a quien
 *   está comprando de quien pidió la lista de precios y se fue.
 * - Tiene apartado o reserva ganada (lead hold/won), o una reserva viva
 *   colgando: ahí hay dinero o una habitación de por medio.
 * - La tomó una persona del hotel (bot apagado o asignada a alguien): esa
 *   conversación es suya y la cierra quien la atendió.
 * - Espera a un humano (status pending): justo lo contrario de cerrada.
 *
 * Cerrar no borra nada ni avisa al huésped: es un cambio de estado, y si la
 * persona vuelve a escribir los webhooks la reabren (ver
 * MetaWebhookController y sus pares de Evolution, Telegram, TikTok y el
 * webchat). Correr por tenant: tenants:run.
 */
class CloseStaleConversations extends Command
{
    protected $signature = 'conversations:close-stale
        {--days= : Días de silencio (default: el ajuste del hotel, 2)}
        {--dry-run : Solo cuenta, no cierra}';

    protected $description = 'Cierra las conversaciones sin cotización que llevan días calladas';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? max(1, (int) $this->option('days'))
            : app(ReservationPolicy::class)->inboxAutoCloseDays();

        if ($days === 0) {
            $this->info('Cierre automático apagado para este hotel.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');
        $closed = 0;

        $this->candidates($cutoff)->chunkById(100, function ($conversations) use (&$closed, $dryRun): void {
            foreach ($conversations as $conversation) {
                if (! $dryRun) {
                    $conversation->update(['status' => Conversation::STATUS_RESOLVED]);
                    // Deja rastro de que lo cerró el sistema y no una
                    // persona; también evita contarla dos veces si alguien
                    // la reabre a mano y se vuelve a quedar callada.
                    $conversation->markFollowup('auto_closed');
                }

                $closed++;
            }
        });

        $this->info($dryRun
            ? "{$closed} conversación(es) se cerrarían con {$days} día(s) de silencio."
            : "{$closed} conversación(es) cerradas por silencio de {$days} día(s).");

        return self::SUCCESS;
    }

    /**
     * Las que preguntaron y se fueron. Va sin `activity_log` a propósito: la
     * primera corrida barre cientos de conversaciones viejas de golpe y
     * llenaría la bitácora del hotel con ruido (mismo criterio que el
     * importador de reservas).
     */
    protected function candidates(\Carbon\CarbonInterface $cutoff): \Illuminate\Database\Eloquent\Builder
    {
        return Conversation::query()
            ->where('status', Conversation::STATUS_OPEN)
            ->where('bot_enabled', true)
            ->whereNull('assigned_to')
            ->whereNull('archived_at')
            // Sin mensajes todavía (hilos que abrió el bot y nadie contestó)
            // el reloj corre desde que nació la conversación.
            ->where(fn ($q) => $q
                ->where('last_message_at', '<', $cutoff)
                ->orWhere(fn ($q2) => $q2->whereNull('last_message_at')->where('created_at', '<', $cutoff)))
            ->whereNotIn('lead_status', [Conversation::LEAD_HOLD, Conversation::LEAD_WON])
            // El huésped llegó a una cotización real: se queda en la bandeja
            // aunque lleve semanas callado.
            ->where(fn ($q) => $q
                ->whereNull('followups')
                ->orWhereJsonDoesntContainKey('followups->'.AgentBrain::REAL_QUOTE))
            // Cinturón y tirantes: una reserva viva colgando manda sobre
            // cualquier lectura del embudo.
            ->whereDoesntHave('reservation', fn ($q) => $q->whereIn('status', [
                ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::CheckedIn,
            ]))
            ->orderBy('id');
    }
}
