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
                'agent_instructions' => $settings['agent_instructions'] ?? '',
            ],
            'plan' => tenant()->planLimits()['label'] ?? tenant('plan'),
            // Tarjeta "Tu plan": límites con uso real y módulos (E1 del
            // spec-plan-maestro). El uso se cuenta igual que los gates que
            // hacen cumplir cada límite.
            'planCard' => $this->planCard(),
            'faqs' => \App\Models\Faq::query()->ordered()->get()
                ->map(fn (\App\Models\Faq $faq) => $faq->only(['id', 'question', 'answer', 'active', 'sort_order'])),
            // Todo lo de cobros (pasarelas, cuentas, confirmación automática)
            // vive en su área aislada /ajustes/metodos-pago; aquí solo un
            // resumen para la tarjeta de acceso.
            'paymentSummary' => $this->paymentSummary($settings),
            // El correo saliente (SMTP) vive en /ajustes/mails; aquí solo
            // un resumen para su tarjeta de acceso.
            'mailSummary' => [
                'configured' => ! empty($settings['smtp_host']) && ! empty($settings['smtp_password']),
                'from_address' => $settings['smtp_from_address'] ?? '',
            ],
        ]);
    }

    /**
     * Resumen para la tarjeta "Métodos de pago": cuántas pasarelas activas y
     * cuántas cuentas de transferencia hay, sin exponer su configuración.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, int>
     */
    protected function paymentSummary(array $settings): array
    {
        return [
            'active_gateways' => \App\Models\Central\PaymentGatewayLink::query()
                ->where('tenant_id', tenant('id'))
                ->where('active', true)
                ->count(),
            'transfer_accounts' => collect($settings['bank_accounts'] ?? [])
                ->filter(fn (array $a) => ! empty($a['active']))
                ->count(),
        ];
    }

    /** @return array<string, mixed> */
    protected function planCard(): array
    {
        $tenant = tenant();
        $limits = $tenant->planLimits();

        // Canales conectados: mismo conteo que EvolutionChannelController.
        $channels = \App\Models\Central\EvolutionChannelLink::query()->where('tenant_id', $tenant->id)->count()
            + \App\Models\Central\MetaChannelLink::query()->where('tenant_id', $tenant->id)->count();

        $requested = \App\Models\Central\ModuleActivationRequest::query()
            ->where('tenant_id', $tenant->id)
            ->pluck('module')
            ->all();

        return [
            'label' => $limits['label'] ?? $tenant->plan,
            'price_monthly' => (int) ($limits['price_monthly'] ?? 0),
            'limits' => [
                ['label' => 'Habitaciones', 'used' => \App\Models\Room::count(), 'max' => $limits['max_rooms'] ?? null],
                ['label' => 'Usuarios', 'used' => \App\Models\User::count(), 'max' => $limits['max_users'] ?? null],
                ['label' => 'Propiedades', 'used' => Property::count(), 'max' => $limits['max_properties'] ?? null],
                ['label' => 'Canales de mensajería', 'used' => $channels, 'max' => $limits['max_channels'] ?? null],
                [
                    'label' => 'Pasarelas de pago',
                    'used' => \App\Models\Central\PaymentGatewayLink::query()->where('tenant_id', $tenant->id)->count(),
                    'max' => $limits['max_gateways'] ?? null,
                ],
            ],
            'modules' => collect(config('modules', []))
                ->map(fn (array $def, string $key) => [
                    'key' => $key,
                    'label' => $def['label'],
                    'description' => $def['description'],
                    'available' => $def['available'],
                    'enabled' => $tenant->hasModule($key),
                    'requested' => in_array($key, $requested, true),
                ])->values(),
        ];
    }
}
