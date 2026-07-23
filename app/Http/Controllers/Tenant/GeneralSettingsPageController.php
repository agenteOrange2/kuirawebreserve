<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Datos generales del hotel (área aislada /ajustes/general): contacto,
 * redes sociales, horarios y moneda, políticas escritas y preguntas
 * frecuentes. Las políticas + FAQs son la fuente de get_policies() para
 * los agentes IA. Superficie propia, fuera de la portada de Ajustes.
 */
class GeneralSettingsPageController extends Controller
{
    public function __invoke(): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        return Inertia::render('tenant/settings/General', [
            'property' => [
                'id' => $property->id,
                'name' => $property->name,
                'address' => $property->address,
                'timezone' => $property->timezone,
            ],
            // Logo del hotel (identidad, lo consume el wizard público): se
            // sube/quita aquí vía /api/property-logo, aparte del Guardar.
            'logoUrl' => $property->wizardAppearance()['logo_url'],
            'settings' => [
                'check_in_time' => $settings['check_in_time'] ?? '15:00',
                'check_out_time' => $settings['check_out_time'] ?? '12:00',
                'currency' => $settings['currency'] ?? 'MXN',
                // Doble moneda: secundaria + tipo de cambio para mostrar el
                // "aprox" en el wizard y las confirmaciones. Null = una sola.
                'currency_secondary' => $settings['currency_secondary'] ?? null,
                'exchange_rate' => $settings['exchange_rate'] ?? null,
                'policies' => $settings['policies'] ?? '',
                // Contacto enriquecido con migración del teléfono/email único.
                'phones' => $settings['phones'] ?? (! empty($settings['phone'])
                    ? [['code' => $settings['phone_country_code'] ?? '52', 'number' => preg_replace('/\D+/', '', (string) $settings['phone'])]]
                    : []),
                'emails' => $settings['emails'] ?? (! empty($settings['email']) ? [$settings['email']] : []),
                'website' => $settings['website'] ?? '',
                'maps_url' => $settings['maps_url'] ?? '',
                'socials' => $settings['socials'] ?? [],
            ],
            'faqs' => \App\Models\Faq::query()->ordered()->get()
                ->map(fn (\App\Models\Faq $faq) => $faq->only(['id', 'question', 'answer', 'active', 'sort_order'])),
        ]);
    }
}
