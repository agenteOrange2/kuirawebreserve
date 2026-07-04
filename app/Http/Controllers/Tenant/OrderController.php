<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Inventory\CreateOrder;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->with(['lines.product:id,name', 'stay.room:id,number'])
            ->latest()
            ->take($request->integer('limit', 25))
            ->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'total' => $order->total,
                'status' => $order->status,
                'room' => $order->stay?->room?->number,
                'created_at' => $order->created_at->format('d/m/Y H:i'),
                'lines' => $order->lines->map(fn ($line) => [
                    'product' => $line->product?->name,
                    'qty' => (float) $line->qty,
                    'total' => $line->total,
                ]),
            ]);

        return response()->json($orders);
    }

    public function store(Request $request, CreateOrder $action): JsonResponse
    {
        $data = $request->validate([
            'property_id' => ['required', 'exists:properties,id'],
            'stay_id' => ['nullable', 'exists:stays,id'],
            'payment_method' => ['nullable', Rule::in(Order::METHODS)],
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

        return response()->json($order->load('lines.product:id,name'), 201);
    }
}
