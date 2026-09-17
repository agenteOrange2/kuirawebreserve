<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\StaffNotification;
use App\Services\Channels\StaffAlerter;
use App\Services\StaffNotifier;
use App\Services\SupportHours;
use Illuminate\Console\Command;
use Throwable;

/**
 * Traspasos sin dueño: el asistente pasa la conversación a una persona del
 * hotel, se apaga, y el huésped se queda esperando sin que nada avise.
 *
 * Esperas reales de cabañas del 13 al 15 de septiembre: 16 min, 41 min,
 * 1 h 12, 2 h 47, 5 h, 9.6 h y —la peor— 22 h para un evento de 60 personas.
 * El traspaso se marcaba en la bandeja y ahí moría.
 *
 * Dos escalones, una vez cada uno por conversación: a los 15 minutos la
 * campana del panel, a los 60 el aviso por WhatsApp al hotel (el mismo que
 * usa el traspaso). Fuera del horario de atención no se avisa: nadie va a
 * contestar y el aviso solo enseña a ignorar la campana.
 *
 * Correr por tenant: tenants:run.
 */
class EscalateHandoffs extends Command
{
    protected $signature = 'conversations:escalate-handoffs';

    protected $description = 'Avisa de las conversaciones que llevan rato esperando a una persona del hotel';

    /** Minutos de espera de cada escalón. */
    public const STEPS = [15, 60];

    /**
     * Más allá de esto ya no se avisa. Un traspaso de hace tres días no se
     * rescata haciendo vibrar el teléfono del hotel: sigue en la bandeja,
     * en el filtro "Esperando al personal", que es donde se atiende. Sin
     * este tope, la primera corrida escala TODO el rezago de golpe — pasó
     * el 2026-09-17: 10 avisos por WhatsApp a las 11:42 de la noche.
     */
    public const MAX_AGE_HOURS = 12;

    /** Tope de avisos por corrida: una ráfaga se ignora igual que el silencio. */
    public const MAX_ALERTS = 3;

    /** Nadie contesta de madrugada, y el aviso enseña a ignorar la campana. */
    public const QUIET_FROM = 21;

    public const QUIET_UNTIL = 8;

    public function handle(StaffNotifier $notifier): int
    {
        // El horario del hotel manda; si no lo configuró, al menos no se
        // avisa de noche (cabañas lo tiene apagado: isOpen() siempre dice
        // que sí, y la primera corrida sonó a las 11:42 PM).
        $hours = app(SupportHours::class);
        $hour = (int) now()->format('H');

        if (! $hours->isOpen() || $hour >= self::QUIET_FROM || $hour < self::QUIET_UNTIL) {
            return self::SUCCESS;
        }

        $pending = Conversation::query()
            ->with('guest:id,first_name,last_name')
            ->whereNull('archived_at')
            ->where('status', Conversation::STATUS_PENDING)
            ->get();

        $since = Conversation::waitingSinceFor($pending->pluck('id')->all());
        $avisados = 0;

        foreach ($pending as $conversation) {
            $waiting = $since[$conversation->id] ?? null;

            if ($waiting === null) {
                continue;
            }

            $minutes = (int) $waiting->diffInMinutes(now());

            // Rezago viejo: se ve en la bandeja, no se grita por WhatsApp.
            if ($minutes > self::MAX_AGE_HOURS * 60) {
                continue;
            }

            if ($avisados >= self::MAX_ALERTS) {
                break;
            }

            foreach (self::STEPS as $step) {
                if ($minutes < $step) {
                    continue;
                }

                $key = 'handoff-escalation:'.$step;

                if ($conversation->followupSent($key)) {
                    continue;
                }

                $conversation->markFollowup($key);
                $avisados++;

                $quien = $conversation->guest?->full_name ?: ($conversation->contact_name ?: 'Un huésped');
                $espera = $minutes >= 60 ? intdiv($minutes, 60).' h '.($minutes % 60).' min' : $minutes.' min';

                // A los 15 minutos basta la campana del panel. A la hora,
                // si nadie la abrió, el aviso sale del panel: WhatsApp y
                // correo al hotel (y su propia campana, con el mismo texto).
                if ($step >= 60) {
                    try {
                        app(StaffAlerter::class)->alert(
                            $conversation,
                            StaffAlerter::KIND_WAITING,
                            "lleva {$espera} esperando respuesta",
                        );
                    } catch (Throwable $e) {
                        report($e);
                    }

                    continue;
                }

                $notifier->notify(
                    type: StaffNotification::TYPE_MESSAGE,
                    title: 'Huésped esperando respuesta',
                    body: "{$quien} lleva {$espera} esperando a una persona del hotel en la bandeja.",
                    url: '/bandeja?esperando=1',
                    subject: $conversation,
                );
            }
        }

        $this->info("Conversaciones esperando: {$pending->count()}; avisos nuevos: {$avisados}.");

        return self::SUCCESS;
    }
}
