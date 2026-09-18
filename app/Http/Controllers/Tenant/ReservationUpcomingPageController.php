<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ReservationStatus;
use App\Models\Property;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Todas las próximas reservas (/reservas/proximas): /reservas solo pinta
 * las 30 llegadas más cercanas para no mandar cientos de filas en cada
 * carga, y aquí vive el resto —lo que ya está apartado a futuro— con
 * buscador, filtro por estado y paginación. Hermana de
 * ReservationHistoryPageController: mismo patrón, otro lado del tiempo.
 */
class ReservationUpcomingPageController extends ReservationsPageController
{
    /** Diez por página: el tablero manda aquí a revisar, no a leer un archivo. */
    protected const PER_PAGE = 10;

    protected const UPCOMING_STATUSES = [
        ReservationStatus::Pending,
        ReservationStatus::Confirmed,
    ];

    public function __invoke(Request $request): Response
    {
        $property = Property::firstOrFail();
        $search = trim($request->string('q')->toString());
        $status = ReservationStatus::tryFrom($request->string('status')->toString());
        // Un día concreto: el tablero manda aquí desde el pulso de la semana
        // ("el viernes llegan 8"), y sin este filtro caía en la lista entera.
        $date = null;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->string('date')->toString())) {
            $date = \Illuminate\Support\Carbon::parse($request->string('date')->toString());
        }

        if (! in_array($status, self::UPCOMING_STATUSES, true)) {
            $status = null;
        }

        $paginator = Reservation::query()
            ->with([
                'room:id,number',
                'roomType:id,name',
                'ratePlan:id,name,type',
                'guest:id,first_name,last_name,phone,email',
            ])
            ->withSum('payments', 'amount')
            ->whereIn('status', $status ? [$status] : self::UPCOMING_STATUSES)
            // Mismo corte que la lista de /reservas: sigue viva mientras no
            // termine, aunque la llegada ya haya pasado.
            ->where('ends_at', '>=', now())
            ->when($date, fn ($query) => $query->whereBetween('starts_at', [
                $date->copy()->startOfDay(),
                $date->copy()->endOfDay(),
            ]))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('guest_name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhereHas('room', fn ($r) => $r->where('number', 'like', "%{$search}%"))
                        ->orWhereHas('guest', fn ($g) => $g->where('phone', 'like', "%{$search}%"));

                    // "RES-2026-0042" o "42" a secas: también busca por id.
                    if (preg_match('/(\d+)\s*$/', $search, $m)) {
                        $q->orWhere('id', (int) ltrim($m[1], '0'));
                    }
                });
            })
            // Por llegada: lo primero que hay que atender, primero.
            ->orderBy('starts_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $timeline = Activity::query()
            ->where('subject_type', Reservation::class)
            ->whereIn('subject_id', $paginator->getCollection()->modelKeys())
            ->latest()
            ->get()
            ->groupBy('subject_id');

        $paginator->through(fn (Reservation $r) => $this->serializeReservation($r, $timeline->get($r->id, collect())));

        return Inertia::render('tenant/reservations/Upcoming', [
            'property' => $property->only(['id', 'name']),
            'reservations' => $paginator,
            // Las cifras describen TODO lo apartado, no la página ni el
            // filtro: el mostrador pregunta "cuánta gente viene", no
            // "cuánta gente viene entre lo que estoy buscando".
            'summary' => $this->summary(),
            'filters' => [
                'q' => $search,
                'status' => $status?->value ?? '',
                'date' => $date?->format('Y-m-d') ?? '',
                'date_label' => $date?->format('d/m/Y') ?? '',
            ],
            'statusOptions' => collect(self::UPCOMING_STATUSES)
                ->map(fn (ReservationStatus $s) => ['value' => $s->value, 'label' => $s->label()])
                ->values(),
            'canManage' => $request->user()->can('reservations.manage'),
        ]);
    }

    /**
     * Cifras de la cabecera: cuántas vienen, cuántas llegan hoy, cuántas
     * siguen sin confirmar y cuánto dinero falta por cobrar de todas ellas.
     *
     * @return array<string, mixed>
     */
    protected function summary(): array
    {
        $now = now();
        $base = fn () => Reservation::query()
            ->whereIn('status', self::UPCOMING_STATUSES)
            ->where('ends_at', '>=', $now);

        $total = (float) $base()->sum('total_amount');
        // La fianza no es del hospedaje: si se cuenta, el saldo sale corto.
        $paid = (float) \App\Models\Payment::query()
            ->whereIn('reservation_id', $base()->select('reservations.id'))
            ->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '<>', 'guarantee'))
            ->sum('amount');

        $balance = round(max(0, $total - $paid), 2);

        return [
            'total' => $base()->count(),
            'today' => $base()
                ->whereBetween('starts_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])
                ->count(),
            'pending' => $base()->where('status', ReservationStatus::Pending)->count(),
            'balance' => $balance,
            'balance_label' => '$'.number_format($balance, 2),
        ];
    }
}
