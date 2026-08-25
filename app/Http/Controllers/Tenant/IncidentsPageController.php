<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Incidencias de mantenimiento: pendientes primero, con filtros por
 * estado/prioridad/habitación y los catálogos que alimentan el formulario.
 */
class IncidentsPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $request->validate([
            'status' => ['nullable', 'in:active,all,open,in_progress,resolved'],
            'priority' => ['nullable', 'in:low,medium,high'],
            'room' => ['nullable', 'integer'],
            'category' => ['nullable', 'in:'.implode(',', array_keys(Incident::CATEGORIES))],
            'q' => ['nullable', 'string', 'max:80'],
            // 'none' = sin responsable, que es el filtro que importa:
            // lo que nadie tomó.
            'assignee' => ['nullable', 'string'],
            'source' => ['nullable', 'in:staff,guest'],
            'overdue' => ['nullable', 'boolean'],
        ]);

        $status = $request->query('status', 'active');
        $search = trim($request->string('q')->toString());
        $assignee = $request->query('assignee');
        $source = $request->query('source');
        $overdue = $request->boolean('overdue');

        $incidents = Incident::query()
            ->with(['room:id,number,name,status', 'reporter:id,name', 'assignee:id,name', 'resolver:id,name', 'technician:id,name', 'media'])
            ->when($status === 'active', fn ($q) => $q->active())
            ->when(in_array($status, Incident::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->when($request->query('priority'), fn ($q, $priority) => $q->where('priority', $priority))
            ->when($request->query('category'), fn ($q, $category) => $q->where('category', $category))
            ->when($request->integer('room'), fn ($q, $room) => $q->where('room_id', $room))
            ->when($search !== '', fn ($q) => $q->search($search))
            ->when($assignee === 'none', fn ($q) => $q->whereNull('assigned_to'))
            ->when($assignee !== null && $assignee !== 'none', fn ($q) => $q->where('assigned_to', (int) $assignee))
            ->when($source, fn ($q, $source) => $q->where('source', $source))
            ->when($overdue, fn ($q) => $q
                ->whereIn('status', [Incident::STATUS_OPEN, Incident::STATUS_IN_PROGRESS])
                ->whereNotNull('due_at')
                ->where('due_at', '<=', now()))
            // CASE y no field(): field() es de MySQL y truena en SQLite,
            // que es donde corren las pruebas.
            ->orderByRaw("case status when 'open' then 0 when 'in_progress' then 1 else 2 end")
            ->orderByRaw("case priority when 'high' then 0 when 'medium' then 1 else 2 end")
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Incident $incident) => self::present($incident));

        return Inertia::render('tenant/incidents/Index', [
            'incidents' => $incidents,
            'categories' => Incident::CATEGORIES,
            'kpis' => [
                'open' => Incident::query()->where('status', Incident::STATUS_OPEN)->count(),
                'in_progress' => Incident::query()->where('status', Incident::STATUS_IN_PROGRESS)->count(),
                'resolved_month' => Incident::query()
                    ->where('status', Incident::STATUS_RESOLVED)
                    ->where('resolved_at', '>=', now()->startOfMonth())
                    ->count(),
                // Pendientes que ya pasaron su tiempo objetivo: es el número
                // que dice si el hotel va al corriente o arrastrando fallas.
                'overdue' => Incident::query()
                    ->whereIn('status', [Incident::STATUS_OPEN, Incident::STATUS_IN_PROGRESS])
                    ->whereNotNull('due_at')
                    ->where('due_at', '<=', now())
                    ->count(),
            ],
            'sla' => app(\App\Services\IncidentPolicy::class)->hours(),
            'rooms' => Room::query()
                ->orderBy('number')
                ->get(['id', 'number', 'name', 'status'])
                ->map(fn (Room $room) => [
                    'id' => $room->id,
                    'label' => trim($room->number.' '.($room->name ?? '')),
                    'status' => $room->status->getMorphClass(),
                ]),
            'staff' => User::query()->orderBy('name')->get(['id', 'name']),
            // Quién repara (personal de casa y proveedores): solo con
            // incidencias avanzadas, igual que responsables y costos.
            'technicians' => (tenant() === null || tenant()->hasModule('incidencias-avanzado'))
                ? \App\Models\Technician::query()->active()->ordered()->get()
                    ->map(fn (\App\Models\Technician $t) => TechnicianController::present($t))
                : [],
            'filters' => [
                'status' => $status,
                'priority' => $request->query('priority'),
                'room' => $request->integer('room') ?: null,
                // Faltaba: sin esto el select de tipo de falla se veía en
                // blanco aunque el filtro siguiera aplicado.
                'category' => $request->query('category'),
                'q' => $search,
                'assignee' => $assignee,
                'source' => $source,
                'overdue' => $overdue,
            ],
            'canManage' => $request->user()->can('rooms.update-status'),
            'canDelete' => $request->user()->can('rooms.manage'),
            // Incidencias avanzadas (Empresarial): responsables y reportes.
            'advanced' => tenant() === null || tenant()->hasModule('incidencias-avanzado'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Incident $incident): array
    {
        return [
            'id' => $incident->id,
            'room_id' => $incident->room_id,
            'room' => $incident->room
                ? trim($incident->room->number.' '.($incident->room->name ?? ''))
                : null,
            'room_status' => $incident->room?->status->getMorphClass(),
            'title' => $incident->title,
            'category' => $incident->category,
            'category_label' => $incident->categoryLabel(),
            'guest_reported' => $incident->isGuestReported(),
            'description' => $incident->description,
            'priority' => $incident->priority,
            'priority_label' => $incident->priorityLabel(),
            'status' => $incident->status,
            'status_label' => $incident->statusLabel(),
            'reported_by' => $incident->reporter?->name,
            'assigned_to' => $incident->assigned_to,
            'assignee' => $incident->assignee?->name,
            'resolved_by' => $incident->resolver?->name,
            'resolved_at' => $incident->resolved_at?->format('d/m/Y H:i'),
            'resolution_notes' => $incident->resolution_notes,
            'created_at' => $incident->created_at->format('d/m/Y H:i'),
            // Tiempo objetivo: lo que permite marcar en rojo lo que ya se
            // pasó, en vez de que todo se vea igual de urgente.
            'due_at' => $incident->due_at?->format('d/m/Y H:i'),
            'overdue' => $incident->isOverdue(),
            'age_hours' => $incident->ageHours(),
            'cost' => $incident->cost !== null ? (float) $incident->cost : null,
            'technician_id' => $incident->technician_id,
            'technician' => $incident->technician?->name,
            'stay_id' => $incident->stay_id,
            'photos' => $incident->getMedia('photos')->map(fn (Media $media) => [
                'id' => $media->id,
                'url' => route('tenant.incidents.photos.show', ['incident' => $incident->id, 'media' => $media->id], false),
            ])->values(),
        ];
    }
}
