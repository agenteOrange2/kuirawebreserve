<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Búsqueda rápida del panel (⌘K): lo que se busca a diario desde cualquier
 * pantalla — una reserva por su código, un huésped por su teléfono, una
 * habitación por su número. Las páginas del menú las resuelve el front (ya
 * las tiene filtradas por módulo y permiso); aquí van solo los DATOS.
 *
 * Cada bloque respeta el permiso de su sección: quien no puede ver el CRM
 * no encuentra huéspedes aquí tampoco.
 */
class QuickSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());

        if (mb_strlen($term) < 2) {
            return response()->json(['groups' => []]);
        }

        $user = $request->user();
        $groups = [];

        if ($user?->can('reservations.view')) {
            $groups[] = [
                'label' => 'Reservas',
                'icon' => 'CalendarCheck',
                'items' => $this->reservations($term),
            ];
        }

        if ($user?->can('guests.view')) {
            $groups[] = [
                'label' => 'Huéspedes',
                'icon' => 'Users',
                'items' => $this->guests($term),
            ];
        }

        if ($user?->can('rooms.view')) {
            $groups[] = [
                'label' => 'Habitaciones',
                'icon' => 'BedDouble',
                'items' => $this->rooms($term),
            ];
        }

        return response()->json([
            'groups' => array_values(array_filter($groups, fn (array $group) => $group['items'] !== [])),
        ]);
    }

    /**
     * Por código, por nombre de quien reservó o por su teléfono. El código
     * se busca también sin el prefijo: nadie teclea "RES-2026-" completo.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function reservations(string $term): array
    {
        return Reservation::query()
            ->with(['room:id,number', 'guest:id,first_name,last_name,phone'])
            ->where(function ($query) use ($term) {
                $query->where('code', 'like', "%{$term}%")
                    ->orWhere('guest_name', 'like', "%{$term}%")
                    ->orWhereHas('guest', fn ($q) => $q->search($term));
            })
            ->latest('starts_at')
            ->take(5)
            ->get()
            ->map(fn (Reservation $reservation) => [
                'title' => $reservation->displayCode(),
                'subtitle' => trim(sprintf(
                    '%s · %s%s',
                    $reservation->guest_name ?: $reservation->guest?->full_name ?: 'Sin nombre',
                    $reservation->starts_at?->locale('es')->isoFormat('D [de] MMM') ?? '',
                    $reservation->room?->number ? ' · Hab. '.$reservation->room->number : '',
                )),
                // El historial es la única lista con buscador server-side:
                // llega con el código ya tecleado, no a una lista sin filtrar.
                'url' => route('tenant.reservations.history', [], false).'?q='.urlencode($reservation->displayCode()),
                'badge' => $reservation->status->label(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    protected function guests(string $term): array
    {
        return Guest::query()
            ->search($term)
            ->orderByDesc('updated_at')
            ->take(5)
            ->get()
            ->map(fn (Guest $guest) => [
                'title' => $guest->full_name,
                'subtitle' => trim(implode(' · ', array_filter([$guest->phone, $guest->email]))) ?: 'Sin contacto',
                'url' => route('tenant.guests.show', $guest, false),
                'badge' => $guest->is_blacklisted ? 'Vetado' : null,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    protected function rooms(string $term): array
    {
        return Room::query()
            ->with('roomType:id,name')
            ->where(fn ($query) => $query
                ->where('number', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%"))
            ->orderBy('number')
            ->take(5)
            ->get()
            ->map(fn (Room $room) => [
                'title' => $room->name ?: 'Habitación '.$room->number,
                'subtitle' => trim(implode(' · ', array_filter([$room->number, $room->roomType?->name]))),
                'url' => route('tenant.rooms.show', $room, false),
                'badge' => $room->status->label(),
            ])
            ->all();
    }
}
