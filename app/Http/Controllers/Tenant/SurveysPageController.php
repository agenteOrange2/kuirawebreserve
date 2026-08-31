<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\StaySurvey;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Resultados del cuestionario de experiencia: promedios (general y por
 * aspecto personalizado del hotel), distribución de calificaciones y las
 * respuestas recientes con comentario, acotados por PERIODO (hoy, semana,
 * mes, año, todo o rango libre) y con PDF imprimible. Los aspectos se
 * editan en /ajustes/encuestas; aquí también se leen respuestas de
 * aspectos que ya se quitaron (no se pierden).
 */
class SurveysPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('tenant/surveys/Index', $this->reportData($request) + [
            'canManage' => $request->user()->can('properties.manage'),
            // Para levantar la incidencia que destapó una queja sin salir
            // de aquí (el catálogo es el mismo del módulo incidencias).
            'incidentCategories' => collect(\App\Models\Incident::CATEGORIES)
                ->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])
                ->values(),
        ]);
    }

    /**
     * Filtros de la LISTA (no de los promedios: los indicadores siguen
     * siendo del periodo completo, si no la comparación se vuelve trampa).
     *
     * @param  Collection<int, StaySurvey>  $answered
     * @return Collection<int, StaySurvey>
     */
    protected function filterRows(Collection $answered, Request $request): Collection
    {
        $show = $request->query('show', 'all');
        $search = trim((string) $request->query('q', ''));

        return $answered
            ->filter(fn (StaySurvey $s) => match ($show) {
                // Lo que hay que atender: pide seguimiento y nadie lo cerró.
                'pending' => $s->handled_at === null
                    && (($s->rating !== null && $s->rating <= 3) || filled($s->comment)),
                'low' => $s->rating !== null && $s->rating <= 3,
                'commented' => filled($s->comment),
                'handled' => $s->handled_at !== null,
                default => true,
            })
            ->filter(function (StaySurvey $s) use ($search) {
                if ($search === '') {
                    return true;
                }

                $haystack = mb_strtolower(implode(' ', array_filter([
                    $s->stay?->guest_name,
                    $s->stay?->room?->number,
                    $s->comment,
                ])));

                return str_contains($haystack, mb_strtolower($search));
            })
            ->values();
    }

    /** PDF del reporte de satisfacción del periodo elegido. */
    public function pdf(Request $request)
    {
        $data = $this->reportData($request, forPdf: true) + [
            'property' => Property::query()->firstOrFail()->only(['id', 'name']),
            'generatedAt' => now()->format('d/m/Y H:i'),
        ];

        $slug = Str::slug($data['period']['label']);

        return Pdf::loadView('pdf.surveys-report', $data)
            ->setPaper('letter')
            ->download("reporte-satisfaccion-{$slug}.pdf");
    }

    /**
     * Página de la lista sobre la colección ya cargada (los promedios
     * necesitan todas las respuestas del periodo de todos modos; partirlo
     * en dos consultas no ahorraría nada y sí desincronizaría el reporte).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    protected function paginateRows(Collection $rows, Request $request): LengthAwarePaginator
    {
        $perPage = 25;
        $page = max(1, (int) $request->query('page', 1));

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function reportData(Request $request, bool $forPdf = false): array
    {
        $request->validate([
            'period' => ['nullable', 'in:day,week,month,year,all,custom'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'show' => ['nullable', 'in:all,pending,low,commented,handled'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $period = $request->query('period', 'all');

        [$from, $to, $label] = match ($period) {
            'day' => [now()->startOfDay(), now()->endOfDay(), 'Hoy'],
            'week' => [now()->startOfWeek(), now()->endOfWeek(), 'Esta semana'],
            'month' => [now()->startOfMonth(), now()->endOfMonth(), 'Este mes'],
            'year' => [now()->startOfYear(), now()->endOfYear(), 'Este año'],
            'custom' => [
                Carbon::parse($request->query('from', now()->startOfMonth()->toDateString()))->startOfDay(),
                Carbon::parse($request->query('to', now()->toDateString()))->endOfDay(),
                'Rango personalizado',
            ],
            default => [null, null, 'Histórico completo'],
        };

        $inPeriod = fn ($query, string $column) => $from !== null
            ? $query->whereBetween($column, [$from, $to])
            : $query;

        $aspects = StaySurvey::aspects();

        // Etiquetas por llave: los aspectos vigentes + los default + los
        // legacy (para respuestas históricas de aspectos ya quitados).
        $labels = collect(StaySurvey::DEFAULT_ASPECTS)
            ->concat([['key' => 'facilities', 'label' => 'Instalaciones']])
            ->concat($aspects)
            ->keyBy('key')
            ->map(fn (array $aspect) => $aspect['label']);

        // Enviadas del periodo (por fecha de creación del link).
        $sent = $inPeriod(StaySurvey::query(), 'created_at')->count();

        // Volumen de hotel: cientos por año — los promedios por aspecto se
        // calculan en PHP sobre las respondidas (el JSON de respuestas no
        // se agrega bien en SQL portable entre MySQL y SQLite).
        $answered = $inPeriod(StaySurvey::query()->submitted(), 'submitted_at')
            ->with([
                'stay.room:id,number,name',
                'stay.guest:id,first_name,last_name,phone,email',
                'guest:id,first_name,last_name,phone,email',
                'handler:id,name',
            ])
            ->latest('submitted_at')
            ->get();

        $aspectAverages = collect($aspects)->map(function (array $aspect) use ($answered) {
            $values = $answered
                ->map(fn (StaySurvey $survey) => $survey->answerFor($aspect['key']))
                ->filter();

            return [
                'key' => $aspect['key'],
                'label' => $aspect['label'],
                'average' => $values->isNotEmpty() ? round((float) $values->avg(), 1) : null,
            ];
        })->values();

        $distribution = $answered->countBy('rating');

        $rows = $forPdf ? $answered->take(50) : $this->filterRows($answered, $request);

        $responses = $rows->map(function (StaySurvey $survey) use ($labels) {
            // Todas las llaves con respuesta: JSON + columnas legacy.
            $keys = collect(array_keys($survey->answers ?? []))
                ->concat(collect(StaySurvey::LEGACY_COLUMNS)->filter(fn (string $column) => $survey->getAttribute($column) !== null)->keys())
                ->unique();

            $guest = $survey->guest ?? $survey->stay?->guest;

            return [
                'id' => $survey->id,
                'guest' => $survey->stay?->guest_name,
                'room' => $survey->stay?->room?->number,
                'room_id' => $survey->stay?->room_id,
                'stay_id' => $survey->stay_id,
                // Contacto para responderle: la queja se contesta, no se
                // archiva.
                'guest_phone' => $guest?->phone,
                'guest_email' => $guest?->email,
                'rating' => $survey->rating,
                'answers' => $keys->map(fn (string $key) => [
                    'label' => $labels[$key] ?? ucfirst($key),
                    'value' => $survey->answerFor($key),
                ])->filter(fn (array $answer) => $answer['value'] !== null)->values(),
                'comment' => $survey->comment,
                'submitted_at' => $survey->submitted_at->format('d/m/Y H:i'),
                // Seguimiento: quién la cerró, cuándo y con qué nota.
                'handled_at' => $survey->handled_at?->format('d/m/Y H:i'),
                'handled_by' => $survey->handler?->name,
                'handled_notes' => $survey->handled_notes,
                'incident_id' => $survey->incident_id,
                'needs_follow_up' => $survey->handled_at === null
                    && (($survey->rating !== null && $survey->rating <= 3) || filled($survey->comment)),
            ];
        })->values();

        $answeredCount = $answered->count();
        $avgRating = $answered->avg('rating');

        return [
            'filters' => [
                'period' => $period,
                'from' => ($from ?? now()->startOfMonth())->format('Y-m-d'),
                'to' => ($to ?? now())->format('Y-m-d'),
                'show' => $request->query('show', 'all'),
                'q' => trim((string) $request->query('q', '')),
            ],
            'period' => [
                'label' => $label,
                'from' => $from?->format('d/m/Y'),
                'to' => $to?->format('d/m/Y'),
            ],
            'kpis' => [
                'sent' => $sent,
                'answered' => $answeredCount,
                'response_rate' => $sent > 0 ? round($answeredCount / $sent * 100, 1) : 0,
                'avg_rating' => $avgRating !== null ? round((float) $avgRating, 1) : null,
                // Evaluaciones bajas del periodo (general en 1-2): las que
                // disparan alerta a la campana.
                'low' => $answered->filter(fn (StaySurvey $s) => $s->rating !== null && $s->rating <= 2)->count(),
                // El número que manda la operación: quejas y comentarios
                // que nadie ha cerrado todavía.
                'pending' => $answered->filter(fn (StaySurvey $s) => $s->handled_at === null
                    && (($s->rating !== null && $s->rating <= 3) || filled($s->comment)))->count(),
            ],
            'aspectAverages' => $aspectAverages,
            'distribution' => collect([5, 4, 3, 2, 1])->map(fn (int $stars) => [
                'stars' => $stars,
                'count' => (int) ($distribution[$stars] ?? 0),
            ])->values(),
            'responses' => $forPdf
                ? $responses
                : $this->paginateRows($responses, $request),
            'matching' => $responses->count(),
        ];
    }
}
