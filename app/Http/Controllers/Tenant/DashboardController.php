<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomStatusLog;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard del hotel (layout tipo "Hotel Performance Insights"): hero de
 * ingresos, rejilla de KPIs operativos con variación vs. ayer, series de 7
 * días para los mini-charts, semáforo/ocupación, distribución por tipo,
 * actividad reciente y llegadas/salidas del día.
 */
class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $today = Carbon::today();
        $yesterday = $today->copy()->subDay();
        $now = Carbon::now();

        $pct = static function (float $cur, float $prev): ?int {
            if ($prev <= 0) {
                return $cur > 0 ? 100 : null;
            }

            return (int) round(($cur - $prev) / $prev * 100);
        };

        $revenueOn = static function (Carbon $start, Carbon $end): float {
            $payments = (float) Payment::query()->whereBetween('paid_at', [$start, $end])->sum('amount');
            $orders = (float) Order::query()
                ->where('status', Order::STATUS_COMPLETED)
                ->whereBetween('created_at', [$start, $end])
                ->sum('total');

            return round($payments + $orders, 2);
        };

        // --- Semáforo de habitaciones ---
        $byStatus = Room::query()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $statuses = collect(RoomStatus::cases())->map(fn (RoomStatus $status) => [
            'value' => $status->value,
            'label' => $status->label(),
            'color' => $status->color(),
            'count' => (int) ($byStatus[$status->value] ?? 0),
        ]);

        $totalRooms = (int) $byStatus->sum();
        $occupied = (int) ($byStatus[RoomStatus::Occupied->value] ?? 0);
        $reserved = (int) ($byStatus[RoomStatus::Reserved->value] ?? 0);
        $available = (int) ($byStatus[RoomStatus::Available->value] ?? 0);
        $occupancyPct = $totalRooms > 0 ? (int) round($occupied / $totalRooms * 100) : 0;

        // --- Movimiento del día ---
        $arrivals = Reservation::query()
            ->with(['room:id,number'])
            ->whereDate('starts_at', $today)
            ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::CheckedIn])
            ->orderBy('starts_at')
            ->get();

        $departures = Reservation::query()
            ->with(['room:id,number'])
            ->whereDate('ends_at', $today)
            ->where('status', ReservationStatus::CheckedIn)
            ->orderBy('ends_at')
            ->get();

        $arrivalsYesterday = Reservation::query()
            ->whereDate('starts_at', $yesterday)
            ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::CheckedIn, ReservationStatus::Completed])
            ->count();

        $checkInsToday = Stay::query()->whereDate('check_in_at', $today)->count();
        $checkInsYesterday = Stay::query()->whereDate('check_in_at', $yesterday)->count();
        $checkOutsToday = Stay::query()->whereDate('check_out_at', $today)->count();
        $checkOutsYesterday = Stay::query()->whereDate('check_out_at', $yesterday)->count();

        $inHouse = Reservation::query()->where('status', ReservationStatus::CheckedIn)->count();
        $pendingReservations = Reservation::query()->where('status', ReservationStatus::Pending)->count();

        $ordersToday = Order::query()->where('status', Order::STATUS_COMPLETED)->whereDate('created_at', $today)->count();
        $ordersYesterday = Order::query()->where('status', Order::STATUS_COMPLETED)->whereDate('created_at', $yesterday)->count();
        $avgOrderToday = (float) Order::query()->where('status', Order::STATUS_COMPLETED)->whereDate('created_at', $today)->avg('total');

        $revenueToday = $revenueOn($today->copy()->startOfDay(), $now);
        $revenueYesterday = $revenueOn($yesterday->copy()->startOfDay(), $yesterday->copy()->endOfDay());

        // --- Hero: ingresos del mes vs. mismo rango del mes anterior ---
        $monthStart = $today->copy()->startOfMonth();
        $monthRevenue = $revenueOn($monthStart, $now);
        $lastMonthRevenue = $revenueOn(
            $monthStart->copy()->subMonthNoOverflow(),
            $now->copy()->subMonthNoOverflow(),
        );

        // --- Series de 7 días (ocupación real por estancias + ingresos) ---
        $revenueSeries = [];
        $occupancySeries = [];
        foreach (range(6, 0) as $offset) {
            $day = $today->copy()->subDays($offset);
            $dayStart = $day->copy()->startOfDay();
            $dayEnd = $day->copy()->endOfDay();

            $revenueSeries[] = [
                'label' => ucfirst($day->locale('es')->isoFormat('dd')),
                'value' => $revenueOn($dayStart, $dayEnd),
            ];

            $occ = Stay::query()
                ->where('check_in_at', '<=', $dayEnd)
                ->where(fn ($q) => $q->whereNull('check_out_at')->orWhere('check_out_at', '>=', $dayStart))
                ->count();

            $occupancySeries[] = [
                'label' => ucfirst($day->locale('es')->isoFormat('dd')),
                'value' => $totalRooms > 0 ? (int) round($occ / $totalRooms * 100) : 0,
            ];
        }

        $occTodaySeries = end($occupancySeries)['value'] ?? $occupancyPct;
        $occPrevSeries = $occupancySeries[count($occupancySeries) - 2]['value'] ?? 0;

        // --- Distribución por tipo de habitación (donut) ---
        $roomTypeDistribution = RoomType::query()
            ->withCount('rooms')
            ->orderByDesc('rooms_count')
            ->get()
            ->map(fn (RoomType $type) => ['label' => $type->name, 'count' => $type->rooms_count])
            ->filter(fn ($t) => $t['count'] > 0)
            ->values();

        // --- Rejilla de métricas (valor + variación real donde aplica) ---
        $metrics = [
            ['title' => 'Ocupación', 'value' => $occupancyPct.'%', 'change' => $pct($occTodaySeries, $occPrevSeries), 'desc' => 'Habitaciones ocupadas sobre el total.'],
            ['title' => 'Habitaciones ocupadas', 'value' => (string) $occupied, 'change' => null, 'desc' => 'Habitaciones con huésped en este momento.'],
            ['title' => 'Habitaciones libres', 'value' => (string) $available, 'change' => null, 'desc' => 'Disponibles para venta o walk-in.'],
            ['title' => 'Llegadas de hoy', 'value' => (string) $arrivals->count(), 'change' => $pct($arrivals->count(), $arrivalsYesterday), 'desc' => 'Reservas cuyo check-in es hoy.'],
            ['title' => 'Salidas de hoy', 'value' => (string) $departures->count(), 'change' => null, 'desc' => 'Estancias que terminan hoy.'],
            ['title' => 'Check-ins hoy', 'value' => (string) $checkInsToday, 'change' => $pct($checkInsToday, $checkInsYesterday), 'desc' => 'Huéspedes registrados hoy.'],
            ['title' => 'Check-outs hoy', 'value' => (string) $checkOutsToday, 'change' => $pct($checkOutsToday, $checkOutsYesterday), 'desc' => 'Salidas ya procesadas hoy.'],
            ['title' => 'Huéspedes en casa', 'value' => (string) $inHouse, 'change' => null, 'desc' => 'Reservas actualmente en estancia.'],
            ['title' => 'Por confirmar', 'value' => (string) $pendingReservations, 'change' => null, 'desc' => 'Reservas pendientes de confirmación.'],
            ['title' => 'Órdenes POS hoy', 'value' => (string) $ordersToday, 'change' => $pct($ordersToday, $ordersYesterday), 'desc' => 'Ventas de bar/cocina/minibar de hoy.'],
            ['title' => 'Ingresos hoy', 'value' => $this->money($revenueToday), 'change' => $pct($revenueToday, $revenueYesterday), 'desc' => 'Pagos de reservas + ventas POS de hoy.'],
            ['title' => 'Ticket promedio', 'value' => $this->money($avgOrderToday), 'change' => null, 'desc' => 'Importe medio por orden de POS hoy.'],
        ];

        $limits = tenant()->planLimits();

        $recentActivity = RoomStatusLog::query()
            ->with(['room:id,number', 'changedBy:id,name'])
            ->latest('created_at')
            ->take(6)
            ->get()
            ->map(fn (RoomStatusLog $log) => [
                'id' => $log->id,
                'room' => $log->room?->number,
                'from' => $log->from_status ? RoomStatus::from($log->from_status)->label() : null,
                'to' => RoomStatus::from($log->to_status)->label(),
                'to_color' => RoomStatus::from($log->to_status)->color(),
                'by' => $log->changedBy?->name ?? 'Sistema',
                'at' => $log->created_at->diffForHumans(),
            ]);

        return Inertia::render('tenant/Dashboard', [
            'hero' => [
                'revenue' => $this->money($monthRevenue),
                'change' => $pct($monthRevenue, $lastMonthRevenue),
                'month' => ucfirst($today->locale('es')->isoFormat('MMMM YYYY')),
            ],
            'metrics' => $metrics,
            'series' => [
                'revenue' => $revenueSeries,
                'occupancy' => $occupancySeries,
                'revenue_today' => $revenueToday,
                'revenue_change' => $pct($revenueToday, $revenueYesterday),
                'occupancy_today' => $occTodaySeries,
                'occupancy_change' => $pct($occTodaySeries, $occPrevSeries),
            ],
            'guestStatus' => [
                'in_house' => $inHouse,
                'checked_out' => $checkOutsToday,
                'pending' => $pendingReservations,
            ],
            'roomTypeDistribution' => $roomTypeDistribution,
            'statuses' => $statuses,
            'occupancy' => ['occupied' => $occupied, 'total' => $totalRooms, 'percent' => $occupancyPct, 'reserved' => $reserved, 'available' => $available],
            'arrivals' => $arrivals->map(fn (Reservation $r) => [
                'id' => $r->id,
                'code' => $r->displayCode(),
                'guest_name' => $r->guest_name,
                'room' => $r->room?->number,
                'eta' => $r->eta ? substr((string) $r->eta, 0, 5) : null,
                'time' => $r->starts_at->format('H:i'),
                'people' => $r->num_people,
                'checked_in' => $r->status === ReservationStatus::CheckedIn,
            ]),
            'departures' => $departures->map(fn (Reservation $r) => [
                'id' => $r->id,
                'code' => $r->displayCode(),
                'guest_name' => $r->guest_name,
                'room' => $r->room?->number,
                'time' => $r->ends_at->format('H:i'),
                'balance' => $r->pendingBalance(),
            ]),
            'totals' => [
                'rooms' => $totalRooms,
                'zones' => Zone::count(),
                'roomTypes' => RoomType::count(),
                'staff' => User::count(),
                'properties' => Property::count(),
            ],
            'plan' => [
                'name' => $limits['label'] ?? tenant('plan'),
                'max_rooms' => $limits['max_rooms'] ?? null,
                'max_users' => $limits['max_users'] ?? null,
            ],
            'recentActivity' => $recentActivity,
        ]);
    }

    private function money(float $amount): string
    {
        return '$'.number_format($amount, 0, '.', ',');
    }
}
