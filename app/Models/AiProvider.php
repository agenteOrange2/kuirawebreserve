<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Proveedor LLM configurado por el hotel (multitenant): su propia API key,
 * modelo y orden en la cadena de fallback. Kimi y MiniMax entran por el
 * driver openai (API compatible) con URL propia.
 */
class AiProvider extends Model
{
    /**
     * Catálogo soportado: driver de Prism + URL + modelos sugeridos por
     * nivel (new = lo más reciente, mid = equilibrio, cheap = económico).
     * El primero de cada lista es el default al elegir el proveedor.
     */
    public const CATALOG = [
        'anthropic' => [
            'label' => 'Anthropic · Claude',
            'driver' => 'anthropic',
            'url' => null,
            'placeholder_model' => 'claude-sonnet-5',
            'key_hint' => 'sk-ant-…',
            'models' => [
                ['id' => 'claude-sonnet-5', 'tier' => 'new'],
                ['id' => 'claude-opus-4-8', 'tier' => 'new'],
                ['id' => 'claude-sonnet-4-5', 'tier' => 'mid'],
                ['id' => 'claude-sonnet-4-0', 'tier' => 'mid'],
                ['id' => 'claude-haiku-4-5', 'tier' => 'cheap'],
                ['id' => 'claude-3-5-haiku-latest', 'tier' => 'cheap'],
            ],
        ],
        'openai' => [
            'label' => 'OpenAI · ChatGPT',
            'driver' => 'openai',
            'url' => null,
            'placeholder_model' => 'gpt-5-mini',
            'key_hint' => 'sk-…',
            'models' => [
                ['id' => 'gpt-5.1', 'tier' => 'new'],
                ['id' => 'gpt-5', 'tier' => 'new'],
                ['id' => 'gpt-5-mini', 'tier' => 'mid'],
                ['id' => 'gpt-4.1', 'tier' => 'mid'],
                ['id' => 'gpt-5-nano', 'tier' => 'cheap'],
                ['id' => 'gpt-4.1-mini', 'tier' => 'cheap'],
            ],
        ],
        'deepseek' => [
            'label' => 'DeepSeek',
            'driver' => 'deepseek',
            'url' => null,
            'placeholder_model' => 'deepseek-chat',
            'key_hint' => 'sk-…',
            // DeepSeek solo expone estos dos alias en su API (ya es el
            // proveedor económico de la lista).
            'models' => [
                ['id' => 'deepseek-chat', 'tier' => 'new'],
                ['id' => 'deepseek-reasoner', 'tier' => 'mid'],
            ],
        ],
        'kimi' => [
            'label' => 'Kimi · Moonshot',
            'driver' => 'openai',
            'url' => 'https://api.moonshot.ai/v1',
            'placeholder_model' => 'kimi-k2-turbo-preview',
            'key_hint' => 'sk-…',
            'models' => [
                ['id' => 'kimi-k2-turbo-preview', 'tier' => 'new'],
                ['id' => 'kimi-k2-thinking', 'tier' => 'new'],
                ['id' => 'kimi-k2-0905-preview', 'tier' => 'mid'],
                ['id' => 'kimi-latest', 'tier' => 'mid'],
                ['id' => 'moonshot-v1-32k', 'tier' => 'cheap'],
                ['id' => 'moonshot-v1-8k', 'tier' => 'cheap'],
            ],
        ],
        'minimax' => [
            'label' => 'MiniMax',
            'driver' => 'openai',
            'url' => 'https://api.minimax.io/v1',
            'placeholder_model' => 'MiniMax-M2',
            'key_hint' => 'eyJ…',
            'models' => [
                ['id' => 'MiniMax-M2', 'tier' => 'new'],
                ['id' => 'MiniMax-Text-01', 'tier' => 'mid'],
                ['id' => 'abab6.5s-chat', 'tier' => 'cheap'],
            ],
        ],
    ];

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
        return self::CATALOG[$this->provider]['label'] ?? $this->provider;
    }

    public function driver(): string
    {
        return self::CATALOG[$this->provider]['driver'] ?? 'openai';
    }

    public function baseUrl(): ?string
    {
        return self::CATALOG[$this->provider]['url'] ?? null;
    }

    /** Últimos 4 caracteres para mostrar sin exponer la key. */
    public function maskedKey(): string
    {
        $key = (string) $this->api_key;

        return $key === '' ? '—' : '••••'.substr($key, -4);
    }
}
