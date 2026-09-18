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
use Symfony\Component\HttpFoundation\StreamedResponse;

class GuestsPageController extends Controller
{
    /** Cómo se puede ordenar el directorio (el resto cae en "recientes"). */
    private const SORTS = ['recent', 'name', 'visits', 'spent'];

    public function index(Request $request): Response
    {
        $archived = $request->boolean('archived');
        $sort = in_array($request->string('sort')->toString(), self::SORTS, true)
            ? $request->string('sort')->toString()
            : 'recent';

        $guests = $this->directoryQuery($request, $sort)
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Guest $guest) => $this->row($guest));

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
            'filters' => [
                'q' => trim($request->string('q')->toString()),
                'blacklisted' => $request->boolean('blacklisted'),
                'upcoming' => $request->boolean('upcoming'),
                'archived' => $archived,
                'sort' => $sort,
            ],
            'canManage' => $request->user()->can('guests.manage'),
            'canViewDocuments' => $request->user()->can('guests.view-documents'),
            'documentTypes' => Guest::DOCUMENT_TYPES,
        ]);
    }

    /**
     * El directorio en CSV, con los filtros puestos.
     *
     * El dueño pedía la lista para mandar promociones y para el contador;
     * hasta ahora salía copiando la pantalla a mano, página por página.
     */
    public function export(Request $request): StreamedResponse
    {
        $sort = in_array($request->string('sort')->toString(), self::SORTS, true)
            ? $request->string('sort')->toString()
            : 'recent';

        $filename = 'huespedes-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($request, $sort) {
            $out = fopen('php://output', 'w');
            // BOM: sin él Excel en Windows parte los acentos.
            fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, [
                'Nombre', 'Teléfono', 'Correo', 'Visitas', 'Gastado',
                'Última visita', 'Próxima llegada', 'Lista negra',
                'Archivado', 'Alta',
            ]);

            // cursor() y no chunk(): el orden puede ser por gasto o por
            // visitas, que no son únicos, y paginar con offset sobre un
            // orden repetido salta o repite renglones.
            foreach ($this->directoryQuery($request, $sort)->cursor() as $guest) {
                $row = $this->row($guest);
                fputcsv($out, [
                    $row['full_name'],
                    $row['phone'] ?? '',
                    $row['email'] ?? '',
                    $row['visits'],
                    number_format($row['total_spent'], 2, '.', ''),
                    $row['last_visit'] ?? '',
                    $row['next_arrival'] ?? '',
                    $row['is_blacklisted'] ? 'sí' : 'no',
                    $row['is_archived'] ? 'sí' : 'no',
                    $row['created_at'],
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * La consulta del directorio, una sola vez para la pantalla y el CSV.
     *
     * Todo lo que la fila necesita viaja en subconsultas: con 15 filas por
     * página, calcular visitas o gasto por fila serían 45 consultas extra.
     */
    protected function directoryQuery(Request $request, string $sort): \Illuminate\Database\Eloquent\Builder
    {
        $search = trim($request->string('q')->toString());

        $query = Guest::query()
            // select() ANTES de withVisits(): puesto después borra las
            // columnas que withCount ya había agregado y el directorio
            // entero vuelve a decir "0 visitas".
            ->select('guests.*')
            ->when($request->boolean('archived'), fn ($q) => $q->onlyTrashed())
            ->when($search !== '', fn ($q) => $q->search($search))
            ->when($request->boolean('blacklisted'), fn ($q) => $q->where('is_blacklisted', true))
            // Solo los que traen algo apartado: a estos se les llama hoy.
            ->when($request->boolean('upcoming'), fn ($q) => $q->whereHas('reservations', fn ($r) => $r
                ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed])
                ->where('ends_at', '>=', now())))
            // Visitas = estancias completadas + reservas completadas sin
            // estancia (Guest::withVisits, mismo criterio que metrics()).
            // Antes el directorio entero decía "0 visitas".
            ->withVisits()
            // Lo que ha dejado: hospedaje + consumos. Mismo criterio que
            // metrics() en la ficha, para que las dos cifras coincidan.
            ->selectRaw($this->spentExpression().' as total_spent')
            // Lo que le importa al mostrador de un huésped: si trae algo
            // próximo. Subconsulta, no una consulta por fila.
            ->addSelect(['next_arrival' => Reservation::query()
                ->selectRaw('min(starts_at)')
                ->whereColumn('reservations.guest_id', 'guests.id')
                ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed])
                ->where('ends_at', '>=', now())])
            // Cuándo vino por última vez: la estancia más reciente o, si
            // nunca se registró llegada, la reserva completada más reciente.
            ->addSelect(['last_stay_at' => Stay::query()
                ->selectRaw('max(check_in_at)')
                ->whereColumn('stays.guest_id', 'guests.id')
                ->where('status', Stay::STATUS_COMPLETED)])
            ->addSelect(['last_reservation_at' => Reservation::query()
                ->selectRaw('max(starts_at)')
                ->whereColumn('reservations.guest_id', 'guests.id')
                ->where('status', ReservationStatus::Completed)]);

        return match ($sort) {
            'name' => $query->orderBy('last_name')->orderBy('first_name'),
            // Los alias del select: los ordena igual MySQL y sqlite.
            'visits' => $query->orderByRaw('(stay_visits + reservation_visits) desc'),
            'spent' => $query->orderByRaw('total_spent desc'),
            default => $query->orderByDesc('updated_at'),
        };
    }

    /**
     * Lo gastado por un huésped, en SQL: hospedaje de sus estancias
     * (cerradas o en curso), consumos cargados al cuarto y reservas
     * completadas que nunca tuvieron estancia (las migradas y las que el
     * hotel cierra sin registrar la llegada).
     */
    protected function spentExpression(): string
    {
        $stayStatuses = "'".Stay::STATUS_COMPLETED."', '".Stay::STATUS_ACTIVE."'";
        $orderStatus = "'".Order::STATUS_COMPLETED."'";
        $completed = "'".ReservationStatus::Completed->value."'";

        return "(
            (select coalesce(sum(s.amount), 0) from stays s
                where s.guest_id = guests.id and s.status in ({$stayStatuses}))
            + (select coalesce(sum(o.total), 0) from orders o
                inner join stays os on os.id = o.stay_id
                where os.guest_id = guests.id and o.status = {$orderStatus})
            + (select coalesce(sum(r.total_amount), 0) from reservations r
                where r.guest_id = guests.id and r.status = {$completed}
                and not exists (select 1 from stays rs where rs.reservation_id = r.id))
        )";
    }

    /**
     * Un renglón del directorio (lo usan la pantalla y el CSV).
     *
     * @return array<string, mixed>
     */
    protected function row(Guest $guest): array
    {
        $lastVisit = collect([$guest->last_stay_at, $guest->last_reservation_at])
            ->filter()
            ->map(fn ($date) => \Illuminate\Support\Carbon::parse($date))
            ->max();

        return [
            'id' => $guest->id,
            'full_name' => $guest->full_name ?? 'Sin nombre',
            'phone' => $guest->phone,
            'email' => $guest->email,
            'visits' => (int) $guest->visits,
            'total_spent' => round((float) $guest->total_spent, 2),
            'last_visit' => $lastVisit?->format('d/m/Y'),
            'next_arrival' => $guest->next_arrival
                ? \Illuminate\Support\Carbon::parse($guest->next_arrival)->format('d/m/Y')
                : null,
            'is_blacklisted' => $guest->is_blacklisted,
            'is_archived' => $guest->trashed(),
            'created_at' => $guest->created_at->format('d/m/Y'),
        ];
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
                    // Con el id la fila es un enlace a la reserva: antes se
                    // pintaba como tarjeta y no llevaba a ningún lado.
                    'id' => $r->id,
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
                // Llegó sin reserva: no hay ficha de reserva que abrir.
                'id' => null,
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
