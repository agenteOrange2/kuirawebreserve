<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ExperienceBooking;
use App\Models\Payment;
use App\Models\PaymentRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Centro de pagos del panel (/pagos): TODO el dinero en un solo lugar —
 * transferencias por verificar, saldos vencidos, links de pago vivos y los
 * últimos pagos registrados. Antes la cola de verificación vivía embebida
 * en la Bandeja de conversaciones; los pagos son operación propia, no una
 * conversación (feedback 2026-07-17). La conciliación fina sigue en
 * /cobros-en-linea.
 */
class PaymentsPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $canManage = $request->user()->can('reservations.manage');

        return Inertia::render('tenant/payments/Index', [
            // Transferencias reportadas que esperan verificación humana
            // (spec-pagos §7.4): aprobar registra el pago y confirma.
            'queue' => $canManage ? PaymentRequestController::queue() : [],
            // Rechazadas/vencidas recientes: visibles para poder reemitir
            // el cobro cuando el huésped corrige (antes desaparecían y el
            // staff no tenía camino de regreso).
            'closedRequests' => $canManage ? $this->closedRequests() : [],
            // Saldos vencidos (spec-pagos §7.2): el impago NO cancela solo
            // por default — alerta aquí y el equipo decide.
            'overdueBalances' => $canManage ? $this->overdueBalances() : [],
            'pendingLinks' => $this->pendingLinks(),
            'recentPayments' => $this->recentPayments($request),
            'canManage' => $canManage,
        ]);
    }

    /**
     * Transferencias rechazadas o vencidas de los últimos 3 días: el caso
     * típico es "rechacé el comprobante malo y el huésped mandó el bueno"
     * — desde aquí se reemite el cobro sin perder el hilo.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function closedRequests(): array
    {
        return PaymentRequest::query()
            ->with(['reservation:id,guest_name,created_at'])
            ->where('method', PaymentRequest::METHOD_TRANSFER)
            ->whereIn('status', [PaymentRequest::STATUS_REJECTED, PaymentRequest::STATUS_EXPIRED])
            ->whereNotNull('reservation_id')
            ->where('updated_at', '>=', now()->subHours(72))
            ->latest('updated_at')
            ->limit(10)
            ->get()
            ->map(fn (PaymentRequest $r) => [
                'id' => $r->id,
                'reservation_code' => $r->subjectCode(),
                'guest_name' => $r->reservation?->guest_name ?? 'Huésped',
                'concept' => $r->conceptLabel(),
                'amount_label' => $r->amountLabel(),
                'status' => $r->status,
                'status_label' => $r->statusLabel(),
                'reason' => $r->meta['rejected_reason'] ?? null,
                'closed_label' => $r->updated_at->diffForHumans(short: true),
            ])
            ->values()
            ->all();
    }

    /**
     * Links de pasarela vivos: emitidos y aún sin pagar — el staff los
     * copia y comparte, o los cancela si ya no aplican.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function pendingLinks(): array
    {
        return PaymentRequest::query()
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->where('method', PaymentRequest::METHOD_GATEWAY)
            ->with(['reservation', 'experienceBooking', 'group'])
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (PaymentRequest $pr) => [
                'id' => $pr->id,
                'subject' => $pr->subjectLabel(),
                'concept' => $pr->conceptLabel(),
                'amount_label' => $pr->amountLabel(),
                'provider' => $pr->provider,
                'checkout_url' => $pr->checkout_url,
                'expires_label' => $pr->expires_at?->diffForHumans(),
                'created_label' => $pr->created_at->format('d/m H:i'),
            ])
            ->values()
            ->all();
    }

    /**
     * Pagos registrados con paginador y filtros (folio/referencia/huésped y
     * método) — el detalle completo viaja en cada fila para el modal, y el
     * estatus refleja los reembolsos de F4.
     *
     * @return array<string, mixed>
     */
    protected function recentPayments(Request $request): array
    {
        $query = Payment::query()
            ->with(['reservation:id,guest_name,created_at', 'receivedBy:id,name', 'paymentRequest', 'refunds'])
            ->latest('id');

        $method = (string) $request->query('method', '');
        if (in_array($method, ['cash', 'card', 'transfer', Payment::METHOD_ONLINE], true)) {
            $query->where('method', $method);
        }

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(function ($where) use ($q) {
                // Folio mostrado (RES-2026-0032 / EXP-2026-0004): el número
                // final es el id real del sujeto.
                if (preg_match('/(?:RES|EXP|GRP)-\d{4}-0*(\d+)/i', $q, $m)) {
                    $where->where('reservation_id', (int) $m[1])
                        ->orWhere('experience_booking_id', (int) $m[1]);

                    return;
                }

                $where->where('reference', 'like', "%{$q}%")
                    ->orWhere('gateway_ref', 'like', "%{$q}%")
                    ->orWhereHas('reservation', fn ($r) => $r->where('guest_name', 'like', "%{$q}%"));
            });
        }

        $page = $query
            ->paginate(15, ['*'], 'payments_page', max(1, (int) $request->query('payments_page', 1)))
            ->withQueryString();

        $experienceBookings = ExperienceBooking::query()
            ->whereIn('id', collect($page->items())->pluck('experience_booking_id')->filter())
            ->get()
            ->keyBy('id');

        $methodLabels = [
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'transfer' => 'Transferencia',
            Payment::METHOD_ONLINE => 'En línea',
        ];

        return [
            'data' => collect($page->items())->map(function (Payment $p) use ($experienceBookings, $methodLabels) {
                // Reembolsos del pago (F4): completados restan del estatus.
                $refunded = round((float) $p->refunds
                    ->where('status', \App\Models\Refund::STATUS_COMPLETED)
                    ->sum('amount'), 2);

                return [
                    'id' => $p->id,
                    'subject' => $p->reservation?->displayCode()
                        ?? $experienceBookings->get($p->experience_booking_id)?->displayCode()
                        ?? 'Estancia',
                    'guest_name' => $p->reservation?->guest_name
                        ?? $experienceBookings->get($p->experience_booking_id)?->guest_name,
                    'amount_label' => '$'.number_format((float) $p->amount, 2),
                    'fee_label' => $p->fee_amount !== null ? '$'.number_format((float) $p->fee_amount, 2) : null,
                    'method_label' => ($methodLabels[$p->method] ?? $p->method).($p->gateway ? ' · '.ucfirst($p->gateway) : ''),
                    'kind_label' => $p->kind === Payment::KIND_CONSUMPTION ? 'Consumo' : 'Hospedaje',
                    'concept' => $p->paymentRequest?->conceptLabel(),
                    'reference' => $p->reference,
                    'gateway_ref' => $p->gateway_ref,
                    'notes' => $p->notes,
                    'paid_label' => $p->paid_at?->format('d/m/Y H:i') ?? $p->created_at->format('d/m/Y H:i'),
                    'received_by' => $p->receivedBy?->name ?? 'Sistema',
                    'status' => $refunded > 0 ? 'refunded' : 'registered',
                    'status_label' => $refunded <= 0 ? 'Registrado'
                        : ($refunded >= (float) $p->amount ? 'Reembolsado' : 'Reembolso parcial'),
                    'refunded_label' => $refunded > 0 ? '$'.number_format($refunded, 2) : null,
                    'receipt' => $p->paymentRequest?->receiptPayload(),
                ];
            })->values()->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function overdueBalances(): array
    {
        return \App\Models\Reservation::query()
            ->where('status', \App\Enums\ReservationStatus::Confirmed)
            ->where('payment_status', '!=', \App\Enums\PaymentStatus::Paid)
            ->whereNotNull('payment_due_at')
            ->where('payment_due_at', '<', now())
            ->orderBy('payment_due_at')
            ->get()
            ->filter(fn ($r) => $r->pendingBalance() > 0)
            ->map(fn ($r) => [
                'id' => $r->id,
                'code' => $r->displayCode(),
                'guest_name' => $r->guest_name ?? 'Huésped',
                'pending_label' => '$'.number_format($r->pendingBalance(), 2),
                'due_label' => $r->payment_due_at->diffForHumans(),
                'starts_label' => $r->starts_at->format('d/m'),
                'conversation_id' => Conversation::query()
                    ->where('reservation_id', $r->id)->latest('id')->value('id'),
            ])
            ->values()
            ->all();
    }
}
