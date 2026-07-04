<?php

namespace App\Models\Central;

/**
 * Consumo diario de IA por tenant (DB central): base de costos, cuotas
 * y costo-beneficio de la plataforma.
 */
class TenantAiUsage extends CentralModel
{
    protected $table = 'tenant_ai_usage';

    protected $fillable = [
        'tenant_id',
        'date',
        'replies',
        'prompt_tokens',
        'completion_tokens',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    /** Respuestas consumidas por el tenant en el mes en curso. */
    public static function repliesThisMonth(string $tenantId): int
    {
        return (int) self::query()
            ->where('tenant_id', $tenantId)
            ->where('date', '>=', now()->startOfMonth()->toDateString())
            ->sum('replies');
    }

    /** Incrementa el rollup del día (upsert atómico). */
    public static function record(string $tenantId, int $promptTokens = 0, int $completionTokens = 0): void
    {
        $row = self::firstOrCreate(['tenant_id' => $tenantId, 'date' => now()->toDateString()]);

        $row->increment('replies');
        if ($promptTokens > 0) {
            $row->increment('prompt_tokens', $promptTokens);
        }
        if ($completionTokens > 0) {
            $row->increment('completion_tokens', $completionTokens);
        }
    }
}
