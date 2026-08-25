<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Property;
use App\Models\Stay;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $property = Property::firstOrFail();

        $products = Product::query()
            ->with('media')
            ->where('active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return Inertia::render('tenant/pos/Index', [
            'property' => $property->only(['id', 'name']),
            'categories' => $products->pluck('category')->filter()->unique()->sort()->values(),
            'products' => $products->map(fn (Product $p) => $p->posPayload()),
            // Habitación preseleccionada al llegar desde el plano
            // (/pos?stay=N). Sin esto el cajero llegaba y volvía a elegirla
            // a mano, con el riesgo de cargarle el consumo a otra.
            'preselectStay' => $request->integer('stay') ?: null,
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
                    'total' => (float) $order->total,
                    'room' => $order->stay?->room?->number,
                    'created_at' => $order->created_at->format('d/m H:i'),
                    'is_void' => $order->isVoid(),
                    // Ya liquidada en el check-out: cancelarla ya no procede,
                    // eso se resuelve con un reembolso.
                    'is_settled' => $order->isSettled(),
                    'void_reason' => $order->void_reason,
                    'summary' => $order->lines
                        ->map(fn ($line) => ((float) $line->qty).'× '.$line->product?->name)
                        ->implode(', '),
                ]),
        ]);
    }
}
