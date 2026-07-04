<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\RoomStatusLog;
use App\Models\Stay;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Historial de una habitación: uso por periodo (semana / mes / 3 meses /
 * año), serie mensual de uso e ingresos, estancias recientes e historial
 * del semáforo.
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
            ->take(25)
            ->get()
            ->map(fn (RoomStatusLog $log) => [
                'id' => $log->id,
                'from' => $log->from_status ? RoomStatus::from($log->from_status)->label() : null,
                'to' => RoomStatus::from($log->to_status)->label(),
                'to_color' => RoomStatus::from($log->to_status)->color(),
                'by' => $log->changedBy?->name ?? 'Sistema',
                'at' => $log->created_at->format('d/m/Y H:i'),
            ]);

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
            'recentStays' => $recentStays,
            'statusHistory' => $statusHistory,
            'totals' => [
                'stays' => Stay::query()->where('room_id', $room->id)->count(),
                'revenue' => round((float) Stay::query()->where('room_id', $room->id)->sum('amount'), 2),
            ],
        ]);
    }
}
