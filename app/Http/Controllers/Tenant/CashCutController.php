<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\CashCut;
use App\Models\Property;
use App\Models\User;
use App\Services\CashCutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CashCutController extends Controller
{
    /**
     * Guarda el corte: recalcula los agregados en el servidor (fuente de
     * verdad) y registra el arqueo de efectivo (esperado vs. contado).
     */
    public function store(Request $request, CashCutService $service): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
            'counted_cash' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $property = Property::firstOrFail();
        $user = User::findOrFail($data['user_id']);
        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);

        $agg = $service->compute($user, $from, $to);

        $countedCash = $data['counted_cash'] !== null ? (float) $data['counted_cash'] : null;
        $difference = $countedCash !== null ? round($countedCash - $agg['expected_cash'], 2) : 0;

        $cut = CashCut::create([
            'property_id' => $property->id,
            'user_id' => $user->id,
            'opened_at' => $from,
            'closed_at' => $to,
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
            'counted_cash' => $countedCash,
            'difference' => $difference,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json($cut, 201);
    }
}
