<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\CashCut;
use App\Models\Property;
use App\Models\Shift;
use App\Models\User;
use App\Services\CashCutService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cortes de caja por encargado y ÁMBITO: Recepción (cobros de reservas y
 * fianzas — en todos los hoteles) y Punto de venta (solo con el módulo
 * pos). Muestra el corte en curso del ámbito/usuario/periodo elegidos —
 * por turno o por rango libre — y el historial de cortes guardados.
 */
class CashCutsPageController extends Controller
{
    public function __invoke(Request $request, CashCutService $service): Response
    {
        $property = Property::firstOrFail();

        $staff = User::query()->orderBy('name')->get(['id', 'name']);

        // Encargado seleccionado (por defecto el usuario actual).
        $selectedUser = $staff->firstWhere('id', $request->integer('user')) ?? $request->user();

        // Ámbitos disponibles: Recepción exige ver reservas (cocina queda
        // fuera) y Punto de venta exige el módulo. Sin contexto de tenant
        // (tests) el módulo no aplica.
        $tenant = tenant();
        $hasPos = $tenant === null || $tenant->hasModule('pos');
        $canRooms = $request->user()->can('reservations.view');

        $available = array_values(array_filter([
            $canRooms ? CashCut::SCOPE_ROOMS : null,
            $hasPos ? CashCut::SCOPE_POS : null,
        ]));

        $scope = $request->string('scope')->toString();
        if (! in_array($scope, $available, true)) {
            $scope = $available[0] ?? null;
        }

        // Turnos elegibles del encargado: el abierto y los cerrados
        // recientes que aún no tienen corte de este ámbito (un combinado
        // viejo también cubre).
        $shifts = collect();
        $shift = null;

        if ($scope !== null && $selectedUser !== null) {
            $shifts = Shift::query()
                ->where('user_id', $selectedUser->id)
                ->where(fn ($q) => $q
                    ->whereNull('ended_at')
                    ->orWhere('ended_at', '>=', Carbon::now()->subDays(14)))
                ->whereDoesntHave('cashCuts', fn ($q) => $q->whereIn('scope', [$scope, CashCut::SCOPE_ALL]))
                ->latest('started_at')
                ->take(10)
                ->get();

            $shift = $shifts->firstWhere('id', $request->integer('shift')) ?: null;
        }

        if ($shift !== null) {
            // Corte por turno: el periodo es el del turno, no el reloj.
            $from = $shift->started_at;
            $to = $shift->ended_at ?? Carbon::now();
        } else {
            $from = $request->date('from')
                ? Carbon::parse($request->date('from'))
                : ($scope !== null ? Carbon::parse($service->defaultOpenedAt($selectedUser, $scope)) : Carbon::today());
            $to = $request->date('to') ? Carbon::parse($request->date('to')) : Carbon::now();
        }

        $preview = $scope !== null && $selectedUser !== null
            ? $service->compute($selectedUser, $from, $to, $shift, $scope)
            : null;

        if ($preview !== null) {
            // Rastro transacción por transacción y pagos pendientes del
            // momento: el corte deja de ser solo totales.
            $preview['movements'] = $service->movements($selectedUser, $from, $to, $shift, $scope);
            $preview['pending'] = $service->pendingSnapshot($selectedUser, $from, $to, $shift, $scope);
        }

        $cuts = CashCut::query()
            ->with(['user:id,name', 'createdBy:id,name'])
            ->latest('closed_at')
            ->take(20)
            ->get()
            ->map(fn (CashCut $c) => [
                'id' => $c->id,
                'user' => $c->user?->name,
                'scope' => $c->scope,
                'scope_label' => $c->scopeLabel(),
                'shift_id' => $c->shift_id,
                'opened_at' => $c->opened_at->format('d/m/Y H:i'),
                'closed_at' => $c->closed_at->format('d/m/Y H:i'),
                'orders_count' => $c->orders_count,
                'payments_count' => $c->payments_count,
                'grand_total' => (float) $c->grand_total,
                'cash_total' => (float) $c->cash_total,
                'card_total' => (float) $c->card_total,
                'transfer_total' => (float) $c->transfer_total,
                'expected_cash' => (float) $c->expected_cash,
                'opening_cash' => (float) $c->opening_cash,
                'counted_cash' => $c->counted_cash !== null ? (float) $c->counted_cash : null,
                'difference' => (float) $c->difference,
                'pending_count' => (int) $c->pending_count,
                'pending_total' => (float) $c->pending_total,
                'pending_items' => $c->pending_items ?? [],
                'notes' => $c->notes,
                'by' => $c->createdBy?->name,
            ]);

        $scopeLabels = [
            CashCut::SCOPE_ROOMS => 'Recepción',
            CashCut::SCOPE_POS => 'Punto de venta',
        ];

        return Inertia::render('tenant/cashcuts/Index', [
            'property' => $property->only(['id', 'name']),
            'staff' => $staff,
            'scopes' => collect($available)
                ->map(fn (string $key) => ['key' => $key, 'label' => $scopeLabels[$key]])
                ->values(),
            'filters' => [
                'user' => $selectedUser?->id,
                'scope' => $scope,
                'shift' => $shift?->id,
                'from' => $from->format('Y-m-d\TH:i'),
                'to' => $to->format('Y-m-d\TH:i'),
            ],
            'shifts' => $shifts->map(fn (Shift $s) => [
                'id' => $s->id,
                'is_open' => $s->isOpen(),
                'label' => $s->isOpen()
                    ? 'En curso desde '.$s->started_at->format('d/m H:i')
                    : $s->started_at->format('d/m H:i').' → '.$s->ended_at->format('d/m H:i'),
            ])->values(),
            'selectedUser' => $selectedUser ? ['id' => $selectedUser->id, 'name' => $selectedUser->name] : null,
            'period' => [
                'from' => $from->format('d/m/Y H:i'),
                'to' => $to->format('d/m/Y H:i'),
            ],
            'preview' => $preview,
            'cuts' => $cuts,
            'canManage' => $request->user()->can('orders.manage'),
        ]);
    }

