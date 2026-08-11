<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\CashCut;
use App\Models\Property;
use App\Models\Shift;
use App\Services\CashCutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    /**
     * Abre un turno para un encargado (uno abierto a la vez por persona).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'opening_cash' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $alreadyOpen = Shift::query()->open()->where('user_id', $data['user_id'])->exists();
        if ($alreadyOpen) {
            return response()->json([
                'message' => 'Esa persona ya tiene un turno abierto; ciérralo antes de abrir otro.',
            ], 422);
        }

        $shift = Shift::create([
            'property_id' => Property::firstOrFail()->id,
            'user_id' => $data['user_id'],
            'started_at' => now(),
            'opening_cash' => $data['opening_cash'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json($shift, 201);
    }

    /**
     * Cierra el turno. Con auto_cut genera de una vez los cortes POR
     * ÁMBITO del periodo exacto del turno (sin arqueo): el de recepción
     * si hubo cobros o fianzas, y el de punto de venta si hubo ventas y
     * el hotel tiene el módulo. Si no, el arqueo manual se hace en
     * /cortes con el turno prellenado.
     */
    public function close(Request $request, Shift $shift, CashCutService $cuts): JsonResponse
    {
        if (! $shift->isOpen()) {
            return response()->json(['message' => 'Ese turno ya está cerrado.'], 422);
        }

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:255'],
            'auto_cut' => ['sometimes', 'boolean'],
        ]);

        $shift->update([
            'ended_at' => now(),
            'notes' => $data['notes'] ?? $shift->notes,
            'closed_by' => $request->user()?->id,
        ]);

        $shift->refresh();

        if ($request->boolean('auto_cut')) {
            // Sin contexto de tenant (tests) el módulo no aplica.
            $tenant = tenant();
            $scopes = array_values(array_filter([
                CashCut::SCOPE_ROOMS,
                $tenant === null || $tenant->hasModule('pos') ? CashCut::SCOPE_POS : null,
            ]));

            foreach ($scopes as $scope) {
                // Si alguien ya hizo el arqueo manual de ese ámbito antes
                // de cerrar el turno, no se duplica: se salta en silencio.
                if ($cuts->overlaps($shift->user, $scope, $shift->started_at, $shift->ended_at)) {
                    continue;
                }

                $agg = $cuts->compute($shift->user, $shift->started_at, $shift->ended_at, $shift, $scope);

                // Solo ámbitos con movimiento: una devolución de fianza
                // sola también cuenta (afecta el arqueo de recepción).
                $hasActivity = $scope === CashCut::SCOPE_POS
                    ? $agg['orders_count'] > 0
                    : $agg['payments_count'] > 0 || $agg['guarantees_count'] > 0 || $agg['guarantees_cash_out'] > 0;

                if (! $hasActivity) {
                    continue;
                }

                // Se congela también la foto de pendientes: el corte
                // automático es el relevo de turno por excelencia.
                $pending = $cuts->pendingSnapshot($shift->user, $shift->started_at, $shift->ended_at, $shift, $scope);

                CashCut::create([
                    'property_id' => $shift->property_id,
                    'user_id' => $shift->user_id,
                    'shift_id' => $shift->id,
                    'scope' => $scope,
                    'opened_at' => $shift->started_at,
                    'closed_at' => $shift->ended_at,
                    'orders_count' => $agg['orders_count'],
                    'orders_total' => $agg['orders_total'],
                    'orders_cost' => $agg['orders_cost'],
                    'payments_count' => $agg['payments_count'],
                    'payments_total' => $agg['payments_total'],
                    'cash_total' => $agg['cash_total'],
                    'card_total' => $agg['card_total'],
                    'transfer_total' => $agg['transfer_total'],
                    'grand_total' => $agg['grand_total'],
                    'expected_cash' => $agg['expected_cash'],
                    'opening_cash' => $agg['opening_cash'],
                    'counted_cash' => null, // automático: sin arqueo físico
                    'difference' => 0,
                    'pending_count' => $pending['count'],
                    'pending_total' => $pending['total'],
                    'pending_items' => $pending['items'],
                    'notes' => 'Corte automático al cerrar turno.',
                    'created_by' => $request->user()?->id,
                ]);
            }
        }

        return response()->json($shift);
    }
}
