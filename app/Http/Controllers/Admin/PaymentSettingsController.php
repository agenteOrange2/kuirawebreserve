<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Central\PaymentGatewayLink;
use App\Models\Tenant;
use App\Services\Payments\PaymentMethodGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Control de plataforma sobre los métodos de cobro: interruptores globales
 * (un método apagado aquí desaparece para TODOS los hoteles) y radiografía
 * de las pasarelas conectadas por hotel. El override por hotel vive en el
 * detalle del tenant (updateTenant). Incluye "cash" (pago en el hotel):
 * apagarlo aquí quita la opción de apartar sin pagar en línea en todos los
 * wizards, aunque el hotel la tenga activada.
 */
class PaymentSettingsController extends Controller
{
    public function __construct(protected PaymentMethodGate $gate) {}

    public function index(): Response
    {
        $tenants = Tenant::query()->get()->keyBy('id');

        return Inertia::render('admin/payments/Index', [
            'methods' => collect(PaymentMethodGate::METHODS)->map(fn ($label, $method) => [
                'method' => $method,
                'label' => $label,
                'enabled' => $this->gate->platformEnabled($method),
            ])->values(),
            // Radiografía: qué hotel tiene qué pasarela conectada y su latido.
            'gateways' => PaymentGatewayLink::query()->orderBy('tenant_id')->get()->map(fn (PaymentGatewayLink $link) => [
                'id' => $link->id,
                'tenant_id' => $link->tenant_id,
                'tenant_name' => $tenants->get($link->tenant_id)?->name ?? $link->tenant_id,
                'provider' => $link->provider,
                'provider_label' => $link->providerLabel(),
                'mode' => $link->mode,
                'active' => $link->active,
                'last_event_at' => $link->last_event_at?->diffForHumans(short: true),
            ]),
        ]);
    }

    /** Interruptor GLOBAL de un método (tenant_id null). */
    public function updateMethod(Request $request): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(PaymentMethodGate::METHODS))],
            'enabled' => ['required', 'boolean'],
        ]);

        $this->gate->set(null, $data['method'], $data['enabled']);

        return response()->json(['ok' => true]);
    }

    /** Override por hotel (toggles del detalle del tenant). */
    public function updateTenant(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(PaymentMethodGate::METHODS))],
            'enabled' => ['required', 'boolean'],
        ]);

        $this->gate->set($tenant->id, $data['method'], $data['enabled']);

        return response()->json(['ok' => true]);
    }
}
