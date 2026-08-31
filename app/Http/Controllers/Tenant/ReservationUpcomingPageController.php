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
    protected const UPCOMING_STATUSES = [
        ReservationStatus::Pending,
        ReservationStatus::Confirmed,
    ];

    public function __invoke(Request $request): Response
    {
        $property = Property::firstOrFail();
        $search = trim($request->string('q')->toString());
        $status = ReservationStatus::tryFrom($request->string('status')->toString());

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
            ->paginate(25)
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
            'filters' => ['q' => $search, 'status' => $status?->value ?? ''],
            'statusOptions' => collect(self::UPCOMING_STATUSES)
                ->map(fn (ReservationStatus $s) => ['value' => $s->value, 'label' => $s->label()])
                ->values(),
            'canManage' => $request->user()->can('reservations.manage'),
        ]);
    }
}
