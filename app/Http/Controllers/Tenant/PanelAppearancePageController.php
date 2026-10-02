<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\PlatformSetting;
use App\Models\Property;
use App\Support\TenantLoginBrand;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Apariencia del PANEL por hotel (/ajustes/general/apariencia): el color
 * de acento (botones, links, activos), el degradado del menú lateral y el
 * login en el dominio del hotel (TenantLoginBrand), que toma esos mismos
 * colores, el logo del hotel y textos/foto propios.
 * No confundir con /reservas/ajustes (apariencia del WIZARD público):
 * esto tiñe el panel que usa el staff del hotel, no lo que ve el huésped.
 *
 * Mecánica: se guardan hex en settings (panel_primary, panel_menu_from,
 * panel_menu_to), HandleInertiaRequests los comparte en panelTenant.colors
 * y RazeLayout pisa las variables CSS del theme (--color-primary,
 * --color-theme-1/2) en <html>. Sin colores guardados = tema Kuira.
 */
class PanelAppearancePageController extends Controller
{
    public function __invoke(): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        return Inertia::render('tenant/settings/PanelAppearance', [
            'property' => $property->only(['id', 'name']),
            'settings' => [
                'panel_primary' => $settings['panel_primary'] ?? null,
                'panel_menu_from' => $settings['panel_menu_from'] ?? null,
                'panel_menu_to' => $settings['panel_menu_to'] ?? null,
            ],
            // Login en el dominio del hotel: su marca + lo que la plataforma
            // pone por default (para la vista previa y los placeholders).
            'login' => [
                'enabled' => filter_var($settings['login_brand_enabled'] ?? true, FILTER_VALIDATE_BOOL),
                'hint' => $settings['login_hint'] ?? '',
                'title' => $settings['login_title'] ?? '',
                'subtitle' => $settings['login_subtitle'] ?? '',
                'logo_url' => $property->wizardAppearance()['logo_url'],
                'background_url' => TenantLoginBrand::backgroundUrl($property),
                'url' => url('/login'),
            ],
            'platform' => [
                'app_name' => PlatformSetting::get('app_name', 'KuiraReserve'),
                'logo_url' => ($p = PlatformSetting::get('logo_path')) ? '/storage/'.$p : null,
                'login_subtitle' => PlatformSetting::get('login_subtitle'),
                'login_background_url' => ($b = PlatformSetting::get('login_background_path')) ? '/storage/'.$b : null,
            ],
        ]);
    }
}
