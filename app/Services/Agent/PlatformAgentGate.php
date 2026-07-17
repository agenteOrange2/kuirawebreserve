<?php

namespace App\Services\Agent;

use App\Models\Central\PlatformAiProvider;
use App\Models\Central\TenantAgentSetting;
use App\Models\Central\TenantAiUsage;
use Illuminate\Support\Collection;

/**
 * Decide, desde la DB CENTRAL, si el tenant actual tiene IA de plataforma:
 * plan que la incluya + habilitado por el super-admin + cuota disponible.
 * Devuelve la cadena de proveedores de plataforma (asignado o automática).
 */
class PlatformAgentGate
{
    /**
     * @return array{
     *   plan_enabled: bool, enabled: bool, byok_allowed: bool,
     *   limit: int|null, used: int, blocked_reason: string|null,
     *   chain: Collection<int, \App\Models\AiProvider>,
     *   provider_id: int|null, plan_label: string
     * }
     */
    public function status(): array
    {
        $tenant = tenant();

        if (! $tenant) {
            return $this->blocked('Sin tenant.', planEnabled: false);
        }

        // El módulo agente-ia decide si el plan/hotel tiene IA (incluye
        // overrides del admin en tenant_modules); la cuota mensual sigue
        // saliendo del plan.
        $planAi = config("plans.{$tenant->plan}.ai", ['enabled' => false, 'monthly_replies' => 0]);
        $moduleEnabled = $tenant->hasModule('agente-ia');
        $settings = TenantAgentSetting::for($tenant->id);
        $planLabel = $tenant->planLimits()['label'] ?? $tenant->plan;

        $base = [
            'plan_enabled' => $moduleEnabled,
            'enabled' => $settings->enabled,
            'byok_allowed' => $settings->byok_allowed,
            'api_allowed' => $settings->api_allowed,
            'provider_id' => $settings->platform_ai_provider_id,
            'plan_label' => $planLabel,
        ];

        if (! $moduleEnabled) {
            return $base + ['limit' => 0, 'used' => 0, 'blocked_reason' => 'plan', 'chain' => collect()];
        }

        if (! $settings->enabled) {
            return $base + ['limit' => null, 'used' => 0, 'blocked_reason' => 'disabled', 'chain' => collect()];
        }

        $limit = $settings->monthly_reply_limit ?? $planAi['monthly_replies'] ?? null;
        $used = TenantAiUsage::repliesThisMonth($tenant->id);

        if ($limit !== null && $limit > 0 && $used >= $limit) {
            return $base + ['limit' => $limit, 'used' => $used, 'blocked_reason' => 'quota', 'chain' => collect()];
        }

        // Cadena: proveedor asignado primero; si no hay asignado, todos los
        // activos en orden (fallback automático).
        $providers = PlatformAiProvider::query()->active()->orderBy('sort_order')->orderBy('id')->get();

        if ($settings->platform_ai_provider_id) {
            $assigned = $providers->firstWhere('id', $settings->platform_ai_provider_id);
            $providers = $assigned
                ? collect([$assigned])->merge($providers->where('id', '!=', $assigned->id))
                : $providers;
        }

        return $base + [
            'limit' => $limit,
            'used' => $used,
            'blocked_reason' => $providers->isEmpty() ? 'no_providers' : null,
            'chain' => $providers->map(fn (PlatformAiProvider $p) => $p->asRuntimeProvider())->values(),
        ];
    }

    /** Registra en la central una respuesta servida con keys de plataforma. */
    public function recordReply(array $meta): void
    {
        $tenant = tenant();
        if (! $tenant) {
            return;
        }

        TenantAiUsage::record(
            $tenant->id,
            (int) ($meta['prompt_tokens'] ?? 0),
            (int) ($meta['completion_tokens'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function blocked(string $reason, bool $planEnabled): array
    {
        return [
            'plan_enabled' => $planEnabled,
            'enabled' => false,
            'byok_allowed' => false,
            'api_allowed' => false,
            'provider_id' => null,
            'plan_label' => '',
            'limit' => 0,
            'used' => 0,
            'blocked_reason' => $reason,
            'chain' => collect(),
        ];
    }
}
