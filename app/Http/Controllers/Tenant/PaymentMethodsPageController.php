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
 * Área AISLADA de métodos de pago. Desde 2026-08-05 es un HUB con
 * sub-páginas: cada tema encapsulado en su propia URL en vez de una sola
 * página larga (pedido explícito del usuario):
 *
 *   /ajustes/metodos-pago                      → resumen + modo de pago del wizard
 *   /ajustes/metodos-pago/pasarela-pago        → pasarelas (llaves propias)
 *   /ajustes/metodos-pago/pagos-transferencia  → cuentas + WhatsApp comprobantes
 *   /ajustes/metodos-pago/plazos-y-saldo       → relojes por método y saldo
 *   /ajustes/metodos-pago/politicas            → cancelación, walk-ins y fianza
 *
 * El hub conserva el diagnóstico de por qué las pasarelas pueden no
 * aparecer en /reservar: tener Stripe conectada NO basta — el wizard solo
 * ofrece pago cuando la reserva lo exige (payment_mode o anticipo de la
 * tarifa). Ese "está conectada pero nunca se ve" era invisible antes.
 */
class PaymentMethodsPageController extends Controller
{
    /** Hub: estado de cobros, modo de pago del wizard y tarjetas a las sub-páginas. */
    public function index(): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];
        $accounts = array_values($settings['bank_accounts'] ?? []);
        $gateways = PaymentGatewayLink::query()
            ->where('tenant_id', tenant('id'))
            ->get();

        return Inertia::render('tenant/settings/payments/Index', [
            'property' => $property->only(['id', 'name']),
            'paymentMode' => $this->paymentMode($settings),
            'cashEnabled' => $this->cashEnabled($settings),
            'enabledMethods' => app(PaymentMethodGate::class)->methodsFor((string) tenant('id')),
            'hasCobrosModule' => (bool) tenant()?->hasModule('cobros'),
            'hasMotorWebModule' => (bool) tenant()?->hasModule('motor-web'),
            // Diagnóstico del wizard: con modo "automático" y ninguna tarifa
            // activa con anticipo, el paso de pago jamás aparece en /reservar
            // aunque haya pasarela conectada — la causa #1 de "no se ven".
            'ratePlansWithDeposit' => RatePlan::query()
                ->where('active', true)
                ->where(fn ($q) => $q
                    ->where('deposit_percent', '>', 0)
                    ->orWhere('deposit_amount', '>', 0))
                ->count(),
            'activeRatePlans' => RatePlan::query()->where('active', true)->count(),
            // Mini-resúmenes para que cada tarjeta diga qué hay adentro sin
            // tener que entrar (mismo patrón que el hub /ajustes).
            'gatewaysSummary' => [
                'connected' => $gateways->count(),
                'active' => $gateways->where('active', true)->count(),
                'test' => $gateways->where('mode', 'test')->count(),
            ],
            'transferSummary' => [
                'accounts_active' => collect($accounts)->where('active', true)->count(),
                'whatsapps' => count($this->transferWhatsapps($settings)),
            ],
            'termsSummary' => [
                'hold_value' => (int) ($settings['hold_value'] ?? 30),
                'hold_unit' => $settings['hold_unit'] ?? 'minute',
                'transfer_valid_value' => (int) ($settings['transfer_valid_value'] ?? 24),
                'transfer_valid_unit' => $settings['transfer_valid_unit'] ?? 'hour',
                'cash_deadline_value' => (int) ($settings['cash_deadline_value'] ?? 24),
                'cash_deadline_unit' => $settings['cash_deadline_unit'] ?? 'hour',
                'balance_due_enabled' => (bool) ($settings['balance_due_enabled'] ?? true),
            ],
            'policiesSummary' => [
                'cancel_policy_enabled' => (bool) ($settings['cancel_policy_enabled'] ?? false),
                'walkin_charge' => $settings['walkin_charge'] ?? 'checkout',
                'guarantee_enabled' => (bool) ($settings['guarantee_enabled'] ?? false)
                    && (float) ($settings['guarantee_amount'] ?? 0) > 0,
            ],
        ]);
    }

    /** Pasarelas de pago: llaves propias del hotel por proveedor. */
    public function gateways(): Response
    {
        return Inertia::render('tenant/settings/payments/Gateways', [
            'gateways' => PaymentGatewayLink::query()
                ->where('tenant_id', tenant('id'))
                ->get()
                ->map(fn ($link) => PaymentGatewayController::serialize($link))
                ->values(),
            'gatewayProviders' => PaymentGatewayLink::PROVIDERS,
            'enabledMethods' => app(PaymentMethodGate::class)->methodsFor((string) tenant('id')),
            // Sin el módulo cobros las rutas de pasarelas devuelven 403: la
            // UI lo dice de frente en vez de dejar que el guardado truene.
            'hasCobrosModule' => (bool) tenant()?->hasModule('cobros'),
            'maxGateways' => tenant()->planLimit('max_gateways'),
        ]);
    }

    /** Pago por transferencia: cuentas bancarias y WhatsApp para comprobantes. */
    public function transfers(): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        return Inertia::render('tenant/settings/payments/Transfers', [
            'property' => $property->only(['id', 'name']),
            'settings' => [
                'bank_accounts' => array_values($settings['bank_accounts'] ?? []),
                'transfer_whatsapps' => $this->transferWhatsapps($settings),
            ],
            'enabledMethods' => app(PaymentMethodGate::class)->methodsFor((string) tenant('id')),
        ]);
    }

    /** Plazos y saldo: el reloj de cada método y el cobro del restante. */
    public function terms(): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        return Inertia::render('tenant/settings/payments/Terms', [
            'property' => $property->only(['id', 'name']),
            'settings' => [
                // Plazos (ReservationPolicy): defaults idénticos al
                // comportamiento previo cuando no hay nada guardado.
                'hold_value' => (int) ($settings['hold_value'] ?? 30),
                'hold_unit' => $settings['hold_unit'] ?? 'minute',
                'transfer_valid_value' => (int) ($settings['transfer_valid_value'] ?? 24),
                'transfer_valid_unit' => $settings['transfer_valid_unit'] ?? 'hour',
                'cash_deadline_value' => (int) ($settings['cash_deadline_value'] ?? 24),
                'cash_deadline_unit' => $settings['cash_deadline_unit'] ?? 'hour',
                'balance_due_enabled' => (bool) ($settings['balance_due_enabled'] ?? true),
                'balance_due_value' => (int) ($settings['balance_due_value'] ?? 5),
                'balance_due_unit' => $settings['balance_due_unit'] ?? 'day',
                'balance_request_days' => (int) ($settings['balance_request_days'] ?? 3),
                'cancel_on_balance_overdue' => (bool) ($settings['cancel_on_balance_overdue'] ?? false),
                'auto_confirm_on_payment' => (bool) ($settings['auto_confirm_on_payment'] ?? true),
                'cash_payment_enabled' => $this->cashEnabled($settings),
            ],
            'enabledMethods' => app(PaymentMethodGate::class)->methodsFor((string) tenant('id')),
        ]);
    }

    /** Políticas y cobros en recepción: cancelación, walk-ins y fianza. */
    public function policies(): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        return Inertia::render('tenant/settings/payments/Policies', [
            'property' => $property->only(['id', 'name']),
            'settings' => [
                // Política de cancelación default del hotel: apagada, todo
                // sigue como siempre (sin política = decisión humana).
                'cancel_policy_enabled' => (bool) ($settings['cancel_policy_enabled'] ?? false),
                'cancel_free_value' => (int) ($settings['cancel_free_value'] ?? 2),
                'cancel_free_unit' => $settings['cancel_free_unit'] ?? 'day',
                'cancel_penalty_percent' => is_numeric($settings['cancel_penalty_percent'] ?? null)
                    ? (float) $settings['cancel_penalty_percent']
                    : 100.0,
                'cancel_policy_text' => $settings['cancel_policy_text'] ?? '',
                // Walk-ins: cuenta final al salir (default) o cobro al llegar.
                'walkin_charge' => $settings['walkin_charge'] ?? 'checkout',
                // Formas de cobro que acepta la recepción. Es lo que ofrecen
                // el plano, el POS, la salida y los abonos — NO lo mismo que
                // los métodos en línea de /admin (esos son del wizard).
                'counter_methods' => app(\App\Services\ReservationPolicy::class)->counterMethods(),
                // Fianza (depósito en garantía): se cobra al registrar la
                // llegada y se devuelve al registrar la salida. Los escalones
                // bajan el monto POR HABITACIÓN cuando el mismo grupo aparta
                // varias — se normalizan al guardar (PropertyController).
                'guarantee_enabled' => (bool) ($settings['guarantee_enabled'] ?? false),
                'guarantee_amount' => round((float) ($settings['guarantee_amount'] ?? 0), 2),
                'guarantee_tiers' => app(\App\Services\ReservationPolicy::class)->guaranteeTiers(),
            ],
            // Catálogo de formas de cobro del mostrador, con su explicación:
            // la confusión que esto resuelve es "apagué tarjeta en línea y el
            // panel sigue ofreciéndome tarjeta", así que cada una dice qué es.
            'counterMethodCatalog' => [
                ['key' => 'cash', 'label' => 'Efectivo', 'hint' => 'Billete en la caja de recepción.'],
                ['key' => 'card', 'label' => 'Tarjeta', 'hint' => 'Terminal bancaria física en el mostrador. No es el cobro con tarjeta por internet: ese depende de las pasarelas.'],
                ['key' => 'transfer', 'label' => 'Transferencia', 'hint' => 'Depósito o transferencia con el comprobante a la vista al momento de atender.'],
            ],
            // Tarifas con política de cancelación propia: mandan sobre la
            // default del hotel — la UI lo avisa para evitar sorpresas.
            'ratePlansWithCancelPolicy' => RatePlan::query()
                ->where('active', true)
                ->whereNotNull('cancel_free_unit')
                ->whereNotNull('cancel_free_value')
                ->count(),
        ]);
    }

    /**
     * 'optional' (viejo modo "ambos") se descompuso en dos piezas: modo
     * 'always' + método efectivo prendido. Aquí se normaliza para la UI;
     * el valor guardado se respeta hasta que el hotel vuelva a guardar.
     */
    private function paymentMode(array $settings): string
    {
        return ($settings['payment_mode'] ?? 'automatic') === 'optional'
            ? 'always'
            : ($settings['payment_mode'] ?? 'automatic');
    }

    private function cashEnabled(array $settings): bool
    {
        return (bool) ($settings['cash_payment_enabled']
            ?? (($settings['payment_mode'] ?? 'automatic') === 'optional'));
    }

    /**
     * Varios números con su lada; el campo viejo de uno solo se migra al
     * vuelo para no perder lo capturado.
     */
    private function transferWhatsapps(array $settings): array
    {
        return $settings['transfer_whatsapps']
            ?? (! empty($settings['transfer_whatsapp'])
                ? [['code' => $settings['phone_country_code'] ?? '52', 'number' => $settings['transfer_whatsapp']]]
                : []);
    }
}
