<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Historial de ventas del POS. La pantalla de venta solo muestra las
 * últimas diez: aquí vive todo, con filtros por fecha, encargado y método,
 * y los totales del periodo filtrado.
 */
class SalesHistoryPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'user' => ['nullable', 'integer'],
            'method' => ['nullable', 'in:cash,card,transfer,room'],
            'status' => ['nullable', 'in:completed,void'],
        ]);

        $property = Property::firstOrFail();

        // Por defecto la semana: abrir esta pantalla no debe traer el
        // historial completo de años.
        $from = Carbon::parse($request->query('from', Carbon::today()->subDays(6)->toDateString()))->startOfDay();
        $to = Carbon::parse($request->query('to', Carbon::today()->toDateString()))->endOfDay();

        $query = Order::query()
            ->with(['lines.product:id,name', 'stay.room:id,number', 'createdBy:id,name'])
            ->where('property_id', $property->id)
            ->whereBetween('created_at', [$from, $to])
            ->when($request->integer('user'), fn ($q, $id) => $q->where('created_by', $id))
            ->when($request->string('method')->toString(), fn ($q, $m) => $q->where('payment_method', $m))
            ->when(
                $request->string('status')->toString(),
                fn ($q, $s) => $q->where('status', $s),
            );

        // Los totales se calculan sobre TODO el filtro, no sobre la página
        // a la vista: si no, "vendiste $400" sería solo lo que se alcanza a
        // ver y no lo del periodo.
        $totals = (clone $query)
            ->where('status', Order::STATUS_COMPLETED)
            ->selectRaw('COUNT(*) AS orders_count, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(tip), 0) AS tip, COALESCE(SUM(discount), 0) AS discount')
            ->first();

        $orders = $query->latest('id')->paginate(30)->withQueryString();

        return Inertia::render('tenant/pos/History', [
            'property' => $property->only(['id', 'name']),
            'orders' => $orders->through(fn (Order $order) => [
                'id' => $order->id,
                'created_at' => $order->created_at->format('d/m/Y H:i'),
                'total' => (float) $order->total,
                'subtotal' => (float) $order->subtotal,
                'discount' => (float) $order->discount,
                'tip' => (float) $order->tip,
                'payment_method' => $order->payment_method,
                'payment_reference' => $order->payment_reference,
                'room' => $order->stay?->room?->number,
                'by' => $order->createdBy?->name,
                'is_void' => $order->isVoid(),
                'void_reason' => $order->void_reason,
                'summary' => $order->lines
                    ->map(fn ($line) => ((float) $line->qty).'× '.$line->product?->name)
                    ->implode(', '),
            ]),
            'totals' => [
                'orders_count' => (int) ($totals->orders_count ?? 0),
                'total' => round((float) ($totals->total ?? 0), 2),
                'tip' => round((float) ($totals->tip ?? 0), 2),
                'discount' => round((float) ($totals->discount ?? 0), 2),
            ],
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'user' => $request->integer('user') ?: null,
                'method' => $request->string('method')->toString() ?: null,
                'status' => $request->string('status')->toString() ?: null,
            ],
            'staff' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
