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

    /** Atajos de llegada: los chips de la franja de filtros. */
    protected const WHEN_FILTERS = ['hoy', 'manana', 'semana'];

    protected const PAYMENT_FILTERS = \App\Services\Reservations\BoardMetrics::PAYMENTS;

    protected const UPCOMING_STATUSES = \App\Services\Reservations\BoardMetrics::UPCOMING_STATUSES;

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

        $when = $request->string('cuando')->toString();
        if (! in_array($when, self::WHEN_FILTERS, true) || $date) {
            $when = '';
        }
        $payment = $request->string('pago')->toString();
        if (! in_array($payment, self::PAYMENT_FILTERS, true)) {
            $payment = '';
        }
        $now = now();

        $paginator = Reservation::query()
            ->with([
                'room:id,number',
                'roomType:id,name',
                'ratePlan:id,name,type',
                'guest:id,first_name,last_name,phone,email',
            ])
            ->withSum('payments', 'amount')
            // Confirmar a mano con una transferencia esperando verificación
            // deja el dinero en el aire: el modal de confirmar lo avisa.
            ->withExists(['paymentRequests as pending_transfer_request' => fn ($q) => $q
                ->where('method', \App\Models\PaymentRequest::METHOD_TRANSFER)
                ->where('status', \App\Models\PaymentRequest::STATUS_PENDING)])
            ->whereIn('status', $status ? [$status] : self::UPCOMING_STATUSES)
            // Mismo corte que la lista de /reservas: sigue viva mientras no
            // termine, aunque la llegada ya haya pasado.
            ->where('ends_at', '>=', now())
            ->when($date, fn ($query) => $query->whereBetween('starts_at', [
                $date->copy()->startOfDay(),
                $date->copy()->endOfDay(),
            ]))
            ->when($when !== '', fn ($query) => $query->whereBetween('starts_at', match ($when) {
                'hoy' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
                'manana' => [$now->copy()->addDay()->startOfDay(), $now->copy()->addDay()->endOfDay()],
                default => [$now->copy()->startOfDay(), $now->copy()->addDays(6)->endOfDay()],
            }))
            ->when($payment !== '', fn ($query) => $this->wherePayment($query, $payment))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('guest_name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhereHas('room', fn ($r) => $r->where('number', 'like', "%{$search}%"))
                        // Teléfono, correo o nombre en la ficha del CRM.
                        ->orWhereHas('guest', fn ($g) => $g->search($search));

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
                'cuando' => $when,
                'pago' => $payment,
            ],
            'statusOptions' => collect(self::UPCOMING_STATUSES)
                ->map(fn (ReservationStatus $s) => ['value' => $s->value, 'label' => $s->label()])
                ->values(),
            'canManage' => $request->user()->can('reservations.manage'),
            // Con check-in automático puro el botón "Recibir" no existe.
            'manualCheckinAllowed' => app(\App\Services\HousekeepingPolicy::class)->manualCheckInAllowed(),
            'holdMinutes' => $this->policy()->holdMinutes(),
        ]);
    }

    /** Cómo viene pagada (mismo corte que la dona del reporte). */
    protected function wherePayment(\Illuminate\Database\Eloquent\Builder $query, string $payment): \Illuminate\Database\Eloquent\Builder
    {
        return app(\App\Services\Reservations\BoardMetrics::class)->wherePayment($query, $payment);
    }

    /**
     * La franja de cifras (BoardMetrics::upcoming). Con $charts trae también
     * lo que grafica /reservas/proximas/reporte.
     *
     * @return array<string, mixed>
     */
    protected function summary(bool $charts = false): array
    {
        return app(\App\Services\Reservations\BoardMetrics::class)->upcoming($charts);
    }
}
