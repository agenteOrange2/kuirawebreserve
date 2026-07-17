<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\PaymentGatewayLink;
use App\Models\Product;
use App\Models\Property;
use App\Services\Payments\PaymentMethodGate;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Área AISLADA de configuración del wizard público (spec-motor-reservas-web
 * E0, ampliación): todo lo que decide cómo se comporta /reservar vive
 * aquí, no disperso en Ajustes general. Tres bloques:
 *
 * 1. Modalidad y huéspedes (guest_policy, block_mode_label) — quién ve el
 *    wizard y con qué reglas de personas.
 * 2. Extras (POS) — paso opcional para pedir productos del inventario
 *    durante la reserva; solo existe si el módulo `pos` está activo.
 * 3. Pago — resumen de qué métodos están listos (no duplica su
 *    configuración, que vive en /ajustes/metodos-pago; esta pantalla
 *    solo informa y enlaza).
 */
class WizardSettingsPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];
        $hasPos = (bool) tenant()?->hasModule('pos');

        $products = $hasPos
            ? Product::query()
                ->where('active', true)
                ->orderBy('category')
                ->orderBy('name')
                ->get(['id', 'name', 'category', 'unit', 'price', 'available_in_wizard', 'track_stock', 'stock_qty'])
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'category' => $p->category,
                    'unit' => $p->unit,
                    'price' => (float) $p->price,
                    'available_in_wizard' => $p->available_in_wizard,
                    // Mismo criterio que BookingExtrasController::products(): un
                    // producto marcado "visible" pero sin existencias NO aparece
                    // en el wizard real — sin este dato el admin lo activa, lo ve
                    // en la lista y no entiende por qué el huésped nunca lo ve.
                    'in_stock' => ! $p->track_stock || (float) $p->stock_qty > 0,
                ])
            : collect();

        return Inertia::render('tenant/settings/Wizard', [
            'property' => $property->only(['id', 'name']),
            'wizardUrl' => "https://{$request->getHost()}/reservar",
            'settings' => [
                'guest_policy' => $settings['guest_policy'] ?? 'family',
                'block_mode_label' => $settings['block_mode_label'] ?? 'Por rato/periodo',
                'wizard_extras_enabled' => (bool) ($settings['wizard_extras_enabled'] ?? false),
                'payment_mode' => $settings['payment_mode'] ?? 'automatic',
            ],
            'hasPosModule' => $hasPos,
            'products' => $products,
            'paymentReadiness' => $this->paymentReadiness(),
            'canManage' => $request->user()->can('properties.manage'),
        ]);
    }

    /** @return array<string, mixed> */
    protected function paymentReadiness(): array
    {
        $gate = app(PaymentMethodGate::class);
        $enabled = $gate->methodsFor((string) tenant('id'));
        $settings = Property::firstOrFail()->settings ?? [];

        $accountsCount = ! $enabled['transfer'] ? 0 : collect($settings['bank_accounts'] ?? [])
            ->filter(fn (array $a) => ! empty($a['active']))
            ->count();

        $enabledProviders = array_keys(array_filter([
            'stripe' => $enabled['stripe'],
            'mercadopago' => $enabled['mercadopago'],
            'paypal' => $enabled['paypal'],
        ]));
        $gatewayLink = PaymentGatewayLink::query()
            ->where('tenant_id', (string) tenant('id'))
            ->where('active', true)
            ->whereIn('provider', $enabledProviders)
            ->first();

        return [
            'gateway_connected' => $gatewayLink !== null,
            'gateway_provider' => $gatewayLink?->providerLabel(),
            'transfer_accounts_count' => $accountsCount,
            'ready' => $gatewayLink !== null || $accountsCount > 0,
        ];
    }
}
