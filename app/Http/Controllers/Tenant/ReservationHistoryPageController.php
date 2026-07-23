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
 * Historial COMPLETO de reservas (/reservas/historial): la lista de
 * /reservas solo trae las últimas 20 para no crecer sin freno; aquí vive
 * todo lo que ya salió del flujo (completadas, canceladas y no-shows) con
 * buscador, filtro por estado, paginación y las acciones de siempre
 * (detalle y borrado). Extiende ReservationsPageController solo para
 * reutilizar la serialización — la vista es propia.
 */
class ReservationHistoryPageController extends ReservationsPageController
{
    protected const HISTORY_STATUSES = [
        ReservationStatus::Completed,
        ReservationStatus::Cancelled,
        ReservationStatus::NoShow,
    ];

    public function __invoke(Request $request): Response
    {
        $property = Property::firstOrFail();
        $search = trim($request->string('q')->toString());
        $status = ReservationStatus::tryFrom($request->string('status')->toString());

        if (! in_array($status, self::HISTORY_STATUSES, true)) {
            $status = null;
        }

        $paginator = Reservation::query()
            ->with([
                'room:id,number',
                'roomType:id,name',
                'ratePlan:id,name,type',
                'guest:id,first_name,last_name,phone,email',
            ])
            ->whereIn('status', $status ? [$status] : self::HISTORY_STATUSES)
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
            ->latest('updated_at')
            ->paginate(25)
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
            'filters' => ['q' => $search, 'status' => $status?->value ?? ''],
            'statusOptions' => collect(self::HISTORY_STATUSES)
                ->map(fn (ReservationStatus $s) => ['value' => $s->value, 'label' => $s->label()])
                ->values(),
            'canManage' => $request->user()->can('reservations.manage'),
        ]);
    }
}
