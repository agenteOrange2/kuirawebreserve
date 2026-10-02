<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\FilterPlanProspectsRequest;
use App\Http\Requests\UpdatePlanProspectRequest;
use App\Mail\ProspectDocumentsMail;
use App\Models\Central\AdminActivity;
use App\Models\Central\Plan;
use App\Models\Central\PlanProspect;
use App\Models\Central\PlatformSetting;
use App\Models\Central\ProspectDocument;
use App\Services\Admin\AdminActivityCatalog;
use App\Services\PlatformMailer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PlanProspectController extends Controller
{
    /** @var array<string, string> */
    public const SOURCES = [
        'landing' => 'Landing',
        'evento' => 'Registro por QR',
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        'new' => 'Nuevo',
        'contacted' => 'Contactado',
        'qualified' => 'Calificado',
        'won' => 'Ganado',
        'lost' => 'Descartado',
    ];

    public function index(FilterPlanProspectsRequest $request): Response
    {
        $filters = $request->validated();

        $documents = ProspectDocument::query()->ordered()->get();

        $prospects = $this->filtered($filters)
            ->with('plan:key,label')
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $history = $this->history($prospects->getCollection()->pluck('id')->all());

        $prospects->through(fn (PlanProspect $prospect) => [
            'id' => $prospect->id,
            'name' => $prospect->name,
            'hotel_name' => $prospect->hotel_name,
            'email' => $prospect->email,
            'phone' => $prospect->phone,
            'has_whatsapp' => $prospect->has_whatsapp,
            'rooms' => $prospect->rooms,
            'plan_key' => $prospect->plan_key,
            'plan_label' => $prospect->plan?->label ?? $prospect->plan_label,
            'services_labels' => $prospect->serviceLabels(),
            'message' => $prospect->message,
            'status' => $prospect->status,
            'notes' => $prospect->notes,
            'source' => $prospect->source,
            'source_label' => self::SOURCES[$prospect->source] ?? ucfirst((string) $prospect->source),
            'contacted_at' => $prospect->contacted_at?->format('d/m/Y H:i'),
            'created_at' => $prospect->created_at?->format('d/m/Y H:i'),
            'created_ago' => $prospect->created_at?->diffForHumans(),
            'docs_email_sent_at' => $prospect->docs_email_sent_at?->format('d/m/Y H:i'),
            'docs_whatsapp_sent_at' => $prospect->docs_whatsapp_sent_at?->format('d/m/Y H:i'),
            'docs_available' => $this->documentsFor($prospect, $documents)->count(),
            'wa_phone' => $prospect->whatsappNumber(),
            'wa_text' => $this->whatsappText($prospect, $documents),
            'wa_greeting' => $this->whatsappGreeting($prospect),
            'history' => $history->get($prospect->id, []),
        ]);

        $statusCounts = PlanProspect::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $total = (int) $statusCounts->sum();
        $won = (int) ($statusCounts['won'] ?? 0);

        return Inertia::render('admin/prospects/Index', [
            'prospects' => $prospects,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? 'all',
                'plan' => $filters['plan'] ?? '',
                'source' => $filters['source'] ?? '',
                'docs' => $filters['docs'] ?? '',
            ],
            'stats' => [
                'total' => $total,
                'new' => (int) ($statusCounts['new'] ?? 0),
                'contacted' => (int) ($statusCounts['contacted'] ?? 0),
                'qualified' => (int) ($statusCounts['qualified'] ?? 0),
                'won' => $won,
                'lost' => (int) ($statusCounts['lost'] ?? 0),
                'conversion' => $total > 0 ? (int) round($won * 100 / $total) : 0,
                // Vivos (ni ganados ni descartados) a los que no les ha llegado nada.
                'docs_pending' => PlanProspect::query()
                    ->whereNotIn('status', ['won', 'lost'])
                    ->whereNull('docs_email_sent_at')
                    ->whereNull('docs_whatsapp_sent_at')
                    ->count(),
                'this_week' => PlanProspect::query()
                    ->where('created_at', '>=', now()->subDays(7))
                    ->count(),
            ],
            'plans' => Plan::query()->ordered()->get(['key', 'label']),
            'sources' => collect(self::SOURCES)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values(),
            'registerUrl' => route('prospects.register'),
            'documentsCount' => $documents->count(),
            'mailConfigured' => app(PlatformMailer::class)->configured(),
            'autoEmailEnabled' => PlatformSetting::get('prospects_auto_email', '1') === '1',
            'emailSettingsUrl' => route('admin.settings.email.edit'),
        ]);
    }

    /**
     * Descarga en CSV lo que muestra el listado con los filtros actuales.
     */
    public function export(FilterPlanProspectsRequest $request): StreamedResponse
    {
        $query = $this->filtered($request->validated())->with('plan:key,label')->latest();

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            // BOM para que Excel lea los acentos.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Recibido', 'Hotel', 'Contacto', 'Correo', 'Teléfono', 'WhatsApp',
                'Habitaciones', 'Plan', 'Servicios', 'Estado', 'Origen',
                'Primer contacto', 'Docs por correo', 'Docs por WhatsApp', 'Mensaje', 'Notas',
            ]);

            $query->chunk(200, function ($rows) use ($out) {
                foreach ($rows as $prospect) {
                    /** @var PlanProspect $prospect */
                    fputcsv($out, [
                        $prospect->created_at?->format('Y-m-d H:i'),
                        $prospect->hotel_name,
                        $prospect->name,
                        $prospect->email,
                        $prospect->phone,
                        $prospect->has_whatsapp ? 'Sí' : 'No',
                        $prospect->rooms,
                        $prospect->plan?->label ?? $prospect->plan_label,
                        implode(', ', $prospect->serviceLabels()),
                        self::STATUS_LABELS[$prospect->status] ?? $prospect->status,
                        self::SOURCES[$prospect->source] ?? $prospect->source,
                        $prospect->contacted_at?->format('Y-m-d H:i'),
                        $prospect->docs_email_sent_at?->format('Y-m-d H:i'),
                        $prospect->docs_whatsapp_sent_at?->format('Y-m-d H:i'),
                        $prospect->message,
                        $prospect->notes,
                    ]);
                }
            });

            fclose($out);
        }, 'prospectos-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function update(UpdatePlanProspectRequest $request, PlanProspect $planProspect): RedirectResponse
    {
        $data = $request->validated();

        // El cambio rápido de estado desde el renglón no manda notas: se conservan.
        if (! $request->has('notes')) {
            unset($data['notes']);
        }

        if ($data['status'] !== 'new' && $planProspect->contacted_at === null) {
            $data['contacted_at'] = now();
        }

        $planProspect->update($data);

        return back()->with('success', 'Prospecto actualizado.');
    }

    /**
     * Borra en masa los prospectos seleccionados en la tabla.
     */
    public function destroyBulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $deleted = PlanProspect::query()->whereIn('id', $data['ids'])->delete();

        return back()->with('success', $deleted === 1
            ? 'Prospecto eliminado.'
            : "{$deleted} prospectos eliminados.");
    }

    /**
     * Envía (o reenvía) por correo los documentos de los servicios del prospecto.
     */
    public function sendDocuments(PlanProspect $planProspect): RedirectResponse
    {
        $documents = ProspectDocument::query()
            ->forServices($planProspect->services ?? [])
            ->ordered()
            ->get();

        if ($documents->isEmpty()) {
            return back()->with('error', 'No hay documentos cargados para los servicios de este prospecto.');
        }

        try {
            $mailer = app(PlatformMailer::class)->mailer() ?? Mail::mailer();
            $mailer->to($planProspect->email)->send(new ProspectDocumentsMail($planProspect, $documents));
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo enviar el correo. Revisa la configuración de correo.');
        }

        $planProspect->forceFill(['docs_email_sent_at' => now()])->save();

        return back()->with('success', 'Documentos enviados a '.$planProspect->email.'.');
    }

    /**
     * Sella el envío por WhatsApp (el envío real lo hace el equipo vía wa.me).
     */
    public function markWhatsappSent(PlanProspect $planProspect): RedirectResponse
    {
        $planProspect->forceFill(['docs_whatsapp_sent_at' => now()])->save();

        return back()->with('success', 'Envío por WhatsApp registrado.');
    }

    /**
     * La consulta del listado con sus filtros; la comparten la vista y el CSV.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<PlanProspect>
     */
    private function filtered(array $filters): Builder
    {
        return PlanProspect::query()
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function ($query) use ($like) {
                    $query->where('name', 'like', $like)
                        ->orWhere('hotel_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like);
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => match ($status) {
                'all' => $query,
                'open' => $query->whereIn('status', ['contacted', 'qualified']),
                default => $query->where('status', $status),
            })
            ->when($filters['plan'] ?? null, fn ($query, string $plan) => $query->where('plan_key', $plan))
            ->when($filters['source'] ?? null, fn ($query, string $source) => $query->where('source', $source))
            // "Sin documentos" es la cifra de arriba: solo los vivos, que a un
            // ganado o descartado ya no hace falta mandarle nada.
            ->when($filters['docs'] ?? null, fn ($query, string $docs) => $docs === 'pending'
                ? $query->whereNull('docs_email_sent_at')->whereNull('docs_whatsapp_sent_at')->whereNotIn('status', ['won', 'lost'])
                : $query->where(fn ($q) => $q->whereNotNull('docs_email_sent_at')->orWhereNotNull('docs_whatsapp_sent_at')));
    }

    /**
     * Lo que el equipo ha hecho con cada prospecto, según la bitácora del panel.
     *
     * @param  list<int>  $ids
     * @return Collection<int, list<array<string, mixed>>>
     */
    private function history(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return AdminActivity::query()
            ->with('user:id,name')
            ->where('subject_type', 'prospect')
            ->whereIn('subject_id', $ids)
            ->latest('id')
            ->get()
            ->groupBy('subject_id')
            ->map(fn (Collection $rows) => $rows->take(10)->map(function (AdminActivity $activity) {
                $meta = AdminActivityCatalog::describe($activity->action);
                $changes = (array) ($activity->properties['changes'] ?? []);
                $details = [];

                if (isset($changes['status'])) {
                    [$old, $new] = $changes['status'];
                    $details[] = (self::STATUS_LABELS[$old] ?? $old).' → '.(self::STATUS_LABELS[$new] ?? $new);
                }
                if (isset($changes['notes'])) {
                    $details[] = 'Editó las notas';
                }

                return [
                    'id' => $activity->id,
                    'label' => $meta['label'],
                    'icon' => $meta['icon'],
                    'tone' => $meta['tone'],
                    'details' => $details,
                    'user' => $activity->user?->name,
                    'at' => $activity->created_at?->format('d/m/Y H:i'),
                    'ago' => $activity->created_at?->diffForHumans(),
                ];
            })->values()->all());
    }

    /**
     * Documentos que le tocan al prospecto: los de sus servicios más los generales.
     *
     * @param  Collection<int, ProspectDocument>  $documents
     * @return Collection<int, ProspectDocument>
     */
    private function documentsFor(PlanProspect $prospect, Collection $documents): Collection
    {
        $services = [...($prospect->services ?? []), ProspectDocument::GENERAL_SERVICE];

        return $documents->filter(fn (ProspectDocument $document) => in_array($document->service, $services, true));
    }

    /**
     * Mensaje prellenado para wa.me con los links públicos de los documentos.
     *
     * @param  Collection<int, ProspectDocument>  $documents
     */
    private function whatsappText(PlanProspect $prospect, Collection $documents): ?string
    {
        if ($prospect->whatsappNumber() === null) {
            return null;
        }

        $relevant = $this->documentsFor($prospect, $documents);

        if ($relevant->isEmpty()) {
            return null;
        }

        $lines = [
            "Hola {$prospect->name}, soy del equipo de ".config('app.name').'.',
        ];

        if ($interest = $this->interest($prospect)) {
            $lines[] = "Gracias por tu interés en: {$interest}.";
        }

        $lines[] = 'Te comparto la información:';

        foreach ($relevant as $document) {
            $lines[] = "- {$document->title}: {$document->publicUrl()}";
        }

        $lines[] = 'Quedamos al pendiente de cualquier duda.';

        return implode("\n", $lines);
    }

    /**
     * Saludo para abrir la conversación cuando no hay documentos que mandar.
     * Este no sella nada: solo inicia el contacto.
     */
    private function whatsappGreeting(PlanProspect $prospect): ?string
    {
        if ($prospect->whatsappNumber() === null) {
            return null;
        }

        $interest = $this->interest($prospect);

        return "Hola {$prospect->name}, soy del equipo de ".config('app.name').'. '
            .($interest
                ? "Te escribo por tu solicitud de información sobre {$interest}."
                : 'Te escribo por tu solicitud de información.');
    }

    private function interest(PlanProspect $prospect): ?string
    {
        $labels = $prospect->serviceLabels();

        if ($labels !== []) {
            return implode(', ', $labels);
        }

        $plan = $prospect->plan?->label ?? $prospect->plan_label;

        return $plan ? "el plan {$plan}" : null;
    }
}
