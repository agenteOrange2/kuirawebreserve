<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Apariencia del PANEL por hotel (/ajustes/general/apariencia): el color
 * de acento (botones, links, activos) y el degradado del menú lateral.
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
        ]);
    }
}
