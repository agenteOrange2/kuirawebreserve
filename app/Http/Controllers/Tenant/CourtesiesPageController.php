<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Courtesy;
use App\Models\Property;
use App\Models\User;
use App\Services\Courtesies\CourtesyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cortesías (/reservas/cortesias): la bandeja del propietario para decidir
 * las solicitudes, el historial con su reporte y la exportación.
 *
 * El propietario ve todas; el resto del personal ve solo las que pidió (sabe
 * qué pasó con su solicitud sin enterarse de lo que regalan los dueños).
 */
class CourtesiesPageController extends Controller
{
    protected const PER_PAGE = 20;

    public function __construct(protected CourtesyService $courtesies) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $isOwner = $this->courtesies->isOwner($user);
        $filters = $this->filters($request, $isOwner);

        $paginator = $this->query($request, $filters, $isOwner)
            ->with([
                'reservation:id,code,created_at,room_id,starts_at,ends_at,status',
                'reservation.room:id,number',
                'stay:id,room_id,check_in_at,planned_end_at,status',
                'stay.room:id,number',
                'requester:id,name',
                'decider:id,name',
            ])
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $paginator->through(fn (Courtesy $c) => $this->row($c));

        $monthStart = now()->startOfMonth();
        $mine = fn (Builder $q) => $isOwner ? $q : $q->where('requested_by', $user->id);

        $monthApproved = $mine(Courtesy::query())
            ->where('status', Courtesy::STATUS_APPROVED)
            ->where('decided_at', '>=', $monthStart);

