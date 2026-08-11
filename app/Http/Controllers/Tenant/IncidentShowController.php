<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Detalle de una incidencia: ficha completa, fotos de evidencia y la
 * línea de tiempo (quién la reportó, cambios de estado/responsable y los
 * movimientos del semáforo que disparó) armada desde el activity log.
 */
class IncidentShowController extends Controller
{
    public function __invoke(Request $request, Incident $incident): Response
    {
        $incident->load(['room:id,number,name,status', 'reporter:id,name', 'assignee:id,name', 'resolver:id,name', 'media']);

        return Inertia::render('tenant/incidents/Show', [
            'incident' => IncidentsPageController::present($incident),
            'timeline' => $this->timeline($incident),
            'staff' => User::query()->orderBy('name')->get(['id', 'name']),
            'canManage' => $request->user()->can('rooms.update-status'),
            'canDelete' => $request->user()->can('rooms.manage'),
            // Incidencias avanzadas (Empresarial): responsables y reportes.
            'advanced' => tenant() === null || tenant()->hasModule('incidencias-avanzado'),
        ]);
    }

    /**
     * Historial legible: entradas del activity log (con autor) + los
     * movimientos del semáforo ligados a esta incidencia.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function timeline(Incident $incident): array
    {
        $entries = Activity::query()
            ->where('log_name', 'incident')
            ->where('subject_type', $incident->getMorphClass())
            ->where('subject_id', $incident->id)
            ->with('causer:id,name')
            ->get()
            ->map(fn (Activity $activity) => [
                'at' => $activity->created_at,
                'date' => $activity->created_at->format('d/m/Y H:i'),
                'by' => $activity->causer?->name,
                'icon' => $activity->event === 'created' ? 'Plus' : 'Pencil',
                'lines' => $activity->event === 'created'
                    ? ['Incidencia reportada']
                    : $this->changeLines($activity),
            ]);

        // Movimientos del semáforo que esta incidencia disparó (contexto
        // que deja ChangeRoomStatus al reportar/resolver).
        $roomMoves = $incident->room
            ? $incident->room->statusLogs()
                ->whereNotNull('context')
                ->with('changedBy:id,name')
                ->get()
                ->filter(fn ($log) => (int) ($log->context['incident_id'] ?? 0) === $incident->id)
                ->map(fn ($log) => [
                    'at' => $log->created_at,
                    'date' => $log->created_at->format('d/m/Y H:i'),
                    'by' => $log->changedBy?->name,
                    'icon' => 'Map',
                    'lines' => [
                        'Habitación '.($log->to_status === 'maintenance'
                            ? 'puesta en mantenimiento'
                            : 'devuelta a '.\App\Enums\RoomStatus::tryFrom($log->to_status)?->label()),
                    ],
                ])
            : collect();

        return $entries->concat($roomMoves)
            ->sortByDesc('at')
            ->map(fn (array $entry) => collect($entry)->except('at')->all())
            ->values()
            ->all();
    }

    /**
     * Cambios de una edición en palabras ("Estado: Abierta → Resuelta").
     *
     * @return array<int, string>
     */
    protected function changeLines(Activity $activity): array
    {
        $new = $activity->properties['attributes'] ?? [];
        $old = $activity->properties['old'] ?? [];

        $userNames = User::query()
            ->whereIn('id', array_filter([$new['assigned_to'] ?? null, $old['assigned_to'] ?? null]))
            ->pluck('name', 'id');

        $statusLabels = ['open' => 'Abierta', 'in_progress' => 'En proceso', 'resolved' => 'Resuelta'];
        $priorityLabels = ['low' => 'Baja', 'medium' => 'Media', 'high' => 'Alta'];

        $lines = [];

        foreach ($new as $field => $value) {
            $before = $old[$field] ?? null;

            $lines[] = match ($field) {
                'status' => 'Estado: '.($statusLabels[$before] ?? '—').' → '.($statusLabels[$value] ?? $value),
                'priority' => 'Prioridad: '.($priorityLabels[$before] ?? '—').' → '.($priorityLabels[$value] ?? $value),
                'assigned_to' => $value === null
                    ? 'Se quitó el responsable'
                    : 'Asignada a '.($userNames[$value] ?? 'alguien del equipo'),
                'title' => 'Título actualizado',
                'room_id' => 'Cambió la habitación',
                'resolution_notes' => $value === null ? null : 'Nota de resolución: '.$value,
                default => null,
            };
        }

        $lines = array_values(array_filter($lines));

        return $lines ?: ['Incidencia actualizada'];
    }
}
