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
 * Centro de caja y pagos.
 *
 * /pagos era una sola página con cinco bloques apilados —cola de
 * verificación, cerradas, saldos vencidos, links vivos e historial
 * paginado— y 1,654 líneas de Vue: "está todo desorganizado y se pierde
 * uno" (dueño, 2026-09-18). Además el dinero estaba repartido en seis rutas
 * de dos grupos distintos del menú.
 *
 * Ahora /pagos es un TABLERO (mismo patrón que /reservas, que ya funcionó)
 * y cada trabajo vive en su propia superficie:
 *
 *   /pagos             tablero: lo que entró hoy y lo que pide atención
 *   /pagos/verificar   transferencias por verificar y las cerradas
 *   /pagos/cobrar      saldos vencidos y links de pago vivos
 *   /pagos/movimientos historial de pagos con filtros y paginación
 *
 * Desde el tablero se entra también a cortes de caja, turnos, cobros en
 * línea y cuentas por cerrar, que siguen en sus rutas de siempre.
 *
 * El dinero cobrado lo calcula CashLedger, nunca a mano: es la misma
 * contabilidad del dashboard, los cortes y los reportes.
 */
class PaymentsPageController extends Controller
{
    /** Tablero: el día en una pantalla y a qué entrar. */
    public function __invoke(Request $request): Response
    {
        return Inertia::render('tenant/payments/Hub', [
            'today' => $this->todayCash(),
            'attention' => $this->attention($request),
            'metrics' => $this->metrics(),
        ] + $this->access($request));
    }

    /**
     * Transferencias que esperan ojos humanos (spec-pagos §7.4): aprobar
     * registra el pago y confirma. Las cerradas van abajo para reemitir el
     * cobro cuando el huésped corrige — antes desaparecían y el staff no
     * tenía camino de regreso.
     */
    public function verify(Request $request): Response
    {
        abort_unless($request->user()->can('reservations.manage'), 403);

        return Inertia::render('tenant/payments/Verify', [
            'queue' => PaymentRequestController::queue(),
            'closedRequests' => $this->closedRequests(),
        ] + $this->access($request));
    }

    /**
     * Lo que falta cobrar: saldos vencidos (el impago NO cancela solo, el
     * equipo decide) y los links de pasarela todavía vivos.
     */
    public function collect(Request $request): Response
    {
        return Inertia::render('tenant/payments/Collect', [
            'overdueBalances' => $request->user()->can('reservations.manage')
                ? $this->overdueBalances()
                : [],
            'pendingLinks' => $this->pendingLinks(),
        ] + $this->access($request));
    }

    /** Historial de pagos registrados, con buscador y paginación. */
    public function movements(Request $request): Response
    {
        return Inertia::render('tenant/payments/Movements', [
            'recentPayments' => $this->recentPayments($request),
        ] + $this->access($request));
    }

    /**
     * Qué puede ver y hacer quien mira: lo comparten las cuatro pantallas
     * para pintar el mismo menú de áreas.
     *
     * @return array<string, bool>
     */
    protected function access(Request $request): array
    {
        $tenant = tenant();

        return [
            'canManage' => $request->user()->can('reservations.manage'),
            'canCashCuts' => $request->user()->can('orders.manage')
                && ($tenant === null || $tenant->hasModule('corte-caja')),
            // Los métodos de pago son configuración del hotel, no operación.
            'canSettings' => $request->user()->can('properties.manage'),
        ];
    }

    /**
     * Lo que entró HOY, con la contabilidad de los cortes. La fianza se
     * reporta aparte porque no es ingreso: es un depósito en garantía que se
     * devuelve al salir.
     *
     * @return array<string, mixed>
     */
    protected function todayCash(): array
    {
        $today = \Carbon\CarbonImmutable::today();
        $cash = app(\App\Services\CashLedger::class)->summary($today->startOfDay(), $today->endOfDay());

        return $cash + [
            'net_label' => '$'.number_format($cash['net'], 2),
            'collected_label' => '$'.number_format($cash['collected'], 2),
            'lodging_label' => '$'.number_format($cash['lodging'], 2),
            'pos_label' => '$'.number_format($cash['pos'], 2),
            'refunds_label' => '$'.number_format($cash['refunds'], 2),
            'guarantees_label' => '$'.number_format($cash['guarantees'], 2),
            'by_method' => array_map(
                fn (array $row) => $row + ['amount_label' => '$'.number_format($row['amount'], 2)],
                $cash['by_method'],
            ),
        ];
    }