    /**
     * Movimientos de un corte guardado, reconstruidos de los registros
     * vivos con el periodo/turno/ámbito que el corte congeló. Los pide el
     * modal de detalle al abrirse (cargarlos para los 20 cortes de la
     * página sería tirar el trabajo).
     */
    public function movements(CashCut $cashCut, CashCutService $service)
    {
        $cashCut->load(['user', 'shift']);

        return response()->json([
            'movements' => $cashCut->user === null ? [] : $service->movements(
                $cashCut->user,
                $cashCut->opened_at,
                $cashCut->closed_at,
                $cashCut->shift,
                $cashCut->scope,
            ),
        ]);
    }

    /**
     * PDF de un corte guardado, listo para imprimir y firmar (entregó /
     * recibió) — el respaldo en papel del arqueo de su ámbito.
     */
    public function pdf(CashCut $cashCut, CashCutService $service)
    {
        $cashCut->load(['user', 'createdBy:id,name', 'shift']);

        $data = [
            'property' => Property::query()->firstOrFail()->only(['id', 'name']),
            'cut' => $cashCut,
            'movements' => $cashCut->user === null ? [] : $service->movements(
                $cashCut->user,
                $cashCut->opened_at,
                $cashCut->closed_at,
                $cashCut->shift,
                $cashCut->scope,
            ),
            'generatedAt' => now()->format('d/m/Y H:i'),
        ];

        $slug = Str::slug($cashCut->user?->name ?? 'corte');
        $scopeSlug = Str::slug($cashCut->scopeLabel());

        return Pdf::loadView('pdf.cash-cut', $data)
            ->setPaper('letter')
            ->download("corte-{$cashCut->id}-{$scopeSlug}-{$slug}-{$cashCut->closed_at->format('Y-m-d')}.pdf");
    }
}
