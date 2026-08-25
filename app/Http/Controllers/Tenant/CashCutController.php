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
     * Cómo va la caja AHORA: el turno abierto de quien pregunta y los
     * totales en curso de cada ámbito que puede ver, más los últimos cortes
     * guardados. Lo consume el panel de caja del plano.
     *
     * Es lo mismo que ya muestra /cortes a la misma gente; solo faltaba
     * poder pedirlo en JSON (hasta ahora viajaba como prop de Inertia).
     *
     * Los movimientos y los pendientes NO van por defecto: `pendingSnapshot`
     * recorre las estancias activas llamando `folio()` una por una, y este
     * endpoint se refresca solo cada minuto.
     */
    public function current(Request $request, CashCutService $service): JsonResponse
    {
        $user = $request->user();
        $scopes = $service->availableScopes($user);
        $shift = Shift::query()->open()->where('user_id', $user->id)->latest('started_at')->first();

        // Ámbito cuyo rastro se pide expandido (?detail=pos|rooms). No va por
        // defecto: pendingSnapshot() recorre las estancias activas llamando
        // folio() una por una, y esto se refresca cada minuto.
        $detail = $request->string('detail')->toString();

        return response()->json([
            'shift' => $shift === null ? null : [
                'id' => $shift->id,
                'started_at' => $shift->started_at->format('d/m H:i'),
                'opening_cash' => (float) $shift->opening_cash,
            ],
            'scopes' => array_map(function (string $scope) use ($service, $user, $detail) {
                $context = $service->openContext($user, $scope);
                $totals = $service->compute($user, $context['from'], $context['to'], $context['shift'], $scope);

                return [
                    'key' => $scope,
                    'label' => CashCut::labelForScope($scope),
                    'from' => $context['from']->format('d/m H:i'),
                    'to' => $context['to']->format('d/m H:i'),
                    // En ISO además del formato de pantalla: es lo que hay que
                    // mandar de vuelta para cerrar la caja de este periodo.
                    'from_iso' => $context['from']->toIso8601String(),
                    'to_iso' => $context['to']->toIso8601String(),
                    'shift_id' => $context['shift']?->id,
                    'orders_count' => $totals['orders_count'],
                    'payments_count' => $totals['payments_count'],
                    'cash_total' => $totals['cash_total'],
                    'card_total' => $totals['card_total'],
                    'transfer_total' => $totals['transfer_total'],
                    'grand_total' => $totals['grand_total'],
                    'expected_cash' => $totals['expected_cash'],
                    'movements' => $detail === $scope
                        ? $service->movements($user, $context['from'], $context['to'], $context['shift'], $scope)
                        : null,
                    'pending' => $detail === $scope
                        ? $service->pendingSnapshot($user, $context['from'], $context['to'], $context['shift'], $scope)
                        : null,
                ];
            }, $scopes),
            'recent_cuts' => CashCut::query()
                ->where('user_id', $user->id)
                ->latest('closed_at')
                ->take(5)
                ->get()
                ->map(fn (CashCut $cut) => [
                    'id' => $cut->id,
                    'scope_label' => $cut->scopeLabel(),
                    'closed_at' => $cut->closed_at?->format('d/m H:i'),
                    'grand_total' => (float) $cut->grand_total,
                    'difference' => $cut->counted_cash === null
                        ? null
                        : round((float) $cut->counted_cash - (float) $cut->expected_cash, 2),
                ]),
        ]);
    }

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
