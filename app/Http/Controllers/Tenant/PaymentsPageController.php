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
            // Saldos vencidos (spec-pagos §7.2): el impago NO cancela solo
            // por default — alerta aquí y el equipo decide.
            'overdueBalances' => $canManage ? $this->overdueBalances() : [],
            'pendingLinks' => $this->pendingLinks(),
            'recentPayments' => $this->recentPayments(),
            'canManage' => $canManage,
        ]);
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
     * @return array<int, array<string, mixed>>
     */
    protected function recentPayments(): array
    {
        $payments = Payment::query()
            ->with(['reservation', 'receivedBy'])
            ->latest('id')
            ->limit(30)
            ->get();

        $experienceBookings = ExperienceBooking::query()
            ->whereIn('id', $payments->pluck('experience_booking_id')->filter())
            ->get()
            ->keyBy('id');

        $methodLabels = [
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'transfer' => 'Transferencia',
            Payment::METHOD_ONLINE => 'En línea',
        ];

        return $payments->map(fn (Payment $p) => [
            'id' => $p->id,
            'subject' => $p->reservation?->displayCode()
                ?? $experienceBookings->get($p->experience_booking_id)?->displayCode()
                ?? 'Estancia',
            'amount_label' => '$'.number_format((float) $p->amount, 2),
            'method_label' => ($methodLabels[$p->method] ?? $p->method).($p->gateway ? ' · '.ucfirst($p->gateway) : ''),
            'paid_label' => $p->paid_at?->format('d/m H:i') ?? $p->created_at->format('d/m H:i'),
            'received_by' => $p->receivedBy?->name ?? 'Sistema',
        ])->values()->all();
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
