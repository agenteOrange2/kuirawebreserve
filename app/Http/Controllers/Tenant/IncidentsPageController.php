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
        ]);

        $status = $request->query('status', 'active');

        $incidents = Incident::query()
            ->with(['room:id,number,name,status', 'reporter:id,name', 'assignee:id,name', 'resolver:id,name', 'media'])
            ->when($status === 'active', fn ($q) => $q->active())
            ->when(in_array($status, Incident::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->when($request->query('priority'), fn ($q, $priority) => $q->where('priority', $priority))
            ->when($request->query('category'), fn ($q, $category) => $q->where('category', $category))
            ->when($request->integer('room'), fn ($q, $room) => $q->where('room_id', $room))
            ->orderByRaw("field(status, 'open', 'in_progress', 'resolved')")
            ->orderByRaw("field(priority, 'high', 'medium', 'low')")
            ->latest()
            ->take(100)
            ->get()
            ->map(fn (Incident $incident) => self::present($incident));

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
            ],
            'rooms' => Room::query()
                ->orderBy('number')
                ->get(['id', 'number', 'name', 'status'])
                ->map(fn (Room $room) => [
                    'id' => $room->id,
                    'label' => trim($room->number.' '.($room->name ?? '')),
                    'status' => $room->status->getMorphClass(),
                ]),
            'staff' => User::query()->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'status' => $status,
                'priority' => $request->query('priority'),
                'room' => $request->integer('room') ?: null,
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
            'photos' => $incident->getMedia('photos')->map(fn (Media $media) => [
                'id' => $media->id,
                'url' => route('tenant.incidents.photos.show', ['incident' => $incident->id, 'media' => $media->id], false),
            ])->values(),
        ];
    }
}
