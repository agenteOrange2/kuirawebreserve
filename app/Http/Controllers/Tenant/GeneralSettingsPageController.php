<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Datos generales del hotel (/ajustes/general): HUB con una pantalla por
 * tema, igual que /ajustes/metodos-pago. Antes era una sola página de ~1400
 * líneas con identidad, contacto, horarios, moneda, políticas y FAQs
 * apiladas: imposible de ordenar y de mantener.
 *
 * Las políticas + FAQs son la fuente de get_policies() para los agentes IA.
 */
class GeneralSettingsPageController extends Controller
{
    /** Portada: qué hay en cada área, sin entrar. */
    public function index(): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        return Inertia::render('tenant/settings/general/Index', [
            'property' => $property->only(['id', 'name']),
            'logoUrl' => $property->wizardAppearance()['logo_url'],
            // Mini-resúmenes para que cada tarjeta diga qué tiene adentro.
            'summary' => [
                'has_logo' => ! empty($property->wizardAppearance()['logo_url']),
                'address' => (bool) $property->address,
                'phones' => count($this->phones($settings)),
                'emails' => count($settings['emails'] ?? (! empty($settings['email']) ? [$settings['email']] : [])),
                'socials' => count($settings['socials'] ?? []),
                'check_in' => $settings['check_in_time'] ?? '15:00',
                'check_out' => $settings['check_out_time'] ?? '12:00',
                'currency' => $settings['currency'] ?? 'MXN',
                'timezone' => $property->timezone,
                'policies' => ! empty($settings['policies']),
                'faqs_active' => \App\Models\Faq::query()->active()->count(),
                'faqs_total' => \App\Models\Faq::query()->count(),
            ],
        ]);
    }

    /** Identidad, ubicación, teléfonos, correos y redes. */
    public function contact(): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        return Inertia::render('tenant/settings/general/Contact', [
            'property' => $property->only(['id', 'name', 'address']),
            'logoUrl' => $property->wizardAppearance()['logo_url'],
            'settings' => [
                'phones' => $this->phones($settings),
                'emails' => $settings['emails'] ?? (! empty($settings['email']) ? [$settings['email']] : []),
                'website' => $settings['website'] ?? '',
                'maps_url' => $settings['maps_url'] ?? '',
                'socials' => $settings['socials'] ?? [],
            ],
        ]);
    }

    /** Horarios de la casa, moneda y zona horaria. */
    public function operation(): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        return Inertia::render('tenant/settings/general/Operation', [
            'property' => $property->only(['id', 'name', 'timezone']),
            'settings' => [
                'check_in_time' => $settings['check_in_time'] ?? '15:00',
                'check_out_time' => $settings['check_out_time'] ?? '12:00',
                'currency' => $settings['currency'] ?? 'MXN',
                // Doble moneda: secundaria + tipo de cambio para mostrar el
                // "aprox" en el wizard y las confirmaciones. Null = una sola.
                'currency_secondary' => $settings['currency_secondary'] ?? null,
                'exchange_rate' => $settings['exchange_rate'] ?? null,
            ],
        ]);
    }

    /** Políticas escritas (fuente de verdad del asistente). */
    public function policies(): Response
    {
        $property = Property::firstOrFail();

        return Inertia::render('tenant/settings/general/Policies', [
            'property' => $property->only(['id', 'name']),
            'settings' => ['policies' => ($property->settings ?? [])['policies'] ?? ''],
        ]);
    }

    /** Preguntas frecuentes que el asistente responde tal cual. */
    public function faqs(): Response
    {
        return Inertia::render('tenant/settings/general/Faqs', [
            'property' => Property::firstOrFail()->only(['id', 'name']),
            'faqs' => \App\Models\Faq::query()->ordered()->get()
                ->map(fn (\App\Models\Faq $faq) => $faq->only(['id', 'question', 'answer', 'active', 'sort_order'])),
        ]);
    }

    /**
     * Teléfonos con la migración del teléfono único de versiones viejas.
     *
     * @param  array<string, mixed>  $settings
     * @return array<int, array{code: string, number: string}>
     */
    protected function phones(array $settings): array
    {
        return $settings['phones'] ?? (! empty($settings['phone'])
            ? [['code' => $settings['phone_country_code'] ?? '52', 'number' => preg_replace('/\D+/', '', (string) $settings['phone'])]]
            : []);
    }
}
