<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\CashCut;
use App\Models\Property;
use App\Models\Shift;
use App\Models\User;
use App\Services\CashCutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class CashCutController extends Controller
{
    /**
     * Guarda el corte de UN ámbito (recepción o punto de venta): recalcula
     * los agregados en el servidor (fuente de verdad) y registra el arqueo
     * de efectivo (esperado vs. contado). Los cortes nuevos siempre traen
     * ámbito — el formato combinado 'all' quedó solo en los históricos.
     */
    public function store(Request $request, CashCutService $service): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'scope' => ['required', Rule::in([CashCut::SCOPE_ROOMS, CashCut::SCOPE_POS])],
            // Corte de un turno concreto: el periodo deja de adivinarse.
            'shift_id' => ['nullable', 'exists:shifts,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
            'counted_cash' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // Mismos candados que la página: el ámbito POS exige el módulo y el
        // de recepción exige ver reservas (cocina solo corta su venta).
        // Sin contexto de tenant (tests) el módulo no aplica.
        if ($data['scope'] === CashCut::SCOPE_POS) {
            $tenant = tenant();
            abort_if($tenant !== null && ! $tenant->hasModule('pos'), 403, 'El módulo Punto de venta no está activo.');
        } else {
            abort_unless($request->user()->can('reservations.view'), 403);
        }

        $property = Property::firstOrFail();
        $user = User::findOrFail($data['user_id']);
        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);
        $shift = ! empty($data['shift_id']) ? Shift::findOrFail($data['shift_id']) : null;

        if ($shift && $shift->user_id !== $user->id) {
            return response()->json([
                'message' => 'Ese turno es de otra persona; el corte debe ser del mismo encargado.',
            ], 422);
        }

        // Anti-doble-conteo: el mismo periodo del mismo encargado y ámbito
        // no se corta dos veces (un combinado viejo también cuenta).
        if ($service->overlaps($user, $data['scope'], $from, $to)) {
            return response()->json([
                'message' => 'Ese periodo ya está cubierto por otro corte de este ámbito para este encargado.',
            ], 422);
        }

        $agg = $service->compute($user, $from, $to, $shift, $data['scope']);

        // Foto de los pagos pendientes al instante del corte: el estado
        // vivo (folios, vencidos) cambia después, así que se congela aquí.
        $pending = $service->pendingSnapshot($user, $from, $to, $shift, $data['scope']);

        $countedCash = isset($data['counted_cash']) ? (float) $data['counted_cash'] : null;
        $difference = $countedCash !== null ? round($countedCash - $agg['expected_cash'], 2) : 0;

        $cut = CashCut::create([
            'property_id' => $property->id,
            'user_id' => $user->id,
            'shift_id' => $shift?->id,
            'scope' => $data['scope'],
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
            'opening_cash' => $agg['opening_cash'],
            'counted_cash' => $countedCash,
            'difference' => $difference,
            'pending_count' => $pending['count'],
            'pending_total' => $pending['total'],
            'pending_items' => $pending['items'],
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json($cut, 201);
    }
}
