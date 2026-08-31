<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\Stay;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GuestsPageController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim($request->string('q')->toString());
        $archived = $request->boolean('archived');

        $guests = Guest::query()
            ->when($archived, fn ($q) => $q->onlyTrashed())
            ->when($search !== '', fn ($q) => $q->search($search))
            ->when($request->boolean('blacklisted'), fn ($q) => $q->where('is_blacklisted', true))
            // Visitas = estancias completadas + reservas completadas sin
            // estancia (mismo criterio que Guest::metrics: así llegó el
            // historial migrado y así queda lo que se cierra sin registrar
            // la llegada). Antes el directorio entero decía "0 visitas".
            ->withCount([
                'stays as stay_visits' => fn ($q) => $q->where('status', 'completed'),
                'reservations as reservation_visits' => fn ($q) => $q
                    ->where('status', ReservationStatus::Completed)
                    ->whereDoesntHave('stay'),
            ])
            // Lo que le importa al mostrador de un huésped: si trae algo
            // próximo. Subconsulta, no una consulta por fila.
            ->addSelect(['next_arrival' => Reservation::query()
                ->selectRaw('min(starts_at)')
                ->whereColumn('reservations.guest_id', 'guests.id')
                ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed])
                ->where('ends_at', '>=', now())])
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Guest $guest) => [
                'id' => $guest->id,
                'full_name' => $guest->full_name ?? 'Sin nombre',
                'phone' => $guest->phone,
                'email' => $guest->email,
                'visits' => (int) $guest->stay_visits + (int) $guest->reservation_visits,
                'next_arrival' => $guest->next_arrival
                    ? \Carbon\Carbon::parse($guest->next_arrival)->format('d/m/Y')
                    : null,
                'is_blacklisted' => $guest->is_blacklisted,
                'is_archived' => $guest->trashed(),
                'created_at' => $guest->created_at->format('d/m/Y'),
            ]);

        $archivedCount = Guest::onlyTrashed()->count();

        return Inertia::render('tenant/guests/Index', [
            'guests' => $guests,
            'archivedCount' => $archivedCount,
            // Cifras del directorio: cuentas agregadas, ninguna consulta por
            // fila, y no dependen del filtro (son el total del hotel).
            'stats' => [
                'total' => Guest::count(),
                'upcoming' => Guest::whereHas('reservations', fn ($q) => $q
                    ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed])
                    ->where('ends_at', '>=', now()))->count(),
                'blacklisted' => Guest::where('is_blacklisted', true)->count(),
                'archived' => $archivedCount,
            ],
            'filters' => ['q' => $search, 'blacklisted' => $request->boolean('blacklisted'), 'archived' => $archived],
            'canManage' => $request->user()->can('guests.manage'),
            'canViewDocuments' => $request->user()->can('guests.view-documents'),
            'documentTypes' => Guest::DOCUMENT_TYPES,
        ]);
    }

    public function show(Request $request, Guest $guest): Response
    {
        $canViewDocuments = $request->user()->can('guests.view-documents');

        return Inertia::render('tenant/guests/Show', [
            'guest' => [
                'id' => $guest->id,
                'first_name' => $guest->first_name,
                'last_name' => $guest->last_name,
                'full_name' => $guest->full_name ?? 'Sin nombre',
                'phone' => $guest->phone,
                'email' => $guest->email,
                'birth_date' => $guest->birth_date?->format('Y-m-d'),
                'nationality' => $guest->nationality,
                'address' => $guest->address,
                'city' => $guest->city,
                'state' => $guest->state,
                'zip' => $guest->zip,
                'id_document_type' => $guest->id_document_type,
                'id_document_number' => $canViewDocuments ? $guest->id_document_number : null,
                'notes' => $guest->notes,
                'is_blacklisted' => $guest->is_blacklisted,
                'blacklist_reason' => $guest->blacklist_reason,
                'marketing_consent' => $guest->marketing_consent,
                'created_at' => $guest->created_at->format('d/m/Y'),
                'is_archived' => $guest->trashed(),
                'archived_at' => $guest->deleted_at?->format('d/m/Y'),
            ],
            'metrics' => $guest->metrics(),
            'documents' => $canViewDocuments ? GuestController::documents($guest) : [],
            'vehicle' => $this->vehiclePayload($guest),
            'vehiclePhotos' => $canViewDocuments ? GuestController::media($guest, 'vehicle') : [],
            // Un solo historial: cada visita una fila (la reserva y su
            // estancia son la misma noche, no dos renglones en dos tablas).
            'history' => $this->history($guest),
            'canManage' => $request->user()->can('guests.manage'),
            'canReserve' => $request->user()->can('reservations.manage'),
            'canViewDocuments' => $canViewDocuments,
            'documentTypes' => Guest::DOCUMENT_TYPES,
        ]);
    }

    /**
     * Historial del huésped en UNA sola línea de tiempo: lo próximo arriba
     * y lo pasado agrupado por año con su subtotal.
     *
     * Antes eran dos tablas —estancias y reservas— y una noche normal
     * (reserva que sí llegó) salía en las dos, mientras que el historial
     * migrado del sitio anterior, que no trae estancias, dejaba la tabla de
     * arriba vacía. Aquí la estancia se funde en su reserva: una visita,
     * una fila, con la habitación real, la hora de llegada y sus consumos.
     *
     * @return array<string, mixed>
     */
    protected function history(Guest $guest, int $limit = 20): array
    {
        // Se traen unas cuantas más de las que se pintan para poder
        // fusionar y ordenar sin pedir la historia completa.
        $fetch = $limit * 2;

        $reservations = $guest->reservations()
            ->with(['room:id,number', 'stay'])
            ->orderByDesc('starts_at')
            ->take($fetch)
            ->get();

        // Estancias sin reserva: llegó sin apartar (walk-in del mostrador).
        $walkIns = $guest->stays()
            ->whereNull('reservation_id')
            ->with(['room:id,number', 'ratePlan:id,name'])
            ->orderByDesc('check_in_at')
            ->take($fetch)
            ->get();

        $stayIds = $reservations->pluck('stay.id')->filter()->merge($walkIns->pluck('id'));

        $consumos = $stayIds->isEmpty()
            ? collect()
            : Order::query()
                ->whereIn('stay_id', $stayIds)
                ->where('status', Order::STATUS_COMPLETED)
                ->selectRaw('stay_id, SUM(total) AS total')
                ->groupBy('stay_id')
                ->pluck('total', 'stay_id');

        $rows = $reservations
            ->map(function (Reservation $r) use ($consumos) {
                $stay = $r->stay;

                return [
                    'key' => 'r'.$r->id,
                    'code' => $r->displayCode(),
                    'kind' => 'reservation',
                    'room' => $stay?->room?->number ?? $r->room?->number,
                    'starts_at' => $r->starts_at->format('d/m/Y'),
                    'ends_at' => $r->ends_at->format('d/m/Y'),
                    'year' => (int) $r->starts_at->format('Y'),
                    'sort' => $r->starts_at->getTimestamp(),
                    'status' => $r->status->value,
                    // "No llegó" se lee mejor que "No show" en la ficha.
                    'status_label' => $r->status === ReservationStatus::NoShow
                        ? 'No llegó'
                        : $r->status->label(),
                    'upcoming' => in_array($r->status, [
                        ReservationStatus::Pending,
                        ReservationStatus::Confirmed,
                        ReservationStatus::CheckedIn,
                    ], true),
                    'amount' => (float) $r->total_amount,
                    'consumos' => (float) ($consumos[$stay?->id] ?? 0),
                    'checked_in_at' => $stay?->check_in_at?->format('H:i'),
                    'checked_out_at' => $stay?->check_out_at?->format('H:i'),
                ];
            })
            ->concat($walkIns->map(fn (Stay $stay) => [
                'key' => 's'.$stay->id,
                'code' => null,
                'kind' => 'walk_in',
                'room' => $stay->room?->number,
                'starts_at' => $stay->check_in_at->format('d/m/Y'),
                'ends_at' => ($stay->check_out_at ?? $stay->planned_end_at)->format('d/m/Y'),
                'year' => (int) $stay->check_in_at->format('Y'),
                'sort' => $stay->check_in_at->getTimestamp(),
                'status' => $stay->status,
                'status_label' => $stay->status === Stay::STATUS_ACTIVE ? 'En casa' : 'Completada',
                'upcoming' => $stay->status === Stay::STATUS_ACTIVE,
                'amount' => (float) $stay->amount,
                'consumos' => (float) ($consumos[$stay->id] ?? 0),
                'checked_in_at' => $stay->check_in_at->format('H:i'),
                'checked_out_at' => $stay->check_out_at?->format('H:i'),
            ]))
            ->sortByDesc('sort')
            ->values();

        $upcoming = $rows->where('upcoming', true)->values();
        $past = $rows->where('upcoming', false)->take($limit)->values();

        return [
            'upcoming' => $upcoming->all(),
            // Por año, con su subtotal: en un historial largo lo que se
            // pregunta es "cuánto dejó este huésped el año pasado".
            'years' => $past
                ->groupBy('year')
                ->map(fn ($group, $year) => [
                    'year' => (int) $year,
                    'visits' => $group->count(),
                    'total' => round($group->sum(fn (array $row) => $row['amount'] + $row['consumos']), 2),
                    'rows' => $group->values()->all(),
                ])
                ->values()
                ->all(),
            'shown' => $past->count(),
            'total' => $guest->reservations()->count()
                + $guest->stays()->whereNull('reservation_id')->count(),
        ];
    }

    /**
     * Vehículo para la ficha: el capturado en el CRM (meta) manda; sin él,
     * la ficha del registro de vehículos (la liga el walk-in del plano); y
     * si la placa tecleada no alcanzó para ficha (VehicleRegistry pide 4+
     * caracteres), al menos lo apuntado en la última estancia — antes eso
     * se guardaba pero la ficha no lo enseñaba por ningún lado.
     *
     * @return array<string, mixed>|null
     */
    private function vehiclePayload(Guest $guest): ?array
    {
        if ($guest->vehicle() !== []) {
            return $guest->vehicle();
        }

        $registered = $guest->vehicles()->latest('updated_at')->first();
        if ($registered) {
            return [
                'plate' => $registered->plate,
                'brand' => $registered->brand,
                'model' => $registered->model,
                'color' => $registered->color,
                'year' => $registered->year,
                'notes' => $registered->notes,
            ];
        }

        $stay = $guest->stays()
            ->where(fn ($q) => $q
                ->where('vehicle_plate', '<>', '')
                ->orWhere('vehicle_desc', '<>', ''))
            ->latest('check_in_at')
            ->first();

        return $stay
            ? ['plate' => $stay->vehicle_plate, 'notes' => $stay->vehicle_desc]
            : null;
    }
}
