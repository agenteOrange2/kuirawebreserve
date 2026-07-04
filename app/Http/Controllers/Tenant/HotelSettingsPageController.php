<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ajustes del hotel: datos generales, horarios de check-in/out, contacto y
 * políticas escritas. Estas políticas serán la fuente de get_policies()
 * para los agentes IA (docs/spec-pendientes-y-agentes.md §4.3).
 */
class HotelSettingsPageController extends Controller
{
    public function __invoke(): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        return Inertia::render('tenant/settings/Hotel', [
            'property' => [
                'id' => $property->id,
                'name' => $property->name,
                'address' => $property->address,
                'timezone' => $property->timezone,
            ],
            'settings' => [
                'check_in_time' => $settings['check_in_time'] ?? '15:00',
                'check_out_time' => $settings['check_out_time'] ?? '12:00',
                'currency' => $settings['currency'] ?? 'MXN',
                'phone' => $settings['phone'] ?? '',
                'email' => $settings['email'] ?? '',
                'policies' => $settings['policies'] ?? '',
            ],
            'plan' => tenant()->planLimits()['label'] ?? tenant('plan'),
            'faqs' => \App\Models\Faq::query()->ordered()->get()
                ->map(fn (\App\Models\Faq $faq) => $faq->only(['id', 'question', 'answer', 'active', 'sort_order'])),
        ]);
    }
}