    /**
     * Las gráficas del tablero: catorce días de ingresos y cómo se cobró en
     * esa ventana.
     *
     * Catorce días y no "hoy": una dona de un día que arranca en cero no
     * dice nada a las nueve de la mañana, y la quincena es el tramo con el
     * que el dueño compara.
     *
     * @return array<string, mixed>
     */
    protected function metrics(): array
    {
        $ledger = app(\App\Services\CashLedger::class);
        $to = \Carbon\CarbonImmutable::today()->endOfDay();
        $from = $to->subDays(13)->startOfDay();

        $series = $ledger->dailySeries($from, $to);
        $summary = $ledger->summary($from, $to);
        $total = round((float) array_sum(array_column($series, 'total')), 2);

        return [
            'series' => $series,
            'by_method' => array_map(
                fn (array $row) => $row + ['amount_label' => '$'.number_format($row['amount'], 2)],
                $summary['by_method'],
            ),
            'range_label' => $from->locale('es')->isoFormat('D MMM').' – '.$to->locale('es')->isoFormat('D MMM'),
            'total' => $total,
            'total_label' => '$'.number_format($total, 2),
            'best_label' => collect($series)->sortByDesc('total')->first()['label'] ?? null,
            'daily_average_label' => '$'.number_format($series === [] ? 0 : $total / count($series), 2),
        ];
    }

    /**
     * Lo que pide atención, ya contado en el servidor: cada tarjeta del
     * tablero dice cuánto hay y qué está atorado, sin que nadie tenga que
     * entrar a las cuatro pantallas para enterarse.
     *
     * @return array<string, mixed>
     */
    protected function attention(Request $request): array
    {
        $canManage = $request->user()->can('reservations.manage');

        $queue = $canManage
            ? PaymentRequest::query()
                ->where('method', PaymentRequest::METHOD_TRANSFER)
                ->where('status', PaymentRequest::STATUS_PENDING)
                ->get(['id', 'created_at'])
            : collect();

        // Dinero esperando ojos desde hace rato: es lo que hace que un
        // huésped que ya pagó siga sin confirmación.
        $oldest = $queue->min('created_at');

        $overdue = $canManage
            ? \App\Models\Reservation::query()
                ->where('status', \App\Enums\ReservationStatus::Confirmed)
                ->where('payment_status', '!=', \App\Enums\PaymentStatus::Paid)
                ->whereNotNull('payment_due_at')
                ->where('payment_due_at', '<', now())
                ->withSum(['payments as paid_amount' => fn ($q) => $q->where(
                    fn ($qq) => $qq->whereNull('kind')->orWhere('kind', '!=', Payment::KIND_GUARANTEE)
                )], 'amount')
                ->get(['id', 'total_amount'])
                ->map(fn ($r) => max(0, round((float) $r->total_amount - (float) ($r->paid_amount ?? 0), 2)))
                ->filter(fn (float $pending) => $pending > 0)
            : collect();

        $links = PaymentRequest::query()
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->where('method', PaymentRequest::METHOD_GATEWAY)
            ->get(['id', 'expires_at']);

        $shifts = \App\Models\Shift::query()->open()->get(['id', 'started_at']);

        $lastCut = \App\Models\CashCut::query()->latest('closed_at')->first();

        return [
            'queue' => [
                'count' => $queue->count(),
                'waiting_label' => $oldest ? $oldest->diffForHumans(short: true) : null,
                // Más de dos horas esperando es alguien sin su confirmación.
                'stale' => $oldest !== null && $oldest->lt(now()->subHours(2)),
            ],
            'overdue' => [
                'count' => $overdue->count(),
                'total_label' => '$'.number_format((float) $overdue->sum(), 2),
            ],
            'links' => [
                'count' => $links->count(),
                'expiring' => $links->filter(fn ($l) => $l->expires_at !== null
                    && $l->expires_at->between(now(), now()->addHours(6)))->count(),
            ],
            'settlements' => [
                'count' => \App\Models\Stay::query()->pendingSettlement()->count()
                    + \App\Models\Reservation::query()->pendingSettlement()->count(),
            ],
            'shift' => [
                'open' => $shifts->count(),
                'since_label' => $shifts->min('started_at')?->diffForHumans(short: true),
                // Un turno de más de 12 horas casi siempre es uno que nadie
                // cerró, no alguien trabajando de más.
                'stale' => $shifts->min('started_at')?->lt(now()->subHours(12)) ?? false,
            ],
            'cuts' => [
                'today' => \App\Models\CashCut::query()->whereDate('closed_at', today())->count(),
                'last_label' => $lastCut?->closed_at?->diffForHumans(short: true),
                // El último corte no cuadró: falta o sobra efectivo.
                'off' => $lastCut !== null && abs((float) $lastCut->difference) >= 0.01,
                'off_label' => $lastCut !== null && abs((float) $lastCut->difference) >= 0.01
                    ? '$'.number_format(abs((float) $lastCut->difference), 2)
                    : null,
            ],
        ];
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
