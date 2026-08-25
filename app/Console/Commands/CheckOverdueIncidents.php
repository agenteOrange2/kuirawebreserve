<?php

namespace App\Console\Commands;

use App\Models\Incident;
use App\Models\StaffNotification;
use App\Services\StaffNotifier;
use Illuminate\Console\Command;

/**
 * Incidencias que se pasaron de su tiempo objetivo.
 *
 * Es el reloj que le faltaba al módulo: hasta ahora un ticket podía vivir
 * abierto indefinidamente sin que nadie se enterara (caso real
 * motellacupula, 2026-08-21: fuga de agua de prioridad alta con dos semanas
 * abierta y la habitación vendiéndose).
 *
 * Avisa UNA vez por ticket (`overdue_notified_at`): el comando corre cada 15
 * minutos y una campana repetida se vuelve ruido que se ignora — que es
 * exactamente el problema que viene a resolver.
 *
 * Correr por tenant: tenants:run.
 */
class CheckOverdueIncidents extends Command
{
    protected $signature = 'incidents:check-overdue';

    protected $description = 'Avisa al staff de las incidencias que pasaron su tiempo objetivo de atención';

    public function handle(StaffNotifier $notifier): int
    {
        $overdue = Incident::query()
            ->with('room:id,number')
            ->whereIn('status', [Incident::STATUS_OPEN, Incident::STATUS_IN_PROGRESS])
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->whereNull('overdue_notified_at')
            ->get();

        foreach ($overdue as $incident) {
            $notifier->notify(
                type: StaffNotification::TYPE_INCIDENT,
                title: 'Falla sin atender',
                body: $this->body($incident),
                url: '/incidencias/'.$incident->id,
                subject: $incident,
            );

            $incident->forceFill(['overdue_notified_at' => now()])->saveQuietly();
        }

        if ($overdue->isNotEmpty()) {
            $this->info("Avisadas {$overdue->count()} incidencia(s) vencida(s).");
        }

        return self::SUCCESS;
    }

    protected function body(Incident $incident): string
    {
        $where = $incident->room ? "Habitación {$incident->room->number}" : 'Área general';
        $hours = $incident->ageHours();
        $age = $hours >= 48
            ? intdiv($hours, 24).' días'
            : $hours.' horas';

        return "{$where}: {$incident->title} — lleva {$age} sin resolverse ({$incident->priorityLabel()}).";
    }
}
