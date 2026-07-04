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
     * Cierra el turno. Con auto_cut genera de una vez el corte del periodo
     * exacto del turno (sin arqueo); si no, el arqueo manual se hace en
     * /cortes con el periodo prellenado.
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
            $agg = $cuts->compute($shift->user, $shift->started_at, $shift->ended_at);

            CashCut::create([
                'property_id' => $shift->property_id,
                'user_id' => $shift->user_id,
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
                'counted_cash' => null, // automático: sin arqueo físico
                'difference' => 0,
                'notes' => 'Corte automático al cerrar turno.',
                'created_by' => $request->user()?->id,
            ]);
        }

        return response()->json($shift);
    }
}
