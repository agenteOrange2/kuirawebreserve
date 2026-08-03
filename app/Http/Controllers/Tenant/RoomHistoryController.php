<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomStatusLog;
use App\Models\Stay;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Historial de una habitación: próximas reservas, uso por periodo (semana /
 * mes / 3 meses / año), serie mensual de uso e ingresos, estancias recientes
 * e historial del semáforo.
 */
class RoomHistoryController extends Controller
{
    public function __invoke(Room $room): Response
    {
        $room->load(['zone:id,name,color', 'roomType:id,name']);
        $now = Carbon::now();

        $usageCount = fn (Carbon $from) => Stay::query()
            ->where('room_id', $room->id)
            ->where('check_in_at', '>=', $from)
            ->count();

        $usageRevenue = fn (Carbon $from) => round((float) Stay::query()
            ->where('room_id', $room->id)
            ->where('check_in_at', '>=', $from)
            ->sum('amount'), 2);

        $periods = [
            ['key' => 'week', 'label' => 'Esta semana', 'from' => $now->copy()->startOfWeek()],
            ['key' => 'month', 'label' => 'Este mes', 'from' => $now->copy()->startOfMonth()],
            ['key' => 'quarter', 'label' => 'Últimos 3 meses', 'from' => $now->copy()->subMonthsNoOverflow(3)->startOfDay()],
            ['key' => 'year', 'label' => 'Este año', 'from' => $now->copy()->startOfYear()],
        ];

        $usage = collect($periods)->map(fn (array $p) => [
            'key' => $p['key'],
            'label' => $p['label'],
            'count' => $usageCount($p['from']),
            'revenue' => $usageRevenue($p['from']),
        ])->values();

        $monthly = [];
        foreach (range(11, 0) as $offset) {
            $monthStart = $now->copy()->subMonthsNoOverflow($offset)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();

            $stays = Stay::query()->where('room_id', $room->id)->whereBetween('check_in_at', [$monthStart, $monthEnd]);

            $monthly[] = [
                'label' => ucfirst($monthStart->locale('es')->isoFormat('MMM')),
                'count' => (clone $stays)->count(),
                'revenue' => round((float) (clone $stays)->sum('amount'), 2),
            ];
        }

        // Lo que viene para esta habitación: reservas vivas (pendientes o
        // confirmadas) ordenadas por llegada — el "quién sigue" del plano.
        $upcomingReservations = Reservation::query()
            ->where('room_id', $room->id)
            ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed])
            ->where('ends_at', '>=', $now)
            ->with(['guest:id,first_name,last_name', 'ratePlan:id,name'])
            ->orderBy('starts_at')
            ->take(20)
            ->get()
            ->map(fn (Reservation $reservation) => [
                'id' => $reservation->id,
                'code' => $reservation->displayCode(),
                'guest_name' => $reservation->guest?->full_name ?? $reservation->guest_name ?? 'Anónimo',
                'rate_plan' => $reservation->ratePlan?->name,
                'status' => $reservation->status->value,
                'status_label' => $reservation->status->label(),
                'payment_status_label' => $reservation->payment_status?->label(),
                'starts_at' => $reservation->starts_at->format('d/m/Y H:i'),
                'ends_at' => $reservation->ends_at->format('d/m/Y H:i'),
                'starts_today' => $reservation->starts_at->isToday(),
                'total_amount' => (float) $reservation->total_amount,
            ]);

        $recentStays = Stay::query()
            ->where('room_id', $room->id)
            ->with('guest:id,first_name,last_name')
            ->latest('check_in_at')
            ->take(15)
            ->get()
            ->map(fn (Stay $stay) => [
                'id' => $stay->id,
                'guest_name' => $stay->guest_name ?? trim(($stay->guest?->first_name ?? '').' '.($stay->guest?->last_name ?? '')) ?: 'Anónimo',
                'check_in_at' => $stay->check_in_at?->format('d/m/Y H:i'),
                'check_out_at' => $stay->check_out_at?->format('d/m/Y H:i'),
                'active' => $stay->status === Stay::STATUS_ACTIVE,
                'amount' => (float) $stay->amount,
                'channel' => $stay->channel,
                'nights' => $stay->check_out_at
                    ? max(1, $stay->check_in_at->copy()->startOfDay()->diffInDays($stay->check_out_at->copy()->startOfDay()))
                    : null,
            ]);

        $statusHistory = RoomStatusLog::query()
            ->where('room_id', $room->id)
            ->with('changedBy:id,name')
            ->latest('created_at')
            ->latest('id')
            ->take(40)
            ->get()
            ->map(fn (RoomStatusLog $log) => [
                'id' => $log->id,
                'from' => $log->from_status ? RoomStatus::from($log->from_status)->label() : null,
                'to' => RoomStatus::from($log->to_status)->label(),
                'to_color' => RoomStatus::from($log->to_status)->color(),
                'by' => $log->changedBy?->name ?? 'Sistema',
                'auto' => (bool) ($log->context['auto'] ?? false),
                // Día aparte para agrupar la línea de tiempo por fecha.
                'day' => ucfirst($log->created_at->locale('es')->isoFormat('dddd D [de] MMMM')),
                'date' => $log->created_at->format('Y-m-d'),
                'time' => $log->created_at->format('H:i'),
            ]);

        // Limpieza y mantenimiento derivados del log del semáforo (últimos
        // 120 días, en orden): cada entrada a "en limpieza" abre un ciclo —
        // cuánto esperó en sucia, cuánto duró la limpieza y quién la hizo;
        // cada entrada a "mantenimiento" abre un periodo hasta que salió.
        $segmentLogs = RoomStatusLog::query()
            ->where('room_id', $room->id)
            ->where('created_at', '>=', $now->copy()->subDays(120))
            ->with('changedBy:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->values();

        $cleaningCycles = [];
        $maintenancePeriods = [];

        foreach ($segmentLogs as $i => $log) {
            $next = $segmentLogs[$i + 1] ?? null;
            $prev = $i > 0 ? $segmentLogs[$i - 1] : null;

            if ($log->to_status === RoomStatus::Cleaning->value) {
                $cleaningCycles[] = [
                    'id' => $log->id,
                    'day' => ucfirst($log->created_at->locale('es')->isoFormat('D MMM')),
                    'started_at' => $log->created_at->format('d/m/Y H:i'),
                    'by' => ($log->context['auto'] ?? false) ? 'Automático' : ($log->changedBy?->name ?? 'Sistema'),
                    'auto' => (bool) ($log->context['auto'] ?? false),
                    // Cuánto esperó en sucia antes de que empezara la limpieza.
                    'dirty_minutes' => $prev && $prev->to_status === RoomStatus::Dirty->value
                        ? (int) round($prev->created_at->diffInMinutes($log->created_at))
                        : null,
                    'duration_minutes' => $next
                        ? (int) round($log->created_at->diffInMinutes($next->created_at))
                        : null,
                    'ongoing' => $next === null,
                    'ended_status' => $next ? RoomStatus::from($next->to_status)->label() : null,
                ];
            }

            if ($log->to_status === RoomStatus::Maintenance->value) {
                $maintenancePeriods[] = [
                    'id' => $log->id,
                    'started_at' => $log->created_at->format('d/m/Y H:i'),
                    'ended_at' => $next?->created_at->format('d/m/Y H:i'),
                    'by' => $log->changedBy?->name ?? 'Sistema',
                    'duration_minutes' => $next
                        ? (int) round($log->created_at->diffInMinutes($next->created_at))
                        : (int) round($log->created_at->diffInMinutes($now)),
                    'ongoing' => $next === null,
                ];
            }
        }

        $completedCycles = collect($cleaningCycles)->filter(fn (array $c) => $c['duration_minutes'] !== null);
        $cleaningStats = [
            'last30' => collect($cleaningCycles)
                ->filter(fn (array $c) => Carbon::createFromFormat('d/m/Y H:i', $c['started_at'])->gte($now->copy()->subDays(30)))
                ->count(),
            'avg_duration' => $completedCycles->isNotEmpty()
                ? (int) round($completedCycles->avg('duration_minutes'))
                : null,
            'avg_dirty_wait' => ($waits = $completedCycles->whereNotNull('dirty_minutes'))->isNotEmpty()
                ? (int) round($waits->avg('dirty_minutes'))
                : null,
        ];

        return Inertia::render('tenant/rooms/History', [
            'room' => [
                'id' => $room->id,
                'number' => $room->number,
                'name' => $room->name,
                'room_type' => $room->roomType->name,
                'zone' => $room->zone?->name,
                'status_label' => $room->status->label(),
                'status_color' => $room->status->color(),
            ],
            'usage' => $usage,
            'monthly' => $monthly,
            'upcomingReservations' => $upcomingReservations,
            'recentStays' => $recentStays,
            'statusHistory' => $statusHistory,
            'cleaningCycles' => collect($cleaningCycles)->reverse()->take(10)->values(),
            'cleaningStats' => $cleaningStats,
            'maintenancePeriods' => collect($maintenancePeriods)->reverse()->take(8)->values(),
            'totals' => [
                'stays' => Stay::query()->where('room_id', $room->id)->count(),
                'revenue' => round((float) Stay::query()->where('room_id', $room->id)->sum('amount'), 2),
            ],
        ]);
    }
}
