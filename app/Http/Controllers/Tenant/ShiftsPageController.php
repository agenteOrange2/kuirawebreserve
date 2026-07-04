<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\CashCut;
use App\Models\Property;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Turnos: quién está a cargo ahora (turnos abiertos), el rol semanal
 * (a quién le toca qué día y en qué horario) y el historial con acceso
 * directo al corte de cada turno.
 */
class ShiftsPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $property = Property::firstOrFail();

        $serialize = function (Shift $shift) {
            // ¿Ya existe un corte que cubre el cierre de este turno?
            $hasCut = $shift->ended_at
                ? CashCut::query()
                    ->where('user_id', $shift->user_id)
                    ->where('closed_at', '>=', $shift->ended_at)
                    ->exists()
                : false;

            return [
                'id' => $shift->id,
                'user_id' => $shift->user_id,
                'user' => $shift->user?->name,
                'started_at' => $shift->started_at->format('d/m/Y H:i'),
                'ended_at' => $shift->ended_at?->format('d/m/Y H:i'),
                // Para prellenar el corte con el periodo exacto del turno.
                'started_at_input' => $shift->started_at->format('Y-m-d\TH:i'),
                'ended_at_input' => $shift->ended_at?->format('Y-m-d\TH:i'),
                'minutes' => (int) $shift->started_at->diffInMinutes($shift->ended_at ?? now()),
                'opening_cash' => (float) $shift->opening_cash,
                'notes' => $shift->notes,
                'opened_by' => $shift->createdBy?->name,
                'closed_by' => $shift->closedBy?->name,
                'has_cut' => $hasCut,
            ];
        };

        // ── Rol semanal ──
        $weekStart = ($request->date('week') ? Carbon::parse($request->date('week')) : Carbon::today())->startOfWeek();
        $weekEnd = $weekStart->copy()->endOfWeek();

        $days = collect(range(0, 6))->map(fn (int $offset) => [
            'date' => $weekStart->copy()->addDays($offset)->toDateString(),
            'label' => ucfirst($weekStart->copy()->addDays($offset)->locale('es')->isoFormat('dd DD')),
            'is_today' => $weekStart->copy()->addDays($offset)->isToday(),
        ]);

        // Asignaciones de la semana agrupadas por "user|fecha".
        $assignments = ShiftAssignment::query()
            ->with('shiftType:id,name,starts_at,ends_at,color')
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->get();

        $schedule = $assignments->groupBy(fn (ShiftAssignment $a) => $a->user_id.'|'.$a->date->toDateString())
            ->map(fn ($group) => $group->map(fn (ShiftAssignment $a) => [
                'id' => $a->id,
                'shift_type_id' => $a->shift_type_id,
                'name' => $a->shiftType?->name,
                'time' => $a->shiftType?->timeLabel(),
                'color' => $a->shiftType?->color ?? 'primary',
            ])->values());

        // Días de la semana en que cada usuario sí abrió turno (asistencia).
        $worked = Shift::query()
            ->whereBetween('started_at', [$weekStart, $weekEnd->copy()->endOfDay()])
            ->get(['user_id', 'started_at'])
            ->map(fn (Shift $s) => $s->user_id.'|'.$s->started_at->toDateString())
            ->unique()
            ->values();

        // Programados hoy (para la pestaña Hoy).
        $today = Carbon::today()->toDateString();
        $scheduledToday = ShiftAssignment::query()
            ->with(['user:id,name', 'shiftType:id,name,starts_at,ends_at,color'])
            ->whereDate('date', $today)
            ->get()
            ->map(fn (ShiftAssignment $a) => [
                'id' => $a->id,
                'user_id' => $a->user_id,
                'user' => $a->user?->name,
                'type' => $a->shiftType?->name,
                'time' => $a->shiftType?->timeLabel(),
                'color' => $a->shiftType?->color ?? 'primary',
            ]);

        return Inertia::render('tenant/shifts/Index', [
            'property' => $property->only(['id', 'name']),
            'staff' => User::query()->orderBy('name')->get(['id', 'name']),
            'shiftTypes' => ShiftType::query()->orderBy('starts_at')->get()->map(fn (ShiftType $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'starts_at' => substr((string) $t->starts_at, 0, 5),
                'ends_at' => substr((string) $t->ends_at, 0, 5),
                'time' => $t->timeLabel(),
                'color' => $t->color,
                'active' => $t->active,
            ]),
            'week' => [
                'start' => $weekStart->toDateString(),
                'label' => ucfirst($weekStart->locale('es')->isoFormat('DD MMM')).' – '.$weekEnd->locale('es')->isoFormat('DD MMM YYYY'),
                'prev' => $weekStart->copy()->subWeek()->toDateString(),
                'next' => $weekStart->copy()->addWeek()->toDateString(),
                'is_current' => $weekStart->isSameWeek(Carbon::today()),
            ],
            'days' => $days,
            'schedule' => $schedule,
            'worked' => $worked,
            'scheduledToday' => $scheduledToday,
            'activeShifts' => Shift::query()
                ->open()
                ->with(['user:id,name', 'createdBy:id,name'])
                ->orderBy('started_at')
                ->get()
                ->map($serialize),
            'history' => Shift::query()
                ->whereNotNull('ended_at')
                ->with(['user:id,name', 'createdBy:id,name', 'closedBy:id,name'])
                ->latest('ended_at')
                ->take(30)
                ->get()
                ->map($serialize),
            'canSchedule' => $request->user()->can('shifts.manage'),
        ]);
    }
}
