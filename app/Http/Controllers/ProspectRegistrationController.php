<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEventProspectRequest;
use App\Mail\ProspectDocumentsMail;
use App\Models\Central\PlanProspect;
use App\Models\Central\PlatformSetting;
use App\Models\Central\ProspectDocument;
use App\Services\PlatformMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Registro público de prospectos por QR (eventos presenciales): el
 * interesado elige servicios y al guardarse se le envían los documentos
 * por correo. El envío por WhatsApp lo hace el equipo a mano desde
 * /admin/prospectos con links wa.me.
 */
class ProspectRegistrationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Registro', [
            'services' => collect(PlanProspect::SERVICES)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values(),
        ]);
    }

    public function store(StoreEventProspectRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $prospect = PlanProspect::query()->create([
            'name' => $data['name'],
            'hotel_name' => $data['hotel_name'],
            'email' => Str::lower($data['email']),
            'phone' => '+'.ltrim($data['phone_code'], '+').' '.trim($data['phone']),
            'has_whatsapp' => (bool) ($data['has_whatsapp'] ?? false),
            'services' => array_values($data['services']),
            'source' => 'evento',
            'ip_hash' => hash('sha256', (string) $request->ip()),
        ]);

        // El fallo de correo se reporta pero nunca pierde el registro. El
        // interruptor vive en /admin/settings/correo (prospects_auto_email).
        $documents = ProspectDocument::query()
            ->forServices($prospect->services ?? [])
            ->ordered()
            ->get();

        $autoEmail = PlatformSetting::get('prospects_auto_email', '1') === '1';

        if ($documents->isNotEmpty() && $autoEmail) {
            try {
                $mailer = app(PlatformMailer::class)->mailer() ?? Mail::mailer();
                $mailer->to($prospect->email)->send(new ProspectDocumentsMail($prospect, $documents));
                $prospect->forceFill(['docs_email_sent_at' => now()])->save();
            } catch (Throwable $e) {
                report($e);
            }
        }

        return back()->with('success', 'Registro recibido. Te enviamos la información a tu correo.');
    }
}
