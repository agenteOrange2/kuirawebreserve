<?php

namespace App\Models\Central;

use App\Models\AiProvider;
use Illuminate\Database\Eloquent\Builder;

/**
 * Proveedor LLM de PLATAFORMA (keys maestras, DB central). Reutiliza el
 * catálogo del modelo tenant para drivers/URLs/etiquetas.
 */
class PlatformAiProvider extends CentralModel
{
    protected $table = 'platform_ai_providers';

    protected $fillable = [
        'provider',
        'model',
        'api_key',
        'active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function label(): string
    {
        return AiProvider::CATALOG[$this->provider]['label'] ?? $this->provider;
    }

    public function maskedKey(): string
    {
        $key = (string) $this->api_key;

        return $key === '' ? '—' : '••••'.substr($key, -4);
    }

    /**
     * Instancia transitoria del modelo tenant (no se guarda): así el
     * AgentBrain usa la misma interfaz para BYOK y plataforma.
     */
    public function asRuntimeProvider(): AiProvider
    {
        $runtime = new AiProvider([
            'provider' => $this->provider,
            'model' => $this->model,
            'api_key' => $this->api_key,
            'active' => true,
        ]);
        $runtime->platform = true; // marca para cuota/rollup

        return $runtime;
    }
}
