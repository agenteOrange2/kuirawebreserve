<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\SiteIntegration;
use App\Services\Agent\AgentBrain;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página Integración (spec-integracion-sitios): tokens de sitios
 * conectados, endpoint de catálogo vivo y agente importador con
 * validación. Vive detrás del módulo motor-web (habilitable por plan o
 * forzado por el admin en la ficha del hotel).
 */
class IntegrationPageController extends Controller
{
    public function __invoke(Request $request, AgentBrain $brain): Response
    {
        $domain = $request->getHost();
        $settings = \App\Models\Property::query()->first()?->settings ?? [];
        $property = \App\Models\Property::firstOrFail();

        // Widgets incrustables: cada wizard público se puede pegar en WP (o
        // cualquier sitio) por script o shortcode. El toggle apaga también
        // la página pública. Los precios SIEMPRE son en vivo: el iframe
        // habla directo con el motor — cambiar tarifas aquí se refleja al
        // instante en la página del hotel.
        $widgets = collect([
            ['key' => 'reservas', 'label' => 'Habitaciones', 'description' => 'El wizard de reservas completo: fechas, habitación, extras y pago.', 'path' => '/reservar', 'module' => 'motor-web'],
            ['key' => 'experiencias', 'label' => 'Experiencias', 'description' => 'Tours y recorridos con cupo en vivo y pago en línea.', 'path' => '/reservar/experiencias', 'module' => 'experiencias'],
            ['key' => 'grupos', 'label' => 'Reservas grupales', 'description' => 'Varias habitaciones de un jalón con un solo pago consolidado.', 'path' => '/reservar/grupos', 'module' => 'grupos'],
        ])->map(fn (array $widget) => [
            'key' => $widget['key'],
            'label' => $widget['label'],
            'description' => $widget['description'],
            'url' => "https://{$domain}{$widget['path']}",
            'module_enabled' => (bool) tenant()?->hasModule($widget['module']),
            'enabled' => (bool) ($settings['widget_'.$widget['key'].'_enabled'] ?? true),
            'shortcode' => '[kuira_'.$widget['key'].']',
            'embed' => '<div data-kuira-widget="'.$widget['key'].'"></div>'."\n".'<script src="https://'.$domain.'/widget.js" defer></script>',
        ])->values();

        return Inertia::render('tenant/integration/Index', [
            'propertyId' => $property->id,
            'widgets' => $widgets,
            'widgetScriptUrl' => "https://{$domain}/widget.js",
            'integrations' => SiteIntegration::query()
                ->where('tenant_id', tenant('id'))
                ->latest()
                ->get()
                ->map(fn (SiteIntegration $i) => SiteIntegrationController::serialize($i))
                ->values(),
            'suggestions' => SiteImportController::pending(),
            'catalogUrl' => "https://{$domain}/api/site/catalog",
            // Wizard público de reservas (spec-motor-reservas-web E0): la
            // URL que se pega en WhatsApp/Instagram/bio o botón de WP.
            'wizardUrl' => "https://{$domain}/reservar",
            // El importador usa la cadena de IA del bot (BYOK o plataforma).
            'aiConfigured' => $brain->isConfigured(),
            'canManage' => $request->user()->can('properties.manage'),
        ]);
    }
}
