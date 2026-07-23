<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Área aislada de APARIENCIA de las páginas públicas (/reservas/ajustes):
 * logo, colores de fondo y acento, y modo claro/oscuro. Una sola
 * configuración aplica a TODAS: /reservar, /reservar/experiencias,
 * /reservar/grupos y /reserva (consulta). El comportamiento del wizard
 * (modalidad, extras, pago) vive aparte en /ajustes/wizard — aquí solo se
 * decide cómo SE VE.
 */
class WizardAppearancePageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $property = Property::firstOrFail();

        return Inertia::render('tenant/reservations/Settings', [
            'property' => $property->only(['id', 'name']),
            'wizardUrl' => "https://{$request->getHost()}/reservar",
            'appearance' => $property->wizardAppearance(),
            'defaults' => Property::WIZARD_APPEARANCE_DEFAULTS,
            'canManage' => $request->user()->can('properties.manage'),
        ]);
    }
}
