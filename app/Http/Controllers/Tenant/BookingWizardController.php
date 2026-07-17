<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\RatePlan;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página pública del wizard de reservas (spec-motor-reservas-web E0),
 * patrón del webchat: standalone (sin RazeLayout), sin login. Detrás del
 * módulo motor-web (middleware de ruta).
 *
 * Las modalidades que se ofrecen (por noche / por bloque) NO son un
 * ajuste manual: se detectan de las tarifas activas reales del catálogo
 * — si el hotel no tiene ninguna tarifa `night`, esa pestaña ni aparece
 * (motellacupula es 100% bloque de 12h; forzar "solo noche" lo hubiera
 * dejado sin nada que vender). Lo único configurable es guest_policy
 * (hotel/familias vs motel/adultos) y cómo le llaman a su modalidad por
 * bloque — eso sí varía por negocio y no se puede inferir de los datos.
 */
class BookingWizardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $property = Property::firstOrFail();

        // Toggle del widget (/integracion): apagado, la página pública
        // tampoco existe.
        abort_unless((bool) (($property->settings['widget_reservas_enabled'] ?? true)), 404);
        $settings = $property->settings ?? [];

        $activeRatePlans = RatePlan::query()
            ->where('active', true)
            ->whereHas('roomType', fn ($q) => $q->where('active', true));

        return Inertia::render('tenant/reservar/Wizard', [
            'property' => [
                'name' => $property->name,
                'phone' => $settings['phone'] ?? null,
                'currency' => $settings['currency'] ?? 'MXN',
                'check_in_time' => $settings['check_in_time'] ?? '15:00',
                'check_out_time' => $settings['check_out_time'] ?? '12:00',
                'guest_policy' => $settings['guest_policy'] ?? 'family',
                'block_mode_label' => $settings['block_mode_label'] ?? 'Por rato/periodo',
            ],
            'hasNightRates' => (clone $activeRatePlans)->where('type', 'night')->exists(),
            'hasBlockRates' => (clone $activeRatePlans)->where('type', 'block')->exists(),
            // Cuánto dura el apartado (para el aviso previo a crear el hold —
            // antes se mostraba por error la duración de la ESTANCIA).
            'holdMinutes' => app(\App\Services\ReservationPolicy::class)->holdMinutes(),
            // Enlace cruzado al wizard de experiencias, solo si el módulo
            // está activo Y hay algo reservable.
            'hasExperiences' => (bool) tenant()?->hasModule('experiencias')
                && \App\Models\Experience::query()->where('active', true)->exists(),
            // Aviso "¿vienen en grupo?": el alta grupal vive en el panel
            // (recepción/teléfono); el wizard invita a llamar.
            'hasGroups' => (bool) tenant()?->hasModule('grupos'),
        ]);
    }
}
