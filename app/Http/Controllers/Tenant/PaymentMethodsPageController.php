<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\PaymentGatewayLink;
use App\Models\Property;
use App\Models\RatePlan;
use App\Services\Payments\PaymentMethodGate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Área AISLADA de métodos de pago (/ajustes/metodos-pago): TODO lo que
 * decide cómo cobra el hotel en línea vive aquí, no disperso en Ajustes
 * general — pasarelas (llaves propias), cuentas para transferencia,
 * confirmación automática, saldos y el modo de pago del wizard.
 *
 * Incluye un diagnóstico de por qué las pasarelas pueden no aparecer en
 * /reservar: tener Stripe conectada NO basta — el wizard solo ofrece pago
 * cuando la reserva lo exige (payment_mode o anticipo de la tarifa). Ese
 * "está conectada pero nunca se ve" era invisible desde la config antigua.
 */
class PaymentMethodsPageController extends Controller
{
    public function __invoke(): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        $gateways = PaymentGatewayLink::query()
            ->where('tenant_id', tenant('id'))
            ->get()
            ->map(fn ($link) => PaymentGatewayController::serialize($link))
            ->values();

        return Inertia::render('tenant/settings/PaymentMethods', [
            'property' => $property->only(['id', 'name']),
            'settings' => [
                'bank_accounts' => array_values($settings['bank_accounts'] ?? []),
                'auto_confirm_on_payment' => (bool) ($settings['auto_confirm_on_payment'] ?? true),
                'balance_request_days' => (int) ($settings['balance_request_days'] ?? 3),
                'cancel_on_balance_overdue' => (bool) ($settings['cancel_on_balance_overdue'] ?? false),
                'payment_mode' => $settings['payment_mode'] ?? 'automatic',
                // Plazos (ReservationPolicy): defaults idénticos al
                // comportamiento previo cuando no hay nada guardado.
                'hold_value' => (int) ($settings['hold_value'] ?? 30),
                'hold_unit' => $settings['hold_unit'] ?? 'minute',
                'transfer_valid_value' => (int) ($settings['transfer_valid_value'] ?? 24),
                'transfer_valid_unit' => $settings['transfer_valid_unit'] ?? 'hour',
                'balance_due_enabled' => (bool) ($settings['balance_due_enabled'] ?? true),
                'balance_due_value' => (int) ($settings['balance_due_value'] ?? 5),
                'balance_due_unit' => $settings['balance_due_unit'] ?? 'day',
                'direct_notify_channel' => $settings['direct_notify_channel'] ?? 'auto',
                'arrival_reminder_enabled' => (bool) ($settings['arrival_reminder_enabled'] ?? true),
            ],
            // Qué canales de WhatsApp existen de verdad, para que el selector
            // de avisos directos avise si eliges uno que no está conectado.
            'notifyChannels' => [
                'meta_whatsapp' => \App\Models\Central\MetaChannelLink::query()
                    ->where('tenant_id', tenant('id'))->where('type', 'whatsapp')->where('active', true)->exists(),
                'evolution' => \App\Models\Central\EvolutionChannelLink::query()
                    ->where('tenant_id', tenant('id'))->where('active', true)->exists(),
            ],
            'gateways' => $gateways,
            'gatewayProviders' => PaymentGatewayLink::PROVIDERS,
            'enabledMethods' => app(PaymentMethodGate::class)->methodsFor((string) tenant('id')),
            // Sin el módulo cobros las rutas de pasarelas devuelven 403: la
            // UI lo dice de frente en vez de dejar que el guardado truene.
            'hasCobrosModule' => (bool) tenant()?->hasModule('cobros'),
            'hasMotorWebModule' => (bool) tenant()?->hasModule('motor-web'),
            'maxGateways' => tenant()->planLimit('max_gateways'),
            // Diagnóstico del wizard: con modo "automático" y ninguna tarifa
            // activa con anticipo, el paso de pago jamás aparece en /reservar
            // aunque haya pasarela conectada — la causa #1 de "no se ven".
            'ratePlansWithDeposit' => RatePlan::query()
                ->where('active', true)
                ->whereNotNull('deposit_percent')
                ->where('deposit_percent', '>', 0)
                ->count(),
            'activeRatePlans' => RatePlan::query()->where('active', true)->count(),
        ]);
    }
}
