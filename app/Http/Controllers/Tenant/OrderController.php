<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Inventory\CreateOrder;
use App\Actions\Inventory\VoidOrder;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class OrderController extends Controller
{
    /**
     * Catálogo de venta para el panel de consumos del plano. Es la misma
     * carga que arma la página del POS (Product::posPayload) para que no
     * diverjan precios entre la caseta y el mostrador.
     */
    public function catalog(): JsonResponse
    {
        $products = Product::query()
            ->with('media')
            ->where('active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return response()->json([
            'categories' => $products->pluck('category')->filter()->unique()->sort()->values(),
            'products' => $products->map(fn (Product $product) => $product->posPayload()),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->with(['lines.product:id,name', 'stay.room:id,number'])
            ->latest()
            ->take($request->integer('limit', 25))
            ->get()
            ->map(fn (Order $order) => $this->serialize($order));

        return response()->json($orders);
    }

    public function store(Request $request, CreateOrder $action): JsonResponse
    {
        $data = $request->validate([
            'property_id' => ['required', 'exists:properties,id'],
            'stay_id' => ['nullable', 'exists:stays,id'],
            // Con estancia, `false` cobra el consumo en el momento en vez de
            // cargarlo al folio. Campo aparte y explícito: honrar el
            // payment_method que el POS ya manda junto al stay_id cambiaría
            // sin avisar el flujo de los hoteles que hoy cargan a habitación.
            'charge_to_room' => ['nullable', 'boolean'],
            'payment_method' => ['nullable', Rule::in(Order::METHODS)],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:100'],
            'tip' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $order = $action->handle($data, $request->user());
        } catch (InsufficientStockException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(
            $this->serialize($order->load(['lines.product:id,name', 'stay.room:id,number'])),
            201,
        );
    }

    /**
     * Cancela una venta y devuelve su mercancía al inventario. Sale sola del
     * corte de caja y del folio: ambos solo suman ventas completadas.
     */
    public function void(Request $request, Order $order, VoidOrder $action): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $order = $action->handle($order, $request->user(), $data['reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(
            $this->serialize($order->load(['lines.product:id,name', 'stay.room:id,number'])),
        );
    }

    /** @return array<string, mixed> */
    protected function serialize(Order $order): array
    {
        return [
            'id' => $order->id,
            'subtotal' => (float) $order->subtotal,
            'discount' => (float) $order->discount,
            'discount_reason' => $order->discount_reason,
            'tip' => (float) $order->tip,
            'total' => (float) $order->total,
            'status' => $order->status,
            'is_void' => $order->isVoid(),
            'is_settled' => $order->isSettled(),
            'payment_method' => $order->payment_method,
            'payment_reference' => $order->payment_reference,
            'room' => $order->stay?->room?->number,
            'created_at' => $order->created_at->format('d/m/Y H:i'),
            'void_reason' => $order->void_reason,
            'summary' => $order->lines
                ->map(fn ($line) => ((float) $line->qty).'× '.$line->product?->name)
                ->implode(', '),
            'lines' => $order->lines->map(fn ($line) => [
                'product' => $line->product?->name,
                'qty' => (float) $line->qty,
                'unit_price' => (float) $line->unit_price,
                'total' => (float) $line->total,
            ]),
        ];
    }
}
