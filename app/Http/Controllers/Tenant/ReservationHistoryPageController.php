<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Historial COMPLETO de reservas (/reservas/historial): la lista de
 * /reservas solo trae las últimas 20 para no crecer sin freno; aquí vive
 * todo lo que ya salió del flujo (completadas, canceladas y no-shows) con
 * buscador, filtro por estado, paginación y las acciones de siempre
 * (detalle y borrado). Extiende ReservationsPageController solo para
 * reutilizar la serialización — la vista es propia.
 */
class ReservationHistoryPageController extends ReservationsPageController
{
    /** Diez por página: el tablero manda aquí a revisar, no a leer un archivo. */
    protected const PER_PAGE = 10;

    protected const HISTORY_STATUSES = [
        ReservationStatus::Completed,
        ReservationStatus::Cancelled,
        ReservationStatus::NoShow,
    ];

    public function __invoke(Request $request): Response
    {
        $property = Property::firstOrFail();
        $search = trim($request->string('q')->toString());

        // Filtro por huésped: la ficha manda aquí con su id, no con su
        // nombre — los nombres se repiten ("DAMARIS GOMEZ" y "Damaris
        // Michelle" son dos personas distintas en cabañas). Y con un
        // huésped a la vista el archivo deja de ser archivo: se muestran
        // TODAS sus reservas, también las vigentes. Si no, "Ver todo"
        // llevaba a una página vacía a quien solo tiene una reserva por
        // llegar (caso real 2026-09-15, RES-2026-1728).
        $guestId = (int) $request->integer('guest');
        $guest = $guestId > 0 ? Guest::withTrashed()->find($guestId) : null;

        $statuses = $guest !== null ? ReservationStatus::cases() : self::HISTORY_STATUSES;

        $status = ReservationStatus::tryFrom($request->string('status')->toString());

        if (! in_array($status, $statuses, true)) {
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
            ->whereIn('status', $status ? [$status] : $statuses)
            ->when($guest, fn ($query, Guest $guest) => $query->where('guest_id', $guest->id))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('guest_name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhereHas('room', fn ($r) => $r->where('number', 'like', "%{$search}%"));

                    // "RES-2026-0042" o "42" a secas: también busca por id.
                    if (preg_match('/(\d+)\s*$/', $search, $m)) {
                        $q->orWhere('id', (int) ltrim($m[1], '0'));
                    }
                });
            })
            // Con un huésped a la vista importa cuándo vino, no cuándo se
            // tocó el registro.
            ->when(
                $guest !== null,
                fn ($query) => $query->latest('starts_at'),
                fn ($query) => $query->latest('updated_at'),
            )
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $timeline = Activity::query()
            ->where('subject_type', Reservation::class)
            ->whereIn('subject_id', $paginator->getCollection()->modelKeys())
            ->latest()
            ->get()
            ->groupBy('subject_id');

        $paginator->through(fn (Reservation $r) => $this->serializeReservation($r, $timeline->get($r->id, collect())));

        return Inertia::render('tenant/reservations/History', [
            'property' => $property->only(['id', 'name']),
            'reservations' => $paginator,
            // Las cifras miran TODO el archivo (o el del huésped a la
            // vista), no la página ni el filtro de estado.
            'summary' => $this->summary($guest),
            'filters' => ['q' => $search, 'status' => $status?->value ?? '', 'guest' => $guest?->id],
            'guest' => $guest === null ? null : [
                'id' => $guest->id,
                'full_name' => $guest->full_name ?? 'Sin nombre',
                'is_archived' => $guest->trashed(),
            ],
            'statusOptions' => collect($statuses)
                ->map(fn (ReservationStatus $s) => ['value' => $s->value, 'label' => $s->label()])
                ->values(),
            'canManage' => $request->user()->can('reservations.manage'),
            'holdMinutes' => app(\App\Services\ReservationPolicy::class)->holdMinutes(),
        ]);
    }

    /**
     * Cómo terminaron las reservas y cuánto dejaron las que sí se usaron.
     *
     * @return array<string, mixed>
     */
    protected function summary(?Guest $guest): array
    {
        $base = fn () => Reservation::query()
            ->whereIn('status', self::HISTORY_STATUSES)
            ->when($guest, fn ($query, Guest $g) => $query->where('guest_id', $g->id));

        $completed = $base()->where('status', ReservationStatus::Completed);
        $revenue = round((float) $completed->sum('total_amount'), 2);

        return [
            'completed' => $base()->where('status', ReservationStatus::Completed)->count(),
            'cancelled' => $base()->where('status', ReservationStatus::Cancelled)->count(),
            'no_show' => $base()->where('status', ReservationStatus::NoShow)->count(),
            'revenue_label' => '$'.number_format($revenue, 2),
        ];
    }
}
