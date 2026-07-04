<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Stay;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reportes de reservas: resumen semanal / mensual / anual / rango
 * personalizado con desglose por estado, tipo de habitación y canal,
 * serie temporal y descarga en PDF.
 */
class ReservationReportsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('tenant/reservations/Reports', $this->reportData($request) + [
            'property' => Property::query()->firstOrFail()->only(['id', 'name']),
        ]);
    }

    public function pdf(Request $request)
    {
        $data = $this->reportData($request);
        $data['property'] = Property::query()->firstOrFail()->only(['id', 'name']);
        $data['generatedAt'] = now()->format('d/m/Y H:i');

        $slug = $data['filters']['from'].'-a-'.$data['filters']['to'];

        return Pdf::loadView('pdf.reservations-report', $data)
            ->setPaper('letter')
            ->download("reporte-reservas-{$slug}.pdf");
    }

    /**
     * @return array<string, mixed>
     */
    protected function reportData(Request $request): array
    {
        $request->validate([
            'period' => ['nullable', 'in:week,month,year,custom'],
            'from' => ['nullable', 'date', 'required_if:period,custom'],
            'to' => ['nullable', 'date', 'after_or_equal:from', 'required_if:period,custom'],
        ]);

        $period = $request->query('period', 'month');
        $today = CarbonImmutable::today();

        [$from, $to, $label] = match ($period) {
            'week' => [$today->startOfWeek(), $today->endOfWeek(), 'Semana del '.$today->startOfWeek()->format('d/m/Y')],
            'year' => [$today->startOfYear(), $today->endOfYear(), 'Año '.$today->year],
            'custom' => [
                CarbonImmutable::parse($request->query('from'))->startOfDay(),
                CarbonImmutable::parse($request->query('to'))->endOfDay(),
                CarbonImmutable::parse($request->query('from'))->format('d/m/Y').' – '.CarbonImmutable::parse($request->query('to'))->format('d/m/Y'),
            ],
            default => [$today->startOfMonth(), $today->endOfMonth(), ucfirst($today->locale('es')->isoFormat('MMMM YYYY'))],
        };

        // Reservas cuya llegada cae en el rango (el "negocio" del periodo).
        $reservations = Reservation::query()
            ->with(['roomType:id,name'])
            ->whereBetween('starts_at', [$from, $to])
            ->get();

        $payments = Payment::query()->whereBetween('paid_at', [$from, $to])->get(['amount', 'paid_at']);
        $orders = Order::query()
            ->where('status', Order::STATUS_COMPLETED)
            ->whereBetween('created_at', [$from, $to])
            ->get(['total', 'created_at']);

        $byStatus = $reservations->countBy(fn (Reservation $r) => $r->status->value);
        $total = $reservations->count();
        $cancelled = (int) ($byStatus[ReservationStatus::Cancelled->value] ?? 0);
        $noShow = (int) ($byStatus[ReservationStatus::NoShow->value] ?? 0);

        $paymentsTotal = round((float) $payments->sum('amount'), 2);
        $ordersTotal = round((float) $orders->sum('total'), 2);

        $sold = $reservations->reject(fn (Reservation $r) => in_array(
            $r->status,
            [ReservationStatus::Cancelled, ReservationStatus::NoShow],
            true,
        ));

        return [
            'filters' => [
                'period' => $period,
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
            'period' => [
                'label' => $label,
                'from' => $from->format('d/m/Y'),
                'to' => $to->format('d/m/Y'),
            ],
            'kpis' => [
                'total' => $total,
                'confirmed' => (int) ($byStatus[ReservationStatus::Confirmed->value] ?? 0),
                'checked_in' => (int) ($byStatus[ReservationStatus::CheckedIn->value] ?? 0),
                'completed' => (int) ($byStatus[ReservationStatus::Completed->value] ?? 0),
                'pending' => (int) ($byStatus[ReservationStatus::Pending->value] ?? 0),
                'cancelled' => $cancelled,
                'no_show' => $noShow,
                'cancel_rate' => $total > 0 ? round($cancelled / $total * 100, 1) : 0,
                'no_show_rate' => $total > 0 ? round($noShow / $total * 100, 1) : 0,
                'reserved_value' => round((float) $sold->sum('total_amount'), 2),
                'avg_reservation' => $sold->count() ? round((float) $sold->avg('total_amount'), 2) : 0,
                'payments_total' => $paymentsTotal,
                'orders_total' => $ordersTotal,
                'revenue_total' => round($paymentsTotal + $ordersTotal, 2),
                'check_ins' => Stay::query()->whereBetween('check_in_at', [$from, $to])->count(),
                'check_outs' => Stay::query()->whereBetween('check_out_at', [$from, $to])->count(),
            ],
            'series' => $this->buildSeries($from, $to, $reservations, $payments, $orders),
            'byStatus' => collect(ReservationStatus::cases())->map(fn (ReservationStatus $status) => [
                'status' => $status->value,
                'label' => $status->label(),
                'count' => (int) ($byStatus[$status->value] ?? 0),
            ])->filter(fn ($row) => $row['count'] > 0)->values(),
            'byRoomType' => $reservations->groupBy(fn (Reservation $r) => $r->roomType?->name ?? 'Sin tipo')
                ->map(fn (Collection $group, string $name) => [
                    'name' => $name,
                    'total' => $group->count(),
                    'cancelled' => $group->filter(fn (Reservation $r) => in_array($r->status, [ReservationStatus::Cancelled, ReservationStatus::NoShow], true))->count(),
                    'revenue' => round((float) $group->reject(fn (Reservation $r) => in_array($r->status, [ReservationStatus::Cancelled, ReservationStatus::NoShow], true))->sum('total_amount'), 2),
                ])->sortByDesc('total')->values(),
            'byChannel' => $reservations->countBy('source_channel')
                ->map(fn (int $count, string $channel) => [
                    'channel' => $channel,
                    'count' => $count,
                ])->sortByDesc('count')->values(),
        ];
    }

    /**
     * Serie temporal con cubetas según el tamaño del rango: día (≤31),
     * semana (≤120 días) o mes.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildSeries(CarbonImmutable $from, CarbonImmutable $to, Collection $reservations, Collection $payments, Collection $orders): array
    {
        $days = (int) $from->diffInDays($to) + 1;
        $step = match (true) {
            $days <= 31 => 'day',
            $days <= 120 => 'week',
            default => 'month',
        };

        $series = [];
        $cursor = $from;

        while ($cursor <= $to) {
            $bucketEnd = match ($step) {
                'day' => $cursor->endOfDay(),
                'week' => $cursor->addDays(6)->endOfDay(),
                default => $cursor->endOfMonth(),
            };
            if ($bucketEnd > $to) {
                $bucketEnd = $to;
            }

            $inBucket = fn ($moment) => $moment >= $cursor && $moment <= $bucketEnd;

            $series[] = [
                'label' => match ($step) {
                    'day' => $cursor->format('d/m'),
                    'week' => $cursor->format('d/m').' +',
                    default => ucfirst($cursor->locale('es')->isoFormat('MMM')),
                },
                'reservations' => $reservations->filter(fn (Reservation $r) => $inBucket($r->starts_at))->count(),
                'cancelled' => $reservations->filter(fn (Reservation $r) => $inBucket($r->starts_at) && in_array($r->status, [ReservationStatus::Cancelled, ReservationStatus::NoShow], true))->count(),
                'revenue' => round(
                    (float) $payments->filter(fn (Payment $p) => $p->paid_at && $inBucket($p->paid_at))->sum('amount')
                    + (float) $orders->filter(fn (Order $o) => $inBucket($o->created_at))->sum('total'),
                    2,
                ),
            ];

            $cursor = match ($step) {
                'day' => $cursor->addDay(),
                'week' => $cursor->addWeek(),
                default => $cursor->addMonthNoOverflow()->startOfMonth(),
            };
        }

        return $series;
    }
}
