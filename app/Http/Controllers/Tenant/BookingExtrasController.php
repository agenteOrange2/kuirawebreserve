<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\PaymentGatewayLink;
use App\Models\Product;
use App\Models\Property;
use App\Services\Payments\PaymentMethodGate;
use Illuminate\Http\JsonResponse;

/**
 * Piezas auxiliares del wizard público (spec: área aislada /ajustes/wizard):
 * catálogo de productos curados para pedir como extras, y qué métodos de
 * pago existen de verdad ANTES de comprometerse a uno (para que el huésped
 * elija cuando hay más de uno, en vez de que el sistema decida por él).
 */
class BookingExtrasController extends Controller
{
    /**
     * Lo que el paso "Extras" del wizard puede ofrecer, de DOS módulos
     * distintos que no se mezclan (spec-motor-reservas-web §12):
     *
     * - `products`: productos reales del POS (módulo pos + interruptor
     *   wizard_extras_enabled + curaduría por producto) — llevan stock.
     * - `addons`: add-ons del módulo `extras` (decoración, desayuno, late
     *   checkout) — sin stock ni calendario, puro cargo a la reserva.
     *
     * El paso existe si hay CUALQUIERA de los dos; vacíos ambos, el
     * frontend simplemente no lo muestra.
     */
    public function products(): JsonResponse
    {
        $settings = Property::firstOrFail()->settings ?? [];

        $products = collect();

        if (tenant()?->hasModule('pos') && ! empty($settings['wizard_extras_enabled'])) {
            $products = Product::query()
                ->where('active', true)
                ->where('available_in_wizard', true)
                // Fuera de stock no se ofrece: nada más frustrante que elegir
                // algo que no hay. Los que no llevan control de stock siempre
                // se muestran (p. ej. un refresco de máquina externa).
                ->where(fn ($q) => $q->where('track_stock', false)->orWhere('stock_qty', '>', 0))
                ->orderBy('category')
                ->orderBy('name')
                ->get(['id', 'name', 'category', 'unit', 'price'])
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'category' => $p->category,
                    'unit' => $p->unit,
                    'price' => (float) $p->price,
                ]);
        }

        $addons = tenant()?->hasModule('extras')
            ? \App\Models\Extra::query()
                ->where('active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (\App\Models\Extra $extra) => [
                    'id' => $extra->id,
                    'name' => $extra->name,
                    'description' => $extra->description,
                    'price' => (float) $extra->price,
                ])
            : collect();

        return response()->json([
            'enabled' => $products->isNotEmpty() || $addons->isNotEmpty(),
            'products' => $products->values(),
            'addons' => $addons->values(),
        ]);
    }

    /**
     * Experiencias con sesiones reservables durante la estancia — el paso
     * Extras del wizard las ofrece como plus de la reserva (módulo
     * `experiencias`). Solo sesiones con cupo dentro de la ventana
     * [llegada, salida]; el precio que se muestra es el que cobrará
     * CreateReservation (servidor, congelado).
     */
    public function experiences(\Illuminate\Http\Request $request): JsonResponse
    {
        if (! tenant()?->hasModule('experiencias')) {
            return response()->json(['enabled' => false, 'experiences' => []]);
        }

        $data = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['nullable', 'date'],
        ]);

        $from = \Carbon\Carbon::parse($data['start'])->startOfDay();
        $from = $from->isPast() ? now() : $from;
        $to = \Carbon\Carbon::parse($data['end'] ?? $data['start'])->endOfDay();

        if ($to->lessThanOrEqualTo($from)) {
            return response()->json(['enabled' => false, 'experiences' => []]);
        }

        $sessions = \App\Models\ExperienceSession::query()
            ->where('status', \App\Models\ExperienceSession::STATUS_SCHEDULED)
            ->whereBetween('starts_at', [$from, $to])
            ->orderBy('starts_at')
            ->withSum(['bookings as people_booked' => fn ($q) => $q->whereIn('status', [
                \App\Models\ExperienceBooking::STATUS_PENDING, \App\Models\ExperienceBooking::STATUS_CONFIRMED,
            ])], 'people')
            ->get()
            ->groupBy('experience_id');

        $experiences = \App\Models\Experience::query()
            ->where('active', true)
            ->with('media')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (\App\Models\Experience $experience) use ($sessions) {
                $own = ($sessions->get($experience->id) ?? collect())
                    ->map(fn (\App\Models\ExperienceSession $session) => [
                        'id' => $session->id,
                        'starts_at' => $session->starts_at->toIso8601String(),
                        'remaining' => max(0, $session->capacity - (int) ($session->people_booked ?? 0)),
                    ])
                    ->filter(fn (array $session) => $session['remaining'] > 0)
                    ->values();

                return [
                    'id' => $experience->id,
                    'name' => $experience->name,
                    'description' => $experience->description,
                    'duration_label' => $experience->durationLabel(),
                    'pricing_mode' => $experience->pricing_mode,
                    'price' => (float) $experience->price,
                    'price_label' => $experience->priceLabel(),
                    'min_people' => $experience->min_people,
                    'max_people' => $experience->max_people,
                    'photos' => $experience->photosPayload(),
                    'sessions' => $own,
                ];
            })
            ->filter(fn (array $experience) => count($experience['sessions']) > 0)
            ->values();

        return response()->json([
            'enabled' => $experiences->isNotEmpty(),
            'experiences' => $experiences,
        ]);
    }

    /**
     * Qué métodos de cobro en línea existen de verdad para este hotel,
     * consultado ANTES de crear ninguna solicitud de cobro — así el paso
     * de pago puede ofrecer elegir cuando hay más de uno, en vez de que
     * el backend decida en silencio.
     *
     * spec-reservas-avanzado §1.4: un hotel Pro puede tener hasta 3
     * pasarelas conectadas y activas A LA VEZ (Stripe, PayPal,
     * MercadoPago); antes esto solo reportaba "hay pasarela sí/no" y
     * `payment()` tomaba la primera por id sin dejar elegir CUÁL. Ahora se
     * listan todas para que el huésped elija por nombre.
     */
    public function paymentOptions(): JsonResponse
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
        $links = PaymentGatewayLink::query()
            ->where('tenant_id', (string) tenant('id'))
            ->where('active', true)
            ->whereIn('provider', $enabledProviders)
            ->orderBy('id')
            ->get();

        return response()->json([
            'gateways' => $links->map(fn (PaymentGatewayLink $link) => [
                'provider' => $link->provider,
                'label' => $link->providerLabel(),
            ])->values(),
            'transfer' => [
                'available' => $accountsCount > 0,
                'accounts_count' => $accountsCount,
            ],
        ]);
    }
}
