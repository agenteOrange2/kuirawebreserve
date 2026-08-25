<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\Technician;
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
        $incident->load(['room:id,number,name,status', 'reporter:id,name', 'assignee:id,name', 'resolver:id,name', 'technician:id,name', 'stay.guest:id,first_name,last_name', 'media']);

        $advanced = tenant() === null || tenant()->hasModule('incidencias-avanzado');

        return Inertia::render('tenant/incidents/Show', [
            'incident' => IncidentsPageController::present($incident),
            'timeline' => $this->timeline($incident),
            'staff' => User::query()->orderBy('name')->get(['id', 'name']),
            'technicians' => $advanced
                ? Technician::query()->active()->ordered()->get()
                    ->map(fn (Technician $t) => TechnicianController::present($t))
                : [],
            // Lo que se le cobró al huésped por este daño, al lado de lo
            // que costó repararlo.
            'stay' => $this->stayBlock($incident),
            'canManage' => $request->user()->can('rooms.update-status'),
            'canDelete' => $request->user()->can('rooms.manage'),
            // Incidencias avanzadas (Empresarial): responsables y reportes.
            'advanced' => $advanced,
        ]);
    }

    /**
     * La estancia que causó el daño y lo que se le cobró: los cargos de
     * tipo daño que quedaron en su cuenta al registrar la salida.
     *
     * @return array<string, mixed>|null
     */
    protected function stayBlock(Incident $incident): ?array
    {
        $stay = $incident->stay;

        if ($stay === null) {
            return null;
        }

        $damages = collect($stay->extra_charges ?? [])
            ->filter(fn (array $line) => ($line['kind'] ?? 'damage') === 'damage')
            ->map(fn (array $line) => [
                'concept' => $line['concept'] ?? 'Daño',
                'amount' => round((float) ($line['amount'] ?? 0), 2),
            ])
            ->values();

        return [
            'id' => $stay->id,
            'guest' => trim(($stay->guest?->first_name ?? '').' '.($stay->guest?->last_name ?? '')) ?: 'Huésped sin nombre',
            'check_in_at' => $stay->check_in_at?->format('d/m/Y H:i'),
            'check_out_at' => $stay->check_out_at?->format('d/m/Y H:i'),
            'charges' => $damages->all(),
            'charged_total' => round((float) $damages->sum('amount'), 2),
        ];
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

        $technicianNames = Technician::query()
            ->whereIn('id', array_filter([$new['technician_id'] ?? null, $old['technician_id'] ?? null]))
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
                'cost' => $value === null
                    ? 'Se quitó el costo de reparación'
                    : 'Costo de reparación: $'.number_format((float) $value, 2),
                'technician_id' => $value === null
                    ? 'Se quitó a quien reparó'
                    : 'Reparó '.($technicianNames[$value] ?? 'un técnico'),
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
