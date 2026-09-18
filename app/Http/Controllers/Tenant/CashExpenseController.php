<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\CashCut;
use App\Models\CashExpense;
use App\Models\Property;
use App\Models\Shift;
use App\Models\User;
use App\Services\CashCutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Salidas de efectivo de la caja. Se capturan durante el turno, no al
 * cortar: a las once de la noche nadie se acuerda de los $380 de gasolina
 * de la mañana, y ese hueco salía como faltante del encargado.
 */
class CashExpenseController extends Controller
{
    public function store(Request $request, CashCutService $service): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'scope' => ['required', Rule::in([CashCut::SCOPE_ROOMS, CashCut::SCOPE_POS])],
            'shift_id' => ['nullable', 'exists:shifts,id'],
            'category' => ['required', Rule::in(array_keys(CashExpense::CATEGORIES))],
            'concept' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'occurred_at' => ['nullable', 'date'],
            'receipt' => ['sometimes', 'file', 'mimes:jpeg,png,webp,pdf', 'max:6144'],
        ], [
            'concept.required' => 'Escribe en qué se gastó.',
            'amount.min' => 'El monto debe ser mayor que cero.',
            'receipt.max' => 'El comprobante puede pesar máximo 6 MB.',
        ]);

        // Mismos candados que el corte: el ámbito POS exige el módulo y el
        // de recepción exige ver reservas.
        if ($data['scope'] === CashCut::SCOPE_POS) {
            $tenant = tenant();
            abort_if($tenant !== null && ! $tenant->hasModule('pos'), 403, 'El módulo Punto de venta no está activo.');
        } else {
            abort_unless($request->user()->can('reservations.view'), 403);
        }

        $user = User::findOrFail($data['user_id']);
        $shift = ! empty($data['shift_id']) ? Shift::findOrFail($data['shift_id']) : null;

        if ($shift !== null && $shift->user_id !== $user->id) {
            return response()->json([
                'message' => 'Ese turno es de otra persona; el gasto va en la caja de quien lo tiene abierto.',
            ], 422);
        }

        $occurredAt = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();

        // Nada de gastos con fecha futura ni fuera de un turno ya cerrado:
        // moverían un arqueo que ya se firmó.
        if ($occurredAt->isFuture()) {
            $occurredAt = now();
        }

        if ($service->overlaps($user, $data['scope'], $occurredAt->copy()->subSecond(), $occurredAt)) {
            return response()->json([
                'message' => 'Ese momento ya está dentro de un corte cerrado; registra el gasto en el periodo actual.',
            ], 422);
        }

        $expense = CashExpense::create([
            'property_id' => Property::firstOrFail()->id,
            'user_id' => $user->id,
            'shift_id' => $shift?->id,
            'scope' => $data['scope'],
            'category' => $data['category'],
            'concept' => trim($data['concept']),
            'amount' => round((float) $data['amount'], 2),
            'occurred_at' => $occurredAt,
            'created_by' => $request->user()?->id,
        ]);

        if ($request->hasFile('receipt')) {
            $expense->addMedia($request->file('receipt'))->toMediaCollection('receipt');
        }

        return response()->json($this->payload($expense->fresh(['createdBy'])), 201);
    }

    public function destroy(CashExpense $cashExpense): JsonResponse
    {
        if ($cashExpense->isLocked()) {
            return response()->json([
                'message' => 'Este gasto ya quedó dentro de un corte cerrado; no se puede borrar.',
            ], 422);
        }

        $cashExpense->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(CashExpense $expense): array
    {
        return [
            'id' => $expense->id,
            'category' => $expense->category,
            'category_label' => $expense->categoryLabel(),
            'concept' => $expense->concept,
            'amount' => (float) $expense->amount,
            'at' => $expense->occurred_at->format('d/m H:i'),
            'by' => $expense->createdBy?->name,
            'receipt_url' => $expense->receiptUrl(),
            'locked' => $expense->isLocked(),
        ];
    }
}