        return Inertia::render('tenant/reservations/Courtesies', [
            'property' => Property::firstOrFail()->only(['id', 'name']),
            'courtesies' => $paginator,
            'filters' => $filters,
            'summary' => [
                'month_label' => ucfirst(now()->locale('es')->isoFormat('MMMM YYYY')),
                'month_amount' => round((float) (clone $monthApproved)->sum('amount'), 2),
                'month_count' => (clone $monthApproved)->count(),
                'pending' => $mine(Courtesy::query())->pending()->count(),
                'cap' => $this->courtesies->monthlyCap(),
                'by_category' => (clone $monthApproved)
                    ->selectRaw('category, count(*) as total, sum(amount) as amount')
                    ->groupBy('category')
                    ->orderByDesc('amount')
                    ->get()
                    ->map(fn ($row) => [
                        'category' => $row->category,
                        'label' => config('courtesies.categories.'.$row->category, 'Otro'),
                        'count' => (int) $row->total,
                        'amount' => round((float) $row->amount, 2),
                    ])
                    ->values(),
            ],
            'categories' => config('courtesies.categories'),
            'people' => $isOwner
                ? User::query()->whereIn('id', Courtesy::query()->select('requested_by'))->orderBy('name')->get(['id', 'name'])
                : [],
            'isOwner' => $isOwner,
            'focusId' => $request->integer('ver') ?: null,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $isOwner = $this->courtesies->isOwner($request->user());
        $filters = $this->filters($request, $isOwner);
        $filename = 'cortesias-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($request, $filters, $isOwner) {
            $out = fopen('php://output', 'w');
            // BOM: sin él Excel en Windows parte los acentos.
            fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, [
                'Fecha', 'Cuenta', 'Habitación', 'Huésped', 'Cortesía', 'Hospedaje',
                'Descuento', 'Categoría', 'Motivo', 'Estado', 'La pidió', 'La decidió',
                'Decidida', 'Pasó el tope',
            ]);

            $query = $this->query($request, $filters, $isOwner)
                ->with(['reservation:id,code,created_at,room_id', 'reservation.room:id,number', 'stay:id,room_id', 'stay.room:id,number', 'requester:id,name', 'decider:id,name'])
                ->latest('id');

            foreach ($query->cursor() as $courtesy) {
                $row = $this->row($courtesy);
                fputcsv($out, [
                    $courtesy->created_at?->format('Y-m-d H:i'),
                    $row['target_label'],
                    $row['room_number'] ?? '',
                    $row['guest_name'] ?? '',
                    $row['kind_label'],
                    number_format($row['lodging_base'], 2, '.', ''),
                    number_format($row['amount'], 2, '.', ''),
                    $row['category_label'],
                    $row['reason'],
                    $row['status_label'],
                    $row['requested_by_name'] ?? '',
                    $row['decided_by_name'] ?? '',
                    $courtesy->decided_at?->format('Y-m-d H:i') ?? '',
                    $row['over_cap'] ? 'sí' : 'no',
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Tope mensual (/ajustes/cortesias): página propia, solo propietario. */
    public function settings(): Response
    {
        return Inertia::render('tenant/settings/Courtesies', [
            'cap' => $this->courtesies->monthlyCap(),
            'monthUsed' => $this->courtesies->monthUsage(),
            'monthLabel' => ucfirst(now()->locale('es')->isoFormat('MMMM YYYY')),
        ]);
    }

    /**
     * @return array{estado: string, categoria: string, desde: string, hasta: string, q: string, persona: ?int}
     */
    protected function filters(Request $request, bool $isOwner): array
    {
        $request->validate([
            'estado' => ['nullable', Rule::in(['', 'todas', 'pending', 'approved', 'rejected', 'canceled', 'revoked'])],
            'categoria' => ['nullable', 'string', 'max:30'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:80'],
            'persona' => ['nullable', 'integer'],
        ]);

        // Sin estado en la URL, el propietario cae en lo que espera su
        // decisión (si hay algo); "todas" es el historial completo.
        $estado = (string) $request->input('estado', '');
        if (! $request->has('estado')) {
            // Desde la campana (?ver=ID) se busca esa en todo el historial:
            // pudo decidirse ya.
            $estado = $isOwner && ! $request->filled('ver') && Courtesy::query()->pending()->exists() ? 'pending' : 'todas';
        }

        return [
            'estado' => $estado === '' ? 'todas' : $estado,
            'categoria' => (string) $request->input('categoria', ''),
            'desde' => (string) $request->input('desde', ''),
            'hasta' => (string) $request->input('hasta', ''),
            'q' => trim((string) $request->input('q', '')),
            'persona' => $isOwner && $request->filled('persona') ? $request->integer('persona') : null,
        ];
    }

    /** @param  array<string, mixed>  $filters */
    protected function query(Request $request, array $filters, bool $isOwner): Builder
    {
        return Courtesy::query()
            ->when(! $isOwner, fn ($q) => $q->where('requested_by', $request->user()->id))
            ->when(! in_array($filters['estado'], ['', 'todas'], true), fn ($q) => $q->where('status', $filters['estado']))
            ->when($filters['categoria'] !== '', fn ($q) => $q->where('category', $filters['categoria']))
            ->when($filters['desde'] !== '', fn ($q) => $q->where('created_at', '>=', $filters['desde'].' 00:00:00'))
            ->when($filters['hasta'] !== '', fn ($q) => $q->where('created_at', '<=', $filters['hasta'].' 23:59:59'))
            ->when($filters['persona'], fn ($q) => $q->where('requested_by', $filters['persona']))
            ->when($filters['q'] !== '', function ($q) use ($filters) {
                $term = $filters['q'];
                // Folio RES-2026-0123: los dígitos del final son el id.
                $folioId = preg_match('/(\d+)$/', $term, $m) ? (int) $m[1] : null;

                $q->where(fn ($inner) => $inner
                    ->where('guest_name', 'like', "%{$term}%")
                    ->orWhere('reason', 'like', "%{$term}%")
                    ->orWhereHas('reservation', fn ($r) => $r->where('code', 'like', "%{$term}%"))
                    ->when($folioId, fn ($w) => $w->orWhere('reservation_id', $folioId)));
            });
    }

    /** @return array<string, mixed> */
    protected function row(Courtesy $courtesy): array
    {
        $reservation = $courtesy->reservation;
        $stay = $courtesy->stay;

        return [
            ...$courtesy->toPayload(),
            'guest_name' => $courtesy->guest_name,
            'reservation_id' => $courtesy->reservation_id,
            'stay_id' => $courtesy->stay_id,
            'target_label' => $reservation
                ? $reservation->displayCode()
                : 'Estancia de mostrador',
            'room_number' => $reservation?->room?->number ?? $stay?->room?->number,
            'dates' => $reservation?->starts_at
                ? $reservation->starts_at->format('d/m/Y').' → '.$reservation->ends_at?->format('d/m/Y')
                : ($stay?->check_in_at ? $stay->check_in_at->format('d/m/Y H:i') : null),
            'is_mine' => $courtesy->requested_by === request()->user()?->id,
        ];
    }
}
