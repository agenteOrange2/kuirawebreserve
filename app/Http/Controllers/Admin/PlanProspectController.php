<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\FilterPlanProspectsRequest;
use App\Http\Requests\UpdatePlanProspectRequest;
use App\Mail\ProspectDocumentsMail;
use App\Models\Central\Plan;
use App\Models\Central\PlanProspect;
use App\Models\Central\PlatformSetting;
use App\Models\Central\ProspectDocument;
use App\Services\PlatformMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PlanProspectController extends Controller
{
    public function index(FilterPlanProspectsRequest $request): Response
    {
        $filters = $request->validated();

        $documents = ProspectDocument::query()->ordered()->get();

        $prospects = PlanProspect::query()
            ->with('plan:key,label')
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('hotel_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when(
                isset($filters['status']) && $filters['status'] !== 'all',
                fn ($query) => $query->where('status', $filters['status']),
            )
            ->when($filters['plan'] ?? null, fn ($query, string $plan) => $query->where('plan_key', $plan))
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(fn (PlanProspect $prospect) => [
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
                'contacted_at' => $prospect->contacted_at?->format('d/m/Y H:i'),
                'created_at' => $prospect->created_at?->format('d/m/Y H:i'),
                'docs_email_sent_at' => $prospect->docs_email_sent_at?->format('d/m/Y H:i'),
                'docs_whatsapp_sent_at' => $prospect->docs_whatsapp_sent_at?->format('d/m/Y H:i'),
                'wa_phone' => $prospect->whatsappNumber(),
                'wa_text' => $this->whatsappText($prospect, $documents),
            ]);

        $statusCounts = PlanProspect::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('admin/prospects/Index', [
            'prospects' => $prospects,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? 'all',
                'plan' => $filters['plan'] ?? '',
            ],
            'stats' => [
                'total' => PlanProspect::query()->count(),
                'new' => (int) ($statusCounts['new'] ?? 0),
                'qualified' => (int) ($statusCounts['qualified'] ?? 0),
                'won' => (int) ($statusCounts['won'] ?? 0),
            ],
            'plans' => Plan::query()->ordered()->get(['key', 'label']),
            'registerUrl' => route('prospects.register'),
            'documentsCount' => $documents->count(),
            'mailConfigured' => app(PlatformMailer::class)->configured(),
            'autoEmailEnabled' => PlatformSetting::get('prospects_auto_email', '1') === '1',
            'emailSettingsUrl' => route('admin.settings.email.edit'),
        ]);
    }

    public function update(UpdatePlanProspectRequest $request, PlanProspect $planProspect): RedirectResponse
    {
        $data = $request->validated();

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

        return back();
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

        $services = $prospect->services ?? [];
        $relevant = $documents->filter(
            fn (ProspectDocument $document) => in_array($document->service, [...$services, ProspectDocument::GENERAL_SERVICE], true),
        );

        if ($services === [] || $relevant->isEmpty()) {
            return null;
        }

        $lines = [
            "Hola {$prospect->name}, soy del equipo de ".config('app.name').'.',
            'Gracias por tu interés en: '.implode(', ', $prospect->serviceLabels()).'.',
            'Te comparto la información:',
        ];

        foreach ($relevant as $document) {
            $lines[] = "- {$document->title}: {$document->publicUrl()}";
        }

        $lines[] = 'Quedamos al pendiente de cualquier duda.';

        return implode("\n", $lines);
    }
}
