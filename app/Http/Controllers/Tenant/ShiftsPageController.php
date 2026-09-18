<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\CashCut;
use App\Models\CashExpense;
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

        // Salidas de efectivo por turno, en una sola consulta: pedirlas
        // renglón por renglón serían 30 consultas en el historial.
        $expensesByShift = CashExpense::query()
            ->whereNotNull('shift_id')
            ->selectRaw('shift_id, sum(amount) as total')
            ->groupBy('shift_id')
            ->pluck('total', 'shift_id');

        $serialize = function (Shift $shift) use ($expensesByShift) {
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
                // Cortes LIGADOS al turno (shift_id) y sus ámbitos — los
                // cortes por reloj de antes del enlace no marcan el badge.
                'has_cut' => $shift->cashCuts->isNotEmpty(),
                'cut_scopes' => $shift->cashCuts->pluck('scope')->unique()->values(),
                // El turno y su corte, en la misma pantalla: antes /turnos
                // solo decía "ya tiene corte" y había que salir a /cortes
                // para saber si cuadró.
                'cuts' => $shift->cashCuts->map(fn (CashCut $c) => [
                    'id' => $c->id,
                    'scope' => $c->scope,
                    'scope_label' => $c->scopeLabel(),
                    'grand_total' => (float) $c->grand_total,
                    'expenses_total' => (float) $c->expenses_total,
                    'counted' => $c->counted_cash !== null,
                    'difference' => (float) $c->difference,
                    'closed_at' => $c->closed_at?->format('d/m H:i'),
                ])->values(),
                // Lo que salió del cajón en el turno, esté cortado o no.
                'expenses_total' => round((float) ($expensesByShift[$shift->id] ?? 0), 2),
                // Turno cerrado y sin corte: eso es lo que hay que perseguir.
                'cut_pending' => $shift->ended_at !== null && $shift->cashCuts->isEmpty(),
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

        // Asignaciones de la semana agrupadas por "area:id|fecha": el rol
        // ya no es solo del panel, también programa camaristas y técnicos.
        $assignments = ShiftAssignment::query()
            ->with('shiftType:id,name,starts_at,ends_at,color')
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->get();

        $schedule = $assignments->groupBy(fn (ShiftAssignment $a) => $a->slot().'|'.$a->date->toDateString())
            ->map(fn ($group) => $group->map(fn (ShiftAssignment $a) => [
                'id' => $a->id,
                'shift_type_id' => $a->shift_type_id,
                'name' => $a->shiftType?->name,
                'time' => $a->shiftType?->timeLabel(),
                'color' => $a->shiftType?->color ?? 'primary',
            ])->values());

        // Días de la semana en que cada usuario sí abrió turno (asistencia
        // con caja). Solo aplica al personal del panel: camaristas y
        // técnicos tienen rol, no fondo de caja.
        $worked = Shift::query()
            ->whereBetween('started_at', [$weekStart, $weekEnd->copy()->endOfDay()])
            ->get(['user_id', 'started_at'])
            ->map(fn (Shift $s) => 'user:'.$s->user_id.'|'.$s->started_at->toDateString())
            ->unique()
            ->values();

        // Programados hoy (para la pestaña Hoy).
        $today = Carbon::today()->toDateString();
        $scheduledToday = ShiftAssignment::query()
            ->with(['assignable', 'shiftType:id,name,starts_at,ends_at,color'])
            ->whereDate('date', $today)
            ->get()
            ->map(fn (ShiftAssignment $a) => [
                'id' => $a->id,
                'slot' => $a->slot(),
                'kind' => $a->kind(),
                // Solo el personal del panel abre turno con caja; para
                // camaristas y técnicos esto va nulo a propósito.
                'user_id' => $a->kind() === 'user' ? $a->assignable_id : null,
                'user' => $a->assigneeName(),
                'type' => $a->shiftType?->name,
                'time' => $a->shiftType?->timeLabel(),
                'color' => $a->shiftType?->color ?? 'primary',
            ]);

        return Inertia::render('tenant/shifts/Index', [
            'property' => $property->only(['id', 'name']),
            'staff' => User::query()->orderBy('name')->get(['id', 'name']),
            // Las tres áreas que se programan, en un solo listado con su
            // clave de área: el rol es de personas, tengan cuenta o no.
            'roster' => $this->rosterPeople(),
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
                ->with(['user:id,name', 'createdBy:id,name', 'cashCuts:id,shift_id,scope,grand_total,expenses_total,counted_cash,difference,closed_at'])
                ->orderBy('started_at')
                ->get()
                ->map($serialize),
            'history' => Shift::query()
                ->whereNotNull('ended_at')
                ->with(['user:id,name', 'createdBy:id,name', 'closedBy:id,name', 'cashCuts:id,shift_id,scope,grand_total,expenses_total,counted_cash,difference,closed_at'])
                ->latest('ended_at')
                ->take(30)
                ->get()
                ->map($serialize),
            'canSchedule' => $request->user()->can('shifts.manage'),
        ]);
    }

    /**
     * Todas las personas que pueden entrar al rol, agrupadas por área.
     * Camaristas y técnicos solo aparecen si su módulo está encendido: sin
     * el módulo esas tablas están vacías y el rol se ve igual que antes.
     *
     * @return array<int, array{slot: string, kind: string, kind_label: string, id: int, name: string}>
     */
    protected function rosterPeople(): array
    {
        $people = User::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $u) => [
                'slot' => 'user:'.$u->id,
                'kind' => 'user',
                'kind_label' => 'Panel',
                'id' => $u->id,
                'name' => $u->name,
            ])->all();

        foreach (\App\Models\Housekeeper::query()->where('active', true)->orderBy('name')->get(['id', 'name']) as $person) {
            $people[] = [
                'slot' => 'housekeeper:'.$person->id,
                'kind' => 'housekeeper',
                'kind_label' => 'Limpieza',
                'id' => $person->id,
                'name' => $person->name,
            ];
        }

        foreach (\App\Models\Technician::query()->where('active', true)->orderBy('name')->get(['id', 'name']) as $person) {
            $people[] = [
                'slot' => 'technician:'.$person->id,
                'kind' => 'technician',
                'kind_label' => 'Mantenimiento',
                'id' => $person->id,
                'name' => $person->name,
            ];
        }

        return $people;
    }
}
