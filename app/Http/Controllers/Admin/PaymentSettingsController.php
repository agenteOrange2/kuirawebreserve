<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Central\PaymentGatewayLink;
use App\Models\Central\PaymentMethodSetting;
use App\Models\Tenant;
use App\Services\Payments\PaymentMethodGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Control de plataforma sobre los métodos de cobro: interruptores globales
 * (un método apagado aquí desaparece para TODOS los hoteles) y radiografía
 * por hotel de con qué cobra de verdad. El override por hotel vive en el
 * detalle del tenant (updateTenant). Incluye "cash" (pago en el hotel):
 * apagarlo aquí quita la opción de apartar sin pagar en línea en todos los
 * wizards, aunque el hotel la tenga activada.
 */
class PaymentSettingsController extends Controller
{
    /** Pasarela activa sin eventos en más días que esto = sin latido. */
    public const STALE_DAYS = 30;

    public function __construct(protected PaymentMethodGate $gate) {}

    public function index(): Response
    {
        $tenants = Tenant::query()->orderBy('name')->get();
        $tenantIds = $tenants->pluck('id')->all();

        $links = PaymentGatewayLink::query()->orderBy('id')->get();
        $overrides = PaymentMethodSetting::query()
            ->whereNotNull('tenant_id')
            ->get()
            ->groupBy('tenant_id');

        $rows = $tenants->map(fn (Tenant $tenant) => $this->tenantRow(
            $tenant,
            $links->where('tenant_id', $tenant->id)->values(),
            $overrides->get($tenant->id, collect()),
        ));

        return Inertia::render('admin/payments/Index', [
            'methods' => collect(PaymentMethodGate::METHODS)->map(fn ($label, $method) => [
                'method' => $method,
                'label' => $label,
                'enabled' => $this->gate->platformEnabled($method),
                // A quién le pega apagarlo: hoteles que hoy lo tienen
                // encendido y, si es pasarela, cuántos cobran con ella.
                'tenants_enabled' => $rows->filter(fn ($row) => $row['methods'][$method]['enabled'])->count(),
                'charging' => $rows->filter(fn ($row) => ($row['charging']['provider'] ?? null) === $method)
                    ->map(fn ($row) => $row['name'])->values(),
            ])->values(),
            'tenants' => $rows->values(),
            // Pasarelas de hoteles que ya no existen: basura que nadie usa,
            // pero conserva llaves cifradas.
            'orphans' => $links->whereNotIn('tenant_id', $tenantIds)->map(fn (PaymentGatewayLink $link) => [
                'id' => $link->id,
                'tenant_id' => $link->tenant_id,
                'provider_label' => $link->providerLabel(),
                'mode' => $link->mode,
                'last_event_at' => $link->last_event_at?->diffForHumans(),
            ])->values(),
            'staleDays' => self::STALE_DAYS,
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

    /**
     * Quita una pasarela cuyo hotel ya no existe. Solo huérfanas: las de un
     * hotel vivo se gestionan en su panel o en su ficha.
     */
    public function destroyOrphan(PaymentGatewayLink $paymentGatewayLink): JsonResponse
    {
        abort_if(
            Tenant::query()->whereKey($paymentGatewayLink->tenant_id)->exists(),
            422,
            'Esa pasarela pertenece a un hotel activo; se quita desde su ficha.',
        );

        $paymentGatewayLink->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @param  Collection<int, PaymentGatewayLink>  $links
     * @param  Collection<int, PaymentMethodSetting>  $overrides
     * @return array<string, mixed>
     */
    private function tenantRow(Tenant $tenant, Collection $links, Collection $overrides): array
    {
        $own = $overrides->mapWithKeys(fn (PaymentMethodSetting $s) => [$s->method => (bool) $s->enabled]);
        $charging = $this->gate->activeGatewayLink($tenant->id);

        return [
            'id' => $tenant->id,
            'name' => $tenant->name ?? $tenant->id,
            'suspended' => $tenant->isSuspended(),
            'methods' => collect(PaymentMethodGate::METHODS)->mapWithKeys(fn ($label, $method) => [$method => [
                'enabled' => $this->gate->enabledFor($tenant->id, $method),
                'platform' => $this->gate->platformEnabled($method),
                // null = sigue a la plataforma; true/false = el hotel lo fijó.
                'own' => $own->get($method),
            ]]),
            'has_overrides' => $own->contains(false),
            // La que usa el botón "Generar link de pago" ahora mismo.
            'charging' => $charging ? [
                'id' => $charging->id,
                'provider' => $charging->provider,
                'provider_label' => $charging->providerLabel(),
                'mode' => $charging->mode,
            ] : null,
            'gateways' => $links->map(fn (PaymentGatewayLink $link) => [
                'id' => $link->id,
                'provider' => $link->provider,
                'provider_label' => $link->providerLabel(),
                'mode' => $link->mode,
                'active' => (bool) $link->active,
                'in_use' => $charging?->id === $link->id,
                // Conectada pero sin poder cobrar: su método está apagado.
                'blocked_by' => ! $this->gate->platformEnabled($link->provider)
                    ? 'platform'
                    : (! $this->gate->enabledFor($tenant->id, $link->provider) ? 'tenant' : null),
                'last_event_at' => $link->last_event_at?->diffForHumans(),
                'stale' => $link->active && ($link->last_event_at === null
                    || $link->last_event_at->lt(now()->subDays(self::STALE_DAYS))),
            ])->values(),
        ];
    }
}
