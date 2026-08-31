<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\MetaChannelLink;
use App\Models\SocialComment;
use App\Services\Agent\AgentBrain;
use App\Services\Social\SocialSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ajustes del módulo de redes sociales, en su propia pantalla: qué contesta
 * solo el asistente y qué espera a una persona.
 *
 * En la queja el mensaje privado está bloqueado (esa regla no se negocia
 * desde la UI); su respuesta pública sí es configurable, pero solo con
 * plantilla fija — la IA nunca redacta una queja.
 */
class SocialSettingsPageController extends Controller
{
    public function __invoke(AgentBrain $brain): Response
    {
        return Inertia::render('tenant/social/Settings', [
            'settings' => (new SocialSettings)->all(),
            'classifications' => SocialComment::CLASSIFICATION_LABELS,
            'privateLocked' => [SocialComment::CLASS_COMPLAINT],
            'agentReady' => $brain->isConfigured(),
            'connected' => MetaChannelLink::query()
                ->where('tenant_id', (string) tenant('id'))
                ->whereIn('type', ['messenger', 'instagram'])
                ->where('active', true)
                ->pluck('type')
                ->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'activo' => ['required', 'boolean'],
            'moderacion_automatica' => ['required', 'boolean'],
            'avisar_quejas' => ['required', 'boolean'],
            'palabras_bloqueadas' => ['array', 'max:100'],
            'palabras_bloqueadas.*' => ['string', 'max:60'],
            'clasificaciones' => ['array'],
            'clasificaciones.*.responder_publico' => ['boolean'],
            'clasificaciones.*.mandar_privado' => ['boolean'],
            'clasificaciones.*.plantilla' => ['nullable', 'string', 'max:500'],
        ]);

        (new SocialSettings)->save($data);

        return back()->with('success', 'Ajustes guardados.');
    }
}
