<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\CashCut;
use App\Models\Property;
use App\Models\User;
use App\Services\CashCutService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cortes de venta por encargado: vista con el corte en curso (previsualizado)
 * del usuario y periodo elegidos, y el historial de cortes guardados.
 */
class CashCutsPageController extends Controller
{
    public function __invoke(Request $request, CashCutService $service): Response
    {
        $property = Property::firstOrFail();

        $staff = User::query()->orderBy('name')->get(['id', 'name']);

        // Encargado seleccionado (por defecto el usuario actual).
        $selectedUser = $staff->firstWhere('id', $request->integer('user')) ?? $request->user();

        $from = $request->date('from')
            ? Carbon::parse($request->date('from'))
            : $service->defaultOpenedAt($selectedUser);
        $to = $request->date('to') ? Carbon::parse($request->date('to')) : Carbon::now();

        $preview = $service->compute($selectedUser, $from, $to);

        $cuts = CashCut::query()
            ->with(['user:id,name', 'createdBy:id,name'])
            ->latest('closed_at')
            ->take(20)
            ->get()
            ->map(fn (CashCut $c) => [
                'id' => $c->id,
                'user' => $c->user?->name,
                'opened_at' => $c->opened_at->format('d/m/Y H:i'),
                'closed_at' => $c->closed_at->format('d/m/Y H:i'),
                'orders_count' => $c->orders_count,
                'payments_count' => $c->payments_count,
                'grand_total' => (float) $c->grand_total,
                'cash_total' => (float) $c->cash_total,
                'card_total' => (float) $c->card_total,
                'transfer_total' => (float) $c->transfer_total,
                'expected_cash' => (float) $c->expected_cash,
                'counted_cash' => $c->counted_cash !== null ? (float) $c->counted_cash : null,
                'difference' => (float) $c->difference,
                'notes' => $c->notes,
                'by' => $c->createdBy?->name,
            ]);

        return Inertia::render('tenant/cashcuts/Index', [
            'property' => $property->only(['id', 'name']),
            'staff' => $staff,
            'filters' => [
                'user' => $selectedUser?->id,
                'from' => $from->format('Y-m-d\TH:i'),
                'to' => $to->format('Y-m-d\TH:i'),
            ],
            'selectedUser' => $selectedUser ? ['id' => $selectedUser->id, 'name' => $selectedUser->name] : null,
            'period' => [
                'from' => $from->format('d/m/Y H:i'),
                'to' => $to->format('d/m/Y H:i'),
            ],
            'preview' => $preview,
            'cuts' => $cuts,
            'canManage' => $request->user()->can('orders.manage'),
        ]);
    }
}
