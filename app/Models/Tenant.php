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
}
