<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ReservationStatus;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Stay;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Pendientes (/reservas/pendientes): los dos trabajos que nadie ve hasta
 * que alguien los va a buscar —lo que falta CONFIRMAR y lo que falta
 * COBRAR— en una sola pantalla.
 *
 * Los apartados paginan de diez en diez y se trabajan en la ficha de cada
 * reserva; las cuentas son un asomo, porque su bandeja con acciones
 * (cobrar, agregar cargos, cerrar con motivo) vive en /reservas/cuentas.
 */
class PendingReservationsPageController extends ReservationsPageController
{
    /** Diez por página: el tablero manda aquí a revisar, no a leer un archivo. */
    protected const PER_PAGE = 10;

    protected const SETTLEMENTS_PREVIEW = 10;

    /** Filtros del reloj del apartado (chips y dona). */
    protected const STATE_FILTERS = ['vencido', 'por_vencer', 'en_tiempo', 'sin_vencimiento', 'transferencia'];

    public function __invoke(Request $request): Response
    {
        $property = Property::firstOrFail();
        $search = trim($request->string('q')->toString());
        $state = $request->string('estado')->toString();
        if (! in_array($state, self::STATE_FILTERS, true)) {
            $state = '';
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
            // ¿Hay transferencia esperando verificación? Confirmar a mano NO
            // registra ese dinero: se aprueba en /pagos.
            ->withExists(['paymentRequests as pending_transfer_request' => fn ($q) => $q
                ->where('method', \App\Models\PaymentRequest::METHOD_TRANSFER)
                ->where('status', \App\Models\PaymentRequest::STATUS_PENDING)])
            ->where('status', ReservationStatus::Pending)
            // Un apartado de una fecha que ya pasó no es trabajo pendiente:
            // lo resolvió el vencimiento o el no-show.
            ->where('ends_at', '>=', now())
            ->when($state !== '', fn ($query) => $this->whereState($query, $state, $now))
            ->when($search !== '', fn ($query) => $query->where(function ($q) use ($search) {
                $q->where('guest_name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhereHas('room', fn ($r) => $r->where('number', 'like', "%{$search}%"))
                    ->orWhereHas('guest', fn ($g) => $g->search($search));

                if (preg_match('/(\d+)\s*$/', $search, $m)) {
                    $q->orWhere('id', (int) ltrim($m[1], '0'));
                }
            }))
            // Primero lo que está por vencerse, que es lo que se pierde solo.
            ->orderByRaw('hold_expires_at is null, hold_expires_at')
            ->orderBy('starts_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $timeline = Activity::query()
            ->where('subject_type', Reservation::class)
            ->whereIn('subject_id', $paginator->getCollection()->modelKeys())
            ->latest()
            ->get()
            ->groupBy('subject_id');

        // El reloj del apartado en palabras ya viene en serializeReservation
        // (hold_state / hold_countdown): la hora sola ("11:20") no decía si ya
        // había pasado.
        $paginator->through(fn (Reservation $r) => $this->serializeReservation($r, $timeline->get($r->id, collect())));

        return Inertia::render('tenant/reservations/Pending', [
            'property' => $property->only(['id', 'name']),
            'reservations' => $paginator,
            'settlements' => Stay::query()
                ->pendingSettlement()
                ->with(['room:id,number', 'guest:id,first_name,last_name', 'reservation:id,code,created_at'])
                // Primero la más vieja: es la que más se va a tardar en cobrar.
                ->orderBy('stays.check_out_at')
                ->limit(self::SETTLEMENTS_PREVIEW)
                ->get()
                ->map(fn (Stay $stay) => [
                    'id' => $stay->id,
                    'room' => $stay->room?->number,
                    'guest_name' => $stay->guest?->full_name ?? $stay->guest_name ?? 'Anónimo',
                    'reservation_code' => $stay->reservation?->displayCode(),
                    'check_out_at' => $stay->check_out_at?->format('d/m/Y H:i'),
                    'pending' => $stay->pendingSettlementAmount(),
                    // La cerró el reloj: nadie estuvo en el mostrador para
                    // cobrar, y eso cambia a quién se le pregunta qué pasó.
                    'auto_closed' => $stay->auto_closed_at !== null,
                ]),
            'settlementsTotal' => Stay::query()->pendingSettlement()->count(),
            'summary' => $this->summary(),
            'filters' => ['q' => $search, 'estado' => $state],
            'canManage' => $request->user()->can('reservations.manage'),
            'holdMinutes' => app(\App\Services\ReservationPolicy::class)->holdMinutes(),
        ]);
    }

    protected function whereState(\Illuminate\Database\Eloquent\Builder $query, string $state, \Carbon\CarbonInterface $now): \Illuminate\Database\Eloquent\Builder
    {
        return app(\App\Services\Reservations\BoardMetrics::class)->whereHoldState($query, $state, $now);
    }

    /**
     * Lo que está en juego (BoardMetrics::pending). Con $charts trae también
     * lo que grafica /reservas/pendientes/reporte.
     *
     * @return array<string, mixed>
     */
    protected function summary(bool $charts = false): array
    {
        return app(\App\Services\Reservations\BoardMetrics::class)->pending($charts);
    }
}
