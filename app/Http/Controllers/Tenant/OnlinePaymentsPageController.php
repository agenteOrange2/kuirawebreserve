<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Property;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reporte "Cobros en línea" (spec-pagos §9.4): el dinero del circuito de
 * solicitudes — pasarelas y transferencias verificadas — para conciliar
 * contra el dashboard del proveedor. NO toca los cortes de caja: nada de
 * esto pasó por las manos de un encargado.
 */
class OnlinePaymentsPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'source' => ['nullable', 'in:all,stripe,mercadopago,transfer'],
        ]);

        $from = ! empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : now()->startOfMonth();
        $to = ! empty($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now()->endOfDay();
        $source = $filters['source'] ?? 'all';

        $payments = Payment::query()
            ->whereNotNull('payment_request_id') // solo el circuito de solicitudes
            ->whereBetween('paid_at', [$from, $to])
            ->when($source === 'transfer', fn ($q) => $q->where('method', 'transfer'))
            ->when(in_array($source, ['stripe', 'mercadopago'], true), fn ($q) => $q->where('gateway', $source))
            ->with(['reservation:id,code,guest_name,created_at', 'receivedBy:id,name'])
            ->orderByDesc('paid_at')
            ->get();

        $requests = PaymentRequest::query()
            ->whereIn('id', $payments->pluck('payment_request_id')->filter())
            ->get()
            ->keyBy('id');

        $rows = $payments->map(function (Payment $payment) use ($requests) {
            $request = $requests->get($payment->payment_request_id);

            return [
                'id' => $payment->id,
                'paid_at' => $payment->paid_at->format('d/m/Y H:i'),
                'reservation_code' => $payment->reservation?->displayCode(),
                'guest_name' => $payment->reservation?->guest_name ?? 'Huésped',
                'concept' => $request?->conceptLabel() ?? 'Cobro',
                'source' => $payment->gateway
                    ? (\App\Models\Central\PaymentGatewayLink::PROVIDERS[$payment->gateway] ?? $payment->gateway)
                    : 'Transferencia',
                'mode' => $request?->mode,
                'reference' => $payment->gateway_ref ?? $payment->reference,
                'verified_by' => $payment->receivedBy?->name,
                'amount' => (float) $payment->amount,
                'amount_label' => '$'.number_format((float) $payment->amount, 2),
                'fee_label' => $payment->fee_amount !== null ? '$'.number_format((float) $payment->fee_amount, 2) : null,
            ];
        })->values();

        $total = $payments->sum('amount');
        $fees = $payments->sum('fee_amount');

        // Embudo de cobro del periodo (F4): solicitudes emitidas → pagadas.
        $requests = PaymentRequest::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($source === 'transfer', fn ($q) => $q->where('method', PaymentRequest::METHOD_TRANSFER))
            ->when(in_array($source, ['stripe', 'mercadopago', 'paypal'], true), fn ($q) => $q->where('provider', $source))
            ->get();

        $issued = $requests->count();
        $requestsPaid = $requests->where('status', PaymentRequest::STATUS_PAID)->count();

        // Reembolsos del periodo (gateway null = manual/transferencia).
        $refunded = \App\Models\Refund::query()
            ->where('status', \App\Models\Refund::STATUS_COMPLETED)
            ->whereBetween('refunded_at', [$from, $to])
            ->when($source === 'transfer', fn ($q) => $q->whereNull('gateway'))
            ->when(in_array($source, ['stripe', 'mercadopago', 'paypal'], true), fn ($q) => $q->where('gateway', $source))
            ->sum('amount');

        return Inertia::render('tenant/reports/OnlinePayments', [
            'property' => Property::firstOrFail()->only(['id', 'name']),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'source' => $source,
            ],
            'stats' => [
                'count' => $payments->count(),
                'total_label' => '$'.number_format($total, 2),
                'fees_label' => '$'.number_format($fees, 2),
                'net_label' => '$'.number_format($total - $fees, 2),
            ],
            'funnel' => [
                'issued' => $issued,
                'paid' => $requestsPaid,
                'conversion_label' => $issued > 0 ? round($requestsPaid / $issued * 100).'%' : 'Sin datos',
                'refunded_label' => '$'.number_format((float) $refunded, 2),
            ],
            'rows' => $rows,
        ]);
    }
}
