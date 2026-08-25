<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Rooms\ChangeRoomStatus;
use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Models\Housekeeper;
use App\Models\Incident;
use App\Models\Room;
use App\Models\RoomCleaning;
use App\Services\HousekeepingChecklist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

/**
 * Registro del trabajo de limpieza: abrir (empieza el cronómetro), cerrar
 * (sella la duración y guarda qué se hizo) y capturar a mano lo que ya pasó.
 *
 * El semáforo lo sigue moviendo ChangeRoomStatus — este controlador NO
 * duplica esa lógica, la invoca. Si el módulo está apagado, nada de esto
 * existe y el plano se comporta como siempre.
 */
class RoomCleaningController extends Controller
{
    /**
     * Empieza una limpieza: manda la habitación a "en limpieza" y arranca el
     * cronómetro con la camarista que la trabaja.
     */
    public function store(Request $request, Room $room, ChangeRoomStatus $changeStatus): JsonResponse
    {
        $data = $request->validate([
            'housekeeper_id' => ['required', 'integer', 'exists:housekeepers,id'],
            'kind' => ['nullable', Rule::in(array_keys(RoomCleaning::KINDS))],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        // Una limpieza abierta en la misma habitación significa que alguien
        // ya la empezó: reabrirla duplicaría el trabajo en el reporte.
        if ($room->cleanings()->open()->exists()) {
            return response()->json([
                'message' => "La {$room->number} ya tiene una limpieza en curso; ciérrala antes de iniciar otra.",
            ], 422);
        }

        try {
            $changeStatus->handle($room, RoomStatus::Cleaning->value, $request->user());
        } catch (CouldNotPerformTransition) {
            return response()->json([
                'message' => "La {$room->number} no puede pasar a limpieza desde {$room->status->label()}.",
            ], 422);
        }

        $cleaning = $room->cleanings()->create([
            'housekeeper_id' => $data['housekeeper_id'],
            // La estancia que la dejó sucia: liga el trabajo con el huésped
            // que salió, para el reporte por tipo de limpieza.
            'stay_id' => $room->stays()->latest('check_out_at')->value('id'),
            'kind' => $data['kind'] ?? RoomCleaning::KIND_CHECKOUT,
            'started_at' => now(),
            'notes' => $data['notes'] ?? null,
            'recorded_by' => $request->user()?->id,
            'source' => RoomCleaning::SOURCE_FLOORPLAN,
        ]);

        return response()->json($this->present($cleaning->fresh(['housekeeper', 'room'])), 201);
    }

    /**
     * Cierra la limpieza: sella la duración, guarda checklist y ropa, libera
     * la habitación y, si encontró algo roto, levanta la incidencia.
     */
    public function update(Request $request, RoomCleaning $cleaning, ChangeRoomStatus $changeStatus): JsonResponse
    {
        if (! $cleaning->isOpen()) {
            return response()->json(['message' => 'Esa limpieza ya estaba cerrada.'], 422);
        }

        $checklist = new HousekeepingChecklist;

        $data = $request->validate([
            'checklist' => ['array'],
            'checklist.*' => ['string', 'max:40'],
            'linens' => ['array'],
            'linens.*' => ['integer', 'min:0', 'max:99'],
            'notes' => ['nullable', 'string', 'max:500'],
            'ended_at' => ['nullable', 'date'],
            // Desperfecto encontrado al limpiar: se levanta el ticket con el
            // módulo de incidencias que ya existe.
            'incident_title' => ['nullable', 'string', 'max:120'],
            'incident_priority' => ['nullable', Rule::in(Incident::PRIORITIES)],
            'set_maintenance' => ['nullable', 'boolean'],
            // Liberar la habitación es lo normal, pero con un desperfecto
            // grave puede quedarse fuera de servicio.
            'release_room' => ['nullable', 'boolean'],
        ]);

        $incident = $this->reportDefect($request, $cleaning, $data);

        $cleaning->close([
            'checklist' => $checklist->sanitizeChecklist($data['checklist'] ?? []) ?: null,
            'linens' => $checklist->sanitizeLinens($data['linens'] ?? []) ?: null,
            'notes' => $data['notes'] ?? $cleaning->notes,
            'incident_id' => $incident?->id ?? $cleaning->incident_id,
        ], isset($data['ended_at']) ? Carbon::parse($data['ended_at']) : null);

        // El estado se mueve al final: si el ticket sacó la habitación de
        // servicio, no se libera encima de esa decisión.
        $room = $cleaning->room;
        $release = $data['release_room'] ?? true;

        if ($release && $room->status->getMorphClass() === RoomStatus::Cleaning->value) {
            try {
                $changeStatus->handle($room, RoomStatus::Available->value, $request->user());
            } catch (CouldNotPerformTransition) {
                // La habitación cambió de estado mientras se limpiaba (un
                // ticket, otra persona): el registro se cierra igual.
            }
        }

        return response()->json($this->present($cleaning->fresh(['housekeeper', 'room', 'incident'])));
    }

    /**
     * Captura de lo que ya pasó: horas escritas a mano al final del turno.
     * No mueve el semáforo — la habitación ya se limpió hace rato.
     */
    public function storeManual(Request $request): JsonResponse
    {
        $checklist = new HousekeepingChecklist;

        $data = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'housekeeper_id' => ['required', 'integer', 'exists:housekeepers,id'],
            'kind' => ['nullable', Rule::in(array_keys(RoomCleaning::KINDS))],
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after_or_equal:started_at'],
            'checklist' => ['array'],
            'checklist.*' => ['string', 'max:40'],
            'linens' => ['array'],
            'linens.*' => ['integer', 'min:0', 'max:99'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'ended_at.after_or_equal' => 'La hora de salida no puede ser anterior a la de entrada.',
        ]);

        $started = Carbon::parse($data['started_at']);

        // Sin tope, un dedazo de fecha mete una limpieza de 400 horas al
        // promedio del reporte y lo vuelve inservible.
        if ($started->isFuture()) {
            return response()->json(['message' => 'La hora de entrada no puede estar en el futuro.'], 422);
        }

        $cleaning = RoomCleaning::create([
            'room_id' => $data['room_id'],
            'housekeeper_id' => $data['housekeeper_id'],
            'kind' => $data['kind'] ?? RoomCleaning::KIND_CHECKOUT,
            'started_at' => $started,
            'checklist' => $checklist->sanitizeChecklist($data['checklist'] ?? []) ?: null,
            'linens' => $checklist->sanitizeLinens($data['linens'] ?? []) ?: null,
            'notes' => $data['notes'] ?? null,
            'recorded_by' => $request->user()?->id,
            'source' => RoomCleaning::SOURCE_MANUAL,
        ]);

        $cleaning->close([], Carbon::parse($data['ended_at']));

        return response()->json($this->present($cleaning->fresh(['housekeeper', 'room'])), 201);
    }

    public function destroy(RoomCleaning $cleaning): JsonResponse
    {
        $cleaning->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Levanta la incidencia del desperfecto encontrado al limpiar.
     *
     * @param  array<string, mixed>  $data
     */
    protected function reportDefect(Request $request, RoomCleaning $cleaning, array $data): ?Incident
    {
        $title = trim((string) ($data['incident_title'] ?? ''));

        if ($title === '' || ! tenant()?->hasModule('incidencias')) {
            return null;
        }

        $incident = Incident::create([
            'room_id' => $cleaning->room_id,
            'title' => $title,
            'category' => 'limpieza',
            'source' => Incident::SOURCE_STAFF,
            'description' => $cleaning->housekeeper
                ? "Reportado al limpiar por {$cleaning->housekeeper->name}."
                : 'Reportado durante la limpieza.',
            'priority' => $data['incident_priority'] ?? 'medium',
            'status' => Incident::STATUS_OPEN,
            'reported_by' => $request->user()?->id,
        ]);

        if ($data['set_maintenance'] ?? false) {
            try {
                app(ChangeRoomStatus::class)->handle(
                    $cleaning->room,
                    RoomStatus::Maintenance->value,
                    $request->user(),
                    ['incident_id' => $incident->id],
                );
            } catch (CouldNotPerformTransition) {
                // Si no se puede sacar de servicio, el ticket queda igual.
            }
        }

        return $incident;
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(RoomCleaning $cleaning): array
    {
        return [
            'id' => $cleaning->id,
            'room_id' => $cleaning->room_id,
            'room' => $cleaning->room?->number,
            'housekeeper_id' => $cleaning->housekeeper_id,
            'housekeeper' => $cleaning->housekeeper?->name,
            'kind' => $cleaning->kind,
            'kind_label' => $cleaning->kindLabel(),
            'started_at' => $cleaning->started_at?->toDateTimeString(),
            'started_label' => $cleaning->started_at?->format('H:i'),
            'ended_at' => $cleaning->ended_at?->toDateTimeString(),
            'ended_label' => $cleaning->ended_at?->format('H:i'),
            'minutes' => $cleaning->elapsedMinutes(),
            'open' => $cleaning->isOpen(),
            'checklist' => $cleaning->checklist ?? [],
            'linens' => $cleaning->linens ?? [],
            'notes' => $cleaning->notes,
            'incident_id' => $cleaning->incident_id,
            'source' => $cleaning->source,
        ];
    }
}
