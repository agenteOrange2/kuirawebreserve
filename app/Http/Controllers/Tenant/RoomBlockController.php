<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Bloqueos de habitación por fechas (mantenimiento programado). El bloqueo
 * descuenta disponibilidad futura vía AvailabilityService sin tocar el
 * semáforo presente. Si el rango pisa reservas vivas, el alta se permite
 * pero se reportan sus folios para que recepción las reubique o cancele.
 */
class RoomBlockController extends Controller
{
    public function index(Room $room): JsonResponse
    {
        return response()->json(
            $room->blocks()
                ->currentOrFuture()
                ->orderBy('starts_at')
                ->get()
                ->map(fn (RoomBlock $block) => $this->serialize($block)),
        );
    }

    public function store(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [
            'ends_at.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.',
        ]);

        $block = $room->blocks()->create([
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'reason' => filled($data['reason'] ?? null) ? trim($data['reason']) : null,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            ...$this->serialize($block),
            // Reservas vivas que pisan el bloqueo: se avisa con folios, pero
            // el bloqueo queda — recepción decide moverlas o cancelarlas.
            'conflicts' => $this->conflictingReservations($room, $block),
        ], 201);
    }

    public function destroy(Room $room, RoomBlock $block): JsonResponse
    {
        abort_unless($block->room_id === $room->id, 404);

        $block->delete();

        return response()->json(status: 204);
    }

    /**
     * Folios de reservas vivas (pendientes con hold vigente, confirmadas o
     * en casa) cuyas noches caen dentro del bloqueo.
     *
     * @return array<int, array{id: int, code: string, guest_name: string|null, starts_at: string, ends_at: string}>
     */
    protected function conflictingReservations(Room $room, RoomBlock $block): array
    {
        // El bloqueo cubre los días starts_at..ends_at completos: como rango
        // de estancia equivale a [starts_at 00:00, ends_at + 1 día 00:00).
        $start = Carbon::parse($block->starts_at->toDateString());
        $end = Carbon::parse($block->ends_at->toDateString())->addDay();

        return $room->reservations()
            ->blocking()
            ->overlapping($start, $end)
            ->orderBy('starts_at')
            ->get()
            // Misma convención que la disponibilidad: un check-out en la
            // mañana del primer día bloqueado no es conflicto.
            ->filter(fn (Reservation $reservation) => $block->coversRange($reservation->starts_at, $reservation->ends_at))
            ->values()
            ->map(fn (Reservation $reservation) => [
                'id' => $reservation->id,
                'code' => $reservation->displayCode(),
                'guest_name' => $reservation->guest?->full_name ?? $reservation->guest_name,
                'starts_at' => $reservation->starts_at->format('d/m/Y'),
                'ends_at' => $reservation->ends_at->format('d/m/Y'),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize(RoomBlock $block): array
    {
        return [
            'id' => $block->id,
            'room_id' => $block->room_id,
            'starts_at' => $block->starts_at->toDateString(),
            'ends_at' => $block->ends_at->toDateString(),
            'reason' => $block->reason,
            'created_by' => $block->createdBy?->name,
        ];
    }
}
