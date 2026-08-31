<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomCleaning;
use App\Models\Stay;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ficha detallada de una habitación: qué pasa ahora mismo en el cuarto
 * (estancia activa, próximas reservas, fallas abiertas y limpieza), su
 * perfil completo y el uso por periodo (semana / mes / 3 meses / año).
 */
class RoomShowController extends Controller
{
    public function __invoke(Room $room): Response
    {
        $room->load(['zone:id,name,color', 'roomType:id,name,capacity']);

        $now = Carbon::now();

        // "Uso" = estancias cuyo check-in cae en el periodo.
        $usage = fn (Carbon $from) => Stay::query()
            ->where('room_id', $room->id)
            ->where('check_in_at', '>=', $from)
            ->count();

        $revenue = fn (Carbon $from) => round((float) Stay::query()
            ->where('room_id', $room->id)
            ->where('check_in_at', '>=', $from)
            ->sum('amount'), 2);

        $periods = [
            ['key' => 'week', 'label' => 'Esta semana', 'from' => $now->copy()->startOfWeek()],
            ['key' => 'month', 'label' => 'Este mes', 'from' => $now->copy()->startOfMonth()],
            ['key' => 'quarter', 'label' => 'Últimos 3 meses', 'from' => $now->copy()->subMonthsNoOverflow(3)->startOfDay()],
            ['key' => 'year', 'label' => 'Este año', 'from' => $now->copy()->startOfYear()],
        ];

        $usageStats = collect($periods)->map(fn (array $p) => [
            'key' => $p['key'],
            'label' => $p['label'],
            'count' => $usage($p['from']),
            'revenue' => $revenue($p['from']),
        ])->values();

        $totalStays = Stay::query()->where('room_id', $room->id)->count();
        $totalRevenue = round((float) Stay::query()->where('room_id', $room->id)->sum('amount'), 2);
        $lastStayAt = Stay::query()->where('room_id', $room->id)->max('check_in_at');

        // Lo primero que se pregunta al abrir un cuarto: quién está dentro.
        $activeStay = Stay::query()
            ->with(['guest', 'ratePlan:id,name'])
            ->where('room_id', $room->id)
            ->where('status', Stay::STATUS_ACTIVE)
            ->latest('check_in_at')
            ->first();

        $folio = $activeStay?->folio();

        // Lo que viene para este cuarto (las cinco más cercanas).
        $upcoming = Reservation::query()
            ->with('guest')
            ->where('room_id', $room->id)
            ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed])
            ->where('ends_at', '>=', $now)
            ->orderBy('starts_at')
            ->limit(5)
            ->get();

        // Y lo que ya pasó: las últimas salidas, no un contador suelto.
        $recent = Stay::query()
            ->with('guest')
            ->where('room_id', $room->id)
            ->whereNotNull('check_out_at')
            ->orderByDesc('check_out_at')
            ->limit(6)
            ->get();

        $incidents = (tenant()?->hasModule('incidencias') ?? true)
            ? Incident::query()
                ->active()
                ->where('room_id', $room->id)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get()
            : collect();

        // Limpieza: la que está en curso o, si no hay, la última cerrada.
        $cleaning = (tenant()?->hasModule('limpieza') ?? false)
            ? RoomCleaning::query()
                ->with('housekeeper:id,name')
                ->where('room_id', $room->id)
                ->orderByDesc('started_at')
                ->first()
            : null;

        return Inertia::render('tenant/rooms/Show', [
            'room' => [
                'id' => $room->id,
                'number' => $room->number,
                'name' => $room->name,
                'description' => $room->description,
                'room_type' => $room->roomType->name,
                'price_from' => $room->roomType->priceFrom(),
                'zone' => $room->zone?->name,
                'zone_color' => $room->zone?->color,
                'status' => $room->status->getMorphClass(),
                'status_label' => $room->status->label(),
                'status_color' => $room->status->color(),
                'beds_label' => $room->bedsLabel(),
                'capacity' => $room->effectiveMaxOccupancy(),
                'size_m2' => $room->size_m2 !== null ? (float) $room->size_m2 : null,
                'view' => $room->view,
                'amenities' => $room->effectiveAmenities(),
                'smoking' => $room->smoking,
                'accessible' => $room->accessible,
                'price_modifier' => $room->price_modifier !== null ? (float) $room->price_modifier : null,
                'notes' => $room->notes,
                'maintenance_notes' => $room->maintenance_notes,
                // Contador de usos: vive en el plano y en el listado, y aquí
                // faltaba justo donde se consulta la habitación.
                'usage_count' => (int) $room->usage_count,
                'usage_limit' => $room->usage_limit,
                'usage_locked' => $room->usageLocked(),
            ],
            'current' => $activeStay ? [
                'id' => $activeStay->id,
                'guest_id' => $activeStay->guest_id,
                'guest_name' => $activeStay->guest?->full_name ?? $activeStay->guest_name ?? 'Anónimo',
                'num_people' => $activeStay->num_people,
                'rate_plan' => $activeStay->ratePlan?->name,
                'check_in_at' => $activeStay->check_in_at?->format('d/m/Y H:i'),
                'planned_end_at' => $activeStay->planned_end_at?->format('d/m/Y H:i'),
                'is_overdue' => $activeStay->planned_end_at?->isPast() ?? false,
                'vehicle_plate' => $activeStay->vehicle_plate,
                'amount' => round((float) $activeStay->amount, 2),
                'pending' => $folio['grand_pending'] ?? 0.0,
                'consumption_pending' => $folio['consumption_pending'] ?? 0.0,
            ] : null,
            'upcoming' => $upcoming->map(fn (Reservation $reservation) => [
                'id' => $reservation->id,
                'code' => $reservation->displayCode(),
                'guest_name' => $reservation->guest?->full_name ?? $reservation->guest_name ?? 'Anónimo',
                'status' => $reservation->status->value,
                'status_label' => $reservation->status->label(),
                'starts_at' => $reservation->starts_at->format('d/m/Y H:i'),
                'ends_at' => $reservation->ends_at->format('d/m/Y H:i'),
                'starts_today' => $reservation->starts_at->isToday(),
                'total_amount' => round((float) $reservation->total_amount, 2),
                'pending' => $reservation->pendingBalance(),
            ])->values(),
            'recent' => $recent->map(fn (Stay $stay) => [
                'id' => $stay->id,
                'guest_id' => $stay->guest_id,
                'guest_name' => $stay->guest?->full_name ?? $stay->guest_name ?? 'Anónimo',
                'check_in_at' => $stay->check_in_at?->format('d/m/Y H:i'),
                'check_out_at' => $stay->check_out_at?->format('d/m/Y H:i'),
                'nights' => $stay->check_in_at && $stay->check_out_at
                    ? max(1, (int) $stay->check_in_at->diffInDays($stay->check_out_at))
                    : null,
                'amount' => round((float) $stay->amount, 2),
            ])->values(),
            'incidents' => $incidents->map(fn (Incident $incident) => [
                'id' => $incident->id,
                'title' => $incident->title,
                'status_label' => $incident->statusLabel(),
                'priority' => $incident->priority,
                'priority_label' => $incident->priorityLabel(),
                'category_label' => $incident->categoryLabel(),
                'overdue' => $incident->isOverdue(),
                'age_hours' => $incident->ageHours(),
            ])->values(),
            'cleaning' => $cleaning ? [
                'open' => $cleaning->isOpen(),
                'kind_label' => $cleaning->kindLabel(),
                'housekeeper' => $cleaning->housekeeper?->name,
                'minutes' => $cleaning->isOpen() ? $cleaning->elapsedMinutes() : (int) $cleaning->minutes,
                'started_at' => $cleaning->started_at?->format('d/m/Y H:i'),
                'ended_at' => $cleaning->ended_at?->format('d/m/Y H:i'),
            ] : null,
            'usage' => $usageStats,
            'totals' => [
                'stays' => $totalStays,
                'revenue' => $totalRevenue,
                'last_stay_at' => $lastStayAt ? Carbon::parse($lastStayAt)->format('d/m/Y') : null,
            ],
            'canManage' => request()->user()->can('rooms.manage'),
            // Desde la ficha se puede abrir una reserva con este cuarto ya
            // elegido (?intent=reserve&room=), que es a lo que se entra.
            'canReserve' => request()->user()->can('reservations.manage'),
        ]);
    }
}
