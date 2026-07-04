<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Property;
use App\Models\Stay;
use Inertia\Inertia;
use Inertia\Response;

class PosPageController extends Controller
{
    public function __invoke(): Response
    {
        $property = Property::firstOrFail();

        $products = Product::query()
            ->where('active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return Inertia::render('tenant/pos/Index', [
            'property' => $property->only(['id', 'name']),
            'categories' => $products->pluck('category')->filter()->unique()->sort()->values(),
            'products' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'category' => $p->category,
                'type' => $p->type,
                'price' => $p->price,
                'track_stock' => $p->track_stock,
                'stock_qty' => (float) $p->stock_qty,
            ]),
            'activeStays' => Stay::query()
                ->active()
                ->with('room:id,number')
                ->get()
                ->map(fn (Stay $stay) => [
                    'id' => $stay->id,
                    'label' => 'Hab. '.$stay->room?->number.($stay->guest_name ? " · {$stay->guest_name}" : ''),
                ]),
            'recentOrders' => Order::query()
                ->with(['lines.product:id,name', 'stay.room:id,number'])
                ->latest()
                ->take(10)
                ->get()
                ->map(fn (Order $order) => [
                    'id' => $order->id,
                    'total' => $order->total,
                    'room' => $order->stay?->room?->number,
                    'created_at' => $order->created_at->format('d/m H:i'),
                    'summary' => $order->lines
                        ->map(fn ($line) => ((float) $line->qty).'× '.$line->product?->name)
                        ->implode(', '),
                ]),
        ]);
    }
}
