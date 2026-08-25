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

        // SOLO los productos ya elegidos. Antes venía el catálogo completo
        // y la pantalla pintaba un interruptor por producto: con 69 activos
        // (motellacupula) ya cansaba, y un inventario de 200 la reventaba.
        // Para AGREGAR productos está el buscador (searchProducts), que
        // consulta contra el servidor en vez de traerse todo de una vez.
        $products = $hasPos
            ? $this->serializeProducts(
                Product::query()
                    ->where('active', true)
                    ->where('available_in_wizard', true)
                    ->orderBy('category')
                    ->orderBy('name')
                    ->get()
            )
            : collect();

        return Inertia::render('tenant/settings/Wizard', [
            'property' => $property->only(['id', 'name']),
            'wizardUrl' => "https://{$request->getHost()}/reservar",
            'settings' => [
                'guest_policy' => $settings['guest_policy'] ?? 'family',
                'block_mode_label' => $settings['block_mode_label'] ?? 'Por rato/periodo',
                'wizard_extras_enabled' => (bool) ($settings['wizard_extras_enabled'] ?? false),
                // 'optional' es legacy del viejo modo "ambos": para el
                // diagnóstico equivale a 'always' (siempre hay paso de pago).
                'payment_mode' => ($settings['payment_mode'] ?? 'automatic') === 'optional'
                    ? 'always'
                    : ($settings['payment_mode'] ?? 'automatic'),
            ],
            'hasPosModule' => $hasPos,
            'products' => $products,
            'productsTotal' => $hasPos ? Product::query()->where('active', true)->count() : 0,
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
    /**
     * Buscador de productos para el selector de extras.
     *
     * Devuelve una tanda corta filtrada por nombre o categoría en lugar de
     * mandar el catálogo entero al navegador: un hotel con inventario real
     * (cientos de productos) marca apenas una docena como extras, así que
     * pintarle todo el almacén para encontrar esa docena es justo al revés.
     */
    public function searchProducts(Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless((bool) tenant()?->hasModule('pos'), 403);

        $term = trim((string) $request->query('q', ''));

        $products = Product::query()
            ->where('active', true)
            ->when($term !== '', fn ($query) => $query->where(
                fn ($q) => $q->where('name', 'like', "%{$term}%")
                    ->orWhere('category', 'like', "%{$term}%"),
            ))
            ->orderBy('category')
            ->orderBy('name')
            ->limit(40)
            ->get();

        return response()->json([
            'products' => $this->serializeProducts($products)->values(),
            // Para avisar "hay más, afina la búsqueda" en vez de mentir con
            // una lista recortada en silencio.
            'truncated' => $products->count() === 40,
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function serializeProducts(\Illuminate\Support\Collection $products): \Illuminate\Support\Collection
    {
        return $products->map(fn (Product $p) => [
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
        ]);
    }

}
