<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Stay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tablero de reservas (/reservas): cuatro accesos —próximas, en casa,
 * pendientes e historial— cada uno con su número y su propia pantalla. La
 * operación del día (calendario, nueva reserva, llegada exprés, registrar
 * salida y cobros) vive en /reservas/operacion.
 *
 * Lo único que se lista es lo que ENTRÓ hoy y ayer (con tope); lo demás
 * solo se cuenta. Así la entrada a la sección cuesta lo mismo con veinte
 * reservas que con veinte mil.
 */
class ReservationsHubController extends Controller
{
    /** Ventana de la alerta de apartados por vencer. */
    private const EXPIRING_MINUTES = 30;

    /**
     * Tope de la tabla de recién llegadas. Un día bueno de puente puede
     * traer decenas: el tablero enseña las últimas y manda al área.
     */
    private const FRESH_LIMIT = 15;

    /**
     * Parámetros que solo sabe atender la operación del día. Los avisos del
     * bot, los correos y las notificaciones ya enviadas apuntan a /reservas
     * con ellos: siguen llevando a donde se abren esos modales.
     */
    private const OPERATION_PARAMS = ['intent', 'reservation', 'stay', 'edit', 'guest', 'room'];

    public function __invoke(Request $request): Response|RedirectResponse
    {
        if ($request->hasAny(self::OPERATION_PARAMS)) {
            return redirect()->route('tenant.reservations.operation', $request->query());
        }

        $property = Property::firstOrFail();
        $now = now();
        $dayStart = $now->copy()->startOfDay();
        $dayEnd = $now->copy()->endOfDay();

        // Próximas: mismo corte que /reservas/proximas — sigue viva mientras
        // no termine, aunque la llegada ya haya pasado.
        $upcoming = fn () => Reservation::query()
            ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed])
            ->where('ends_at', '>=', $now);

        $activeStays = fn () => Stay::query()->active();

        $historyStatuses = [
            ReservationStatus::Completed,
            ReservationStatus::Cancelled,
            ReservationStatus::NoShow,
        ];

        return Inertia::render('tenant/reservations/Hub', [
            'property' => $property->only(['id', 'name']),
            'upcoming' => [
                'total' => $upcoming()->count(),
                'today' => $upcoming()->whereBetween('starts_at', [$dayStart, $dayEnd])->count(),
                // La llegada ya pasó y nadie registró la entrada: es trabajo
                // atorado, no una reserva más.
                'arrival_pending' => Reservation::query()
                    ->where('status', ReservationStatus::Confirmed)
                    ->where('starts_at', '<', $now)
                    ->where('ends_at', '>=', $now)
                    ->count(),
            ],
            'inHouse' => [
                'total' => $activeStays()->count(),
                'departures_today' => $activeStays()->whereBetween('planned_end_at', [$dayStart, $dayEnd])->count(),
                'overdue' => $activeStays()->where('planned_end_at', '<', $now)->count(),
            ],
            'pending' => [
                // Apartados que esperan confirmación (o que caiga el anticipo).
                'total' => $upcoming()->where('status', ReservationStatus::Pending)->count(),
                'expiring' => $upcoming()
                    ->where('status', ReservationStatus::Pending)
                    ->whereNotNull('hold_expires_at')
                    ->whereBetween('hold_expires_at', [$now, $now->copy()->addMinutes(self::EXPIRING_MINUTES)])
                    ->count(),
                // Cuentas cerradas con saldo: ni el cierre automático de
                // estancias ni el cierre de día de reservas cobran. Van
                // sumadas porque el tablero manda a una sola bandeja.
                'settlements' => Stay::query()->pendingSettlement()->count()
                    + Reservation::query()->pendingSettlement()->count(),
            ],
            'history' => [
                'total' => Reservation::query()->whereIn('status', $historyStatuses)->count(),
                'last_week' => Reservation::query()
                    ->whereIn('status', $historyStatuses)
                    ->where('updated_at', '>=', $now->copy()->subDays(7))
                    ->count(),
            ],
            // El pulso de la semana: cuánta gente llega y sale cada día.
            // Antes el tablero solo decía "hoy", y el puente que venía no
            // se veía hasta que ya estaba encima.
            'week' => $this->weekAhead($dayStart),
            'fresh' => $this->freshReservations($dayStart),
            'canManage' => $request->user()->can('reservations.manage'),
        ]);
    }

    /**
     * Llegadas y salidas de los próximos siete días, contadas por día.
     *
     * Se traen las fechas del rango en dos consultas y se agrupan en PHP:
     * agrupar por día en SQL cambia de dialecto (y aquí conviven MySQL en
     * producción con sqlite en los tests).
     *
     * @return array<string, mixed>
     */
    protected function weekAhead(\Carbon\CarbonInterface $dayStart): array
    {
        $rangeEnd = $dayStart->copy()->addDays(7);

        $arrivals = Reservation::query()
            ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed])
            ->whereBetween('starts_at', [$dayStart, $rangeEnd])
            ->pluck('starts_at')
            ->countBy(fn ($date) => $date->format('Y-m-d'));

        $departures = Reservation::query()
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn])
            ->whereBetween('ends_at', [$dayStart, $rangeEnd])
            ->pluck('ends_at')
            ->countBy(fn ($date) => $date->format('Y-m-d'));

        $names = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $day = $dayStart->copy()->addDays($i);
            $key = $day->format('Y-m-d');

            $days[] = [
                'date' => $key,
                'label' => match ($i) {
                    0 => 'Hoy',
                    1 => 'Mañana',
                    default => $names[$day->dayOfWeek].' '.$day->day,
                },
                'weekend' => in_array($day->dayOfWeek, [0, 5, 6], true),
                'arrivals' => $arrivals[$key] ?? 0,
                'departures' => $departures[$key] ?? 0,
            ];
        }

        return [
            'days' => $days,
            'arrivals' => array_sum(array_column($days, 'arrivals')),
            'departures' => array_sum(array_column($days, 'departures')),
        ];
    }

    /**
     * Lo que ENTRÓ hoy y ayer: reservas recién creadas, por cualquier canal.
     *
     * Hoy se marcan "Nuevo", mañana esas mismas aparecen como "Ayer" y al
     * tercer día se caen solas de la tabla — la lista no crece, y lo que
     * sigue vivo ya vive en su área (próximas, en casa, pendientes o
     * historial), a donde apunta cada renglón.
     *
     * @return array<string, mixed>
     */
    protected function freshReservations(\Carbon\CarbonInterface $dayStart): array
    {
        $yesterdayStart = $dayStart->copy()->subDay();

        $rows = Reservation::query()
            ->with(['room:id,number', 'roomType:id,name'])
            ->where('created_at', '>=', $yesterdayStart)
            ->latest('created_at')
            ->limit(self::FRESH_LIMIT)
            ->get()
            ->map(function (Reservation $r) use ($dayStart) {
                [$area, $areaLabel] = $this->areaFor($r->status);

                return [
                    'id' => $r->id,
                    'code' => $r->displayCode(),
                    'guest_name' => $r->guest_name,
                    'room' => $r->room?->number,
                    'room_type' => $r->roomType?->name,
                    'starts_at' => $r->starts_at->format('d/m/Y H:i'),
                    'ends_at' => $r->ends_at->format('d/m/Y H:i'),
                    'created_at' => $r->created_at->format('d/m/Y H:i'),
                    'total_amount' => $r->total_amount,
                    'status' => $r->status->value,
                    'status_label' => $r->status->label(),
                    'source_channel' => $r->source_channel,
                    // La etiqueta se calcula en el servidor: el navegador del
                    // mostrador puede tener otra hora (o el hotel otra zona).
                    'freshness' => $r->created_at >= $dayStart ? 'today' : 'yesterday',
                    'area' => $area,
                    'area_label' => $areaLabel,
                ];
            });

        return [
            'rows' => $rows->values()->all(),
            'total' => Reservation::query()->where('created_at', '>=', $yesterdayStart)->count(),
            'today' => Reservation::query()->where('created_at', '>=', $dayStart)->count(),
        ];
    }

    /**
     * A qué vista pertenece cada reserva según su estado. Es el mismo corte
     * que usan las cuatro pantallas, para que el renglón lleve a donde de
     * verdad se va a trabajar.
     *
     * @return array{0: string, 1: string}
     */
    protected function areaFor(ReservationStatus $status): array
    {
        return match ($status) {
            ReservationStatus::Pending => ['pending', 'Pendientes'],
            ReservationStatus::Confirmed => ['upcoming', 'Próximas'],
            ReservationStatus::CheckedIn => ['in-house', 'En casa'],
            default => ['history', 'Historial'],
        };
    }
}
