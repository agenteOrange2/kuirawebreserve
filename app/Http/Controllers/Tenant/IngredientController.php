<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Inventory\RecordStockMovement;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IngredientController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Ingredient::orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'property_id' => ['required', 'exists:properties,id'],
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['sometimes', 'string', 'max:20'],
            'reorder_point' => ['nullable', 'numeric', 'min:0'],
            'cost' => ['sometimes', 'numeric', 'min:0'],
        ]);

        return response()->json(Ingredient::create($data), 201);
    }

    public function update(Request $request, Ingredient $ingredient): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'unit' => ['sometimes', 'string', 'max:20'],
            'reorder_point' => ['nullable', 'numeric', 'min:0'],
            'cost' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $ingredient->update($data);

        return response()->json($ingredient);
    }

    public function destroy(Ingredient $ingredient): JsonResponse
    {
        if ($ingredient->recipes()->exists() || $ingredient->stockMovements()->exists()) {
            return response()->json([
                'message' => 'El insumo tiene recetas o movimientos; no se puede eliminar.',
            ], 409);
        }

        $ingredient->delete();

        return response()->json(status: 204);
    }

    /**
     * Compra / ajuste / merma de insumo.
     */
    public function movement(Request $request, Ingredient $ingredient, RecordStockMovement $action): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['purchase', 'waste', 'adjustment'])],
            'qty' => ['required', 'numeric', 'not_in:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $action->handle(
                $ingredient,
                $data['type'],
                (float) $data['qty'],
                isset($data['unit_cost']) ? (float) $data['unit_cost'] : null,
                notes: $data['notes'] ?? null,
                user: $request->user(),
            );
        } catch (InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($ingredient->refresh());
    }
}
