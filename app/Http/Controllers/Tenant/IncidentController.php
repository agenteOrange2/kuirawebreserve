<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Rooms\ChangeRoomStatus;
use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Models\Incident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Tickets de incidencias de mantenimiento. Opcionalmente mueven el
 * semáforo: al reportar pueden poner la habitación en mantenimiento y al
 * resolver devolverla a disponible — siempre vía ChangeRoomStatus (punto
 * único, deja rastro y emite el broadcast del plano).
 */
class IncidentController extends Controller
{
    public function store(Request $request, ChangeRoomStatus $changeStatus): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            // La estancia que causó el daño (la manda el check-out).
            'stay_id' => ['nullable', 'integer', 'exists:stays,id'],
            'title' => ['required', 'string', 'max:120'],
            'category' => ['nullable', Rule::in(array_keys(Incident::CATEGORIES))],
            'source' => ['nullable', Rule::in([Incident::SOURCE_STAFF, Incident::SOURCE_GUEST])],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['required', Rule::in(Incident::PRIORITIES)],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'set_maintenance' => ['nullable', 'boolean'],
        ]);

        // Asignación de responsables = incidencias avanzadas (Empresarial).
        // Sin el módulo el campo se ignora en silencio: el ticket se crea
        // igual, solo que sin responsable.
        $tenant = tenant();
        if ($tenant !== null && ! $tenant->hasModule('incidencias-avanzado')) {
            $validated['assigned_to'] = null;
        }

        $incident = Incident::create([
            'room_id' => $validated['room_id'] ?? null,
            'stay_id' => $validated['stay_id'] ?? null,
            'title' => $validated['title'],
            'category' => $validated['category'] ?? null,
            'source' => $validated['source'] ?? Incident::SOURCE_STAFF,
            'description' => $validated['description'] ?? null,
            'priority' => $validated['priority'],
            'status' => ($validated['assigned_to'] ?? null) ? Incident::STATUS_IN_PROGRESS : Incident::STATUS_OPEN,
            'reported_by' => $request->user()->id,
            'assigned_to' => $validated['assigned_to'] ?? null,
        ]);

        // Poner en mantenimiento solo cuando no hay huésped de por medio:
        // una ocupada o reservada no se saca de servicio desde aquí.
        if (($validated['set_maintenance'] ?? false) && $incident->room) {
            $current = $incident->room->status->getMorphClass();

            if (in_array($current, [RoomStatus::Available->value, RoomStatus::Dirty->value, RoomStatus::Cleaning->value], true)) {
                $changeStatus->handle(
                    $incident->room,
                    RoomStatus::Maintenance->value,
                    $request->user(),
                    ['incident_id' => $incident->id],
                );
            }
        }

        // Una falla de prioridad alta no puede depender de que alguien
        // entre a /incidencias: suena la campana en el momento.
        if ($incident->priority === 'high') {
            $this->notifyStaff(
                $incident,
                'Falla de prioridad alta',
                $incident->room
                    ? "Habitación {$incident->room->number}: {$incident->title}"
                    : $incident->title,
            );
        }

        return response()->json(IncidentsPageController::present($incident->fresh(['room', 'reporter', 'assignee', 'resolver', 'technician'])), 201);
    }

    public function update(Request $request, Incident $incident, ChangeRoomStatus $changeStatus): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => ['sometimes', 'nullable', 'integer', 'exists:rooms,id'],
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'category' => ['sometimes', 'nullable', Rule::in(array_keys(Incident::CATEGORIES))],
            'source' => ['sometimes', Rule::in([Incident::SOURCE_STAFF, Incident::SOURCE_GUEST])],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'priority' => ['sometimes', 'required', Rule::in(Incident::PRIORITIES)],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'status' => ['sometimes', 'required', Rule::in(Incident::STATUSES)],
            'resolution_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'technician_id' => ['sometimes', 'nullable', 'integer', 'exists:technicians,id'],
            'release_room' => ['nullable', 'boolean'],
        ]);

        $releaseRoom = (bool) ($validated['release_room'] ?? false);
        unset($validated['release_room']);

        // Mismo criterio que en el alta: sin incidencias avanzadas no se
        // tocan responsables.
        $tenant = tenant();
        if ($tenant !== null && ! $tenant->hasModule('incidencias-avanzado')) {
            unset($validated['assigned_to'], $validated['cost'], $validated['technician_id']);
        }

        $incident->fill($validated);

        // Sello de resolución al entrar (y se limpia si se reabre).
        if ($incident->isDirty('status')) {
            if ($incident->status === Incident::STATUS_RESOLVED) {
                $incident->resolved_by = $request->user()->id;
                $incident->resolved_at = now();
            } else {
                $incident->resolved_by = null;
                $incident->resolved_at = null;
                $incident->resolution_notes = null;
                // Si vuelve a estar rota, lo que costó la vez pasada deja de
                // ser el costo de arreglarla: se recaptura al cerrar de nuevo.
                $incident->cost = null;
                $incident->technician_id = null;
            }
        }

        $incident->save();

        // Devolver la habitación al servicio solo si sigue en mantenimiento.
        if (
            $releaseRoom
            && $incident->status === Incident::STATUS_RESOLVED
            && $incident->room
            && $incident->room->status->getMorphClass() === RoomStatus::Maintenance->value
        ) {
            $changeStatus->handle(
                $incident->room,
                RoomStatus::Available->value,
                $request->user(),
                ['incident_id' => $incident->id],
            );
        }

        return response()->json(IncidentsPageController::present($incident->fresh(['room', 'reporter', 'assignee', 'resolver', 'technician'])));
    }

    public function destroy(Incident $incident): JsonResponse
    {
        $incident->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Borrado en bloque desde el listado: la limpieza de fin de mes de los
     * tickets viejos, sin ir de uno en uno.
     */
    public function destroyBulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $deleted = 0;
        foreach (Incident::query()->whereIn('id', $data['ids'])->get() as $incident) {
            $incident->delete();
            $deleted++;
        }

        return response()->json(['deleted' => $deleted]);
    }

    /** Sube una foto de evidencia al disco privado. */
    public function storePhoto(Request $request, Incident $incident): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'image', 'max:8192'],
        ]);

        $incident->addMediaFromRequest('file')->toMediaCollection('photos');

        return response()->json(IncidentsPageController::present($incident->fresh(['room', 'reporter', 'assignee', 'resolver', 'technician'])), 201);
    }

    public function showPhoto(Incident $incident, Media $media): BinaryFileResponse
    {
        abort_unless(
            $media->model_type === $incident->getMorphClass()
            && (int) $media->model_id === $incident->id
            && $media->collection_name === 'photos',
            404,
        );

        return response()->file($media->getPath());
    }

    public function destroyPhoto(Incident $incident, Media $media): JsonResponse
    {
        abort_unless(
            (int) $media->model_id === $incident->id && $media->collection_name === 'photos',
            404,
        );

        $media->delete();

        return response()->json(IncidentsPageController::present($incident->fresh(['room', 'reporter', 'assignee', 'resolver', 'technician'])));
    }

    /**
     * Aviso a la campana del panel. StaffNotifier ya deduplica por sujeto:
     * el mismo ticket refresca su aviso en vez de apilar otro.
     */
    protected function notifyStaff(Incident $incident, string $title, string $body): void
    {
        app(\App\Services\StaffNotifier::class)->notify(
            type: \App\Models\StaffNotification::TYPE_INCIDENT,
            title: $title,
            body: $body,
            url: '/incidencias/'.$incident->id,
            subject: $incident,
        );
    }

    /**
     * Tiempos objetivo por prioridad. Son tres números y viven en los
     * ajustes del hotel: se editan en un modal desde /incidencias en vez de
     * abrir una pantalla de configuración para eso.
     */
    public function updateSla(Request $request): JsonResponse
    {
        $data = $request->validate([
            'high' => ['required', 'integer', 'min:1', 'max:'.\App\Services\IncidentPolicy::MAX_HOURS],
            'medium' => ['required', 'integer', 'min:1', 'max:'.\App\Services\IncidentPolicy::MAX_HOURS],
            'low' => ['required', 'integer', 'min:1', 'max:'.\App\Services\IncidentPolicy::MAX_HOURS],
        ]);

        $policy = app(\App\Services\IncidentPolicy::class);
        $policy->save($data);

        // Los plazos vigentes se recalculan: cambiar la política y que los
        // tickets abiertos conserven el plazo viejo sería mentir.
        Incident::query()
            ->whereIn('status', [Incident::STATUS_OPEN, Incident::STATUS_IN_PROGRESS])
            ->get()
            ->each(function (Incident $incident) use ($policy) {
                $incident->forceFill([
                    'due_at' => $policy->dueAt($incident->priority, $incident->created_at),
                    'overdue_notified_at' => null,
                ])->saveQuietly();
            });

        return response()->json(['ok' => true, 'sla' => $policy->hours()]);
    }
}
