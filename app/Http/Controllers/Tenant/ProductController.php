<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Inventory\RecordStockMovement;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            Product::query()
                ->with('recipeItems.ingredient:id,name,unit')
                ->when($request->boolean('active_only'), fn ($q) => $q->where('active', true))
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $product = DB::transaction(function () use ($data) {
            $product = Product::create($data);
            $this->syncRecipe($product, $data['recipe'] ?? []);

            return $product;
        });

        return response()->json($product->load('recipeItems.ingredient:id,name,unit'), 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $this->validated($request, $product);

        DB::transaction(function () use ($product, $data) {
            $product->update($data);
            if (array_key_exists('recipe', $data)) {
                $this->syncRecipe($product, $data['recipe'] ?? []);
            }
        });

        return response()->json($product->refresh()->load('recipeItems.ingredient:id,name,unit'));
    }

    public function destroy(Product $product): JsonResponse
    {
        if ($product->stockMovements()->exists() || $product->orderLines()->exists()) {
            $product->update(['active' => false]);

            return response()->json($product);
        }

        DB::transaction(function () use ($product) {
            $product->recipeItems()->delete();
            $product->delete();
        });

        return response()->json(status: 204);
    }

    /**
     * Compra / ajuste / merma de un producto simple con stock propio.
     */
    public function movement(Request $request, Product $product, RecordStockMovement $action): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['purchase', 'waste', 'adjustment'])],
            'qty' => ['required', 'numeric', 'not_in:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $action->handle(
                $product,
                $data['type'],
                (float) $data['qty'],
                isset($data['unit_cost']) ? (float) $data['unit_cost'] : null,
                notes: $data['notes'] ?? null,
                user: $request->user(),
            );
        } catch (InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($product->refresh());
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'property_id' => [$product ? 'sometimes' : 'required', 'exists:properties,id'],
            'name' => [$product ? 'sometimes' : 'required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:50'],
            'sku' => ['nullable', 'string', 'max:50'],
            'type' => [$product ? 'sometimes' : 'required', Rule::in([Product::TYPE_SIMPLE, Product::TYPE_COMPOSITE])],
            'unit' => ['sometimes', 'string', 'max:20'],
            'price' => [$product ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'cost' => ['sometimes', 'numeric', 'min:0'],
            'track_stock' => ['sometimes', 'boolean'],
            'reorder_point' => ['nullable', 'numeric', 'min:0'],
            'active' => ['sometimes', 'boolean'],
            // Curación del wizard público (/ajustes/wizard): qué productos
            // se ofrecen SIN staff de por medio al huésped en /reservar.
            'available_in_wizard' => ['sometimes', 'boolean'],
            'recipe' => ['sometimes', 'array'],
            'recipe.*.ingredient_id' => ['required_with:recipe', 'exists:ingredients,id'],
            'recipe.*.quantity' => ['required_with:recipe', 'numeric', 'gt:0'],
        ]);
    }

    /**
     * @param  array<int, array{ingredient_id: int, quantity: float}>  $recipe
     */
    protected function syncRecipe(Product $product, array $recipe): void
    {
        $product->recipeItems()->delete();

        if ($product->isComposite()) {
            foreach ($recipe as $item) {
                $product->recipeItems()->create($item);
            }
        }
    }
}
