<?php

declare(strict_types=1);

namespace App\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * Tenant = un hotel/motel de la plataforma. Vive en la DB central;
 * sus datos operativos (habitaciones, reservas, etc.) viven en su propia DB.
 *
 * Los atributos que no son columnas se guardan en la columna JSON `data`
 * (comportamiento de stancl/tenancy v3).
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    /**
     * Overrides de módulos (tenant_modules) memoizados por request.
     *
     * @var array<string, bool>|null
     */
    protected ?array $resolvedModuleOverrides = null;

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'plan',
            'suspended_at',
        ];
    }

    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
        ];
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /** @return array<string, mixed> */
    public function planLimits(): array
    {
        return config("plans.{$this->plan}") ?? config('plans.basic');
    }

    public function planLimit(string $key): ?int
    {
        return $this->planLimits()[$key] ?? null;
    }

    /**
     * ¿El hotel tiene este módulo? Único punto de verdad (spec-plan-maestro
     * E1): override del admin (tenant_modules) ?? incluido en el plan ?? no.
     */
    public function hasModule(string $key): bool
    {
        $override = $this->moduleOverrides()[$key] ?? null;

        if ($override !== null) {
            return $override;
        }

        return in_array($key, $this->planLimits()['modules'] ?? [], true);
    }

    /**
     * Keys de módulos activos para este hotel, en el orden del catálogo
     * (config/modules.php). Es lo que se comparte al frontend para el menú.
     *
     * @return list<string>
     */
    public function enabledModules(): array
    {
        return array_values(array_filter(
            array_keys(config('modules', [])),
            fn (string $key) => $this->hasModule($key),
        ));
    }

    /** @return array<string, bool> */
    protected function moduleOverrides(): array
    {
        return $this->resolvedModuleOverrides ??= \App\Models\Central\TenantModule::query()
            ->where('tenant_id', $this->id)
            ->pluck('enabled', 'module')
            ->map(fn ($enabled) => (bool) $enabled)
            ->all();
    }
}
