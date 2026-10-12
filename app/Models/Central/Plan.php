<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Builder;

/**
 * Plan de la plataforma (DB central). La forma canónica de consumirlos en
 * el resto del código sigue siendo config('plans') — AppServiceProvider
 * la hidrata desde esta tabla en cada request.
 */
class Plan extends CentralModel
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Periodos de pago por adelantado que se pueden ofrecer además del mes,
     * en meses => nombre. El mensual va siempre y sin descuento.
     */
    public const PERIODS = [
        3 => 'Trimestral',
        6 => 'Semestral',
        12 => 'Anual',
        24 => 'Dos años',
        36 => 'Tres años',
    ];

    /** Tope del descuento por tiempo: más que esto ya es regalar el plan. */
    public const MAX_DISCOUNT = 90;

    protected $fillable = [
        'key',
        'label',
        'description',
        'price_monthly',
        'activation_fee',
        'billing_periods',
        'max_properties',
        'max_rooms',
        'max_users',
        'max_channels',
        'max_gateways',
        'modules',
        'ai_enabled',
        'ai_monthly_replies',
        'active',
        'public',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'billing_periods' => 'array',
            'ai_enabled' => 'boolean',
            'active' => 'boolean',
            'public' => 'boolean',
        ];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('key');
    }

    /**
     * Planes que se anuncian solos: los que salen en la página de inicio y
     * los únicos que el formulario de prospectos acepta. Un plan a la medida
     * (public = false) se asigna a mano desde /admin y nadie más lo ve.
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('active', true)->where('public', true);
    }

    /**
     * Mensual + los periodos por adelantado que ofrece el plan, con lo que
     * cuesta cada uno: total del periodo y su equivalente al mes. Es la
     * única cuenta del descuento; admin y página de inicio la leen de aquí.
     *
     * @return list<array{months: int, label: string, discount: int, total: int, monthly: int, saving: int}>
     */
    public function billingOptions(): array
    {
        $price = (int) $this->price_monthly;
        $options = [['months' => 1, 'label' => 'Mensual', 'discount' => 0, 'total' => $price, 'monthly' => $price, 'saving' => 0]];

        foreach (self::normalizePeriods($this->billing_periods ?? []) as $period) {
            $full = $price * $period['months'];
            $total = (int) round($full * (100 - $period['discount']) / 100);

            $options[] = [
                'months' => $period['months'],
                'label' => self::PERIODS[$period['months']],
                'discount' => $period['discount'],
                'total' => $total,
                'monthly' => (int) round($total / $period['months']),
                'saving' => $full - $total,
            ];
        }

        return $options;
    }

    /**
     * Deja la lista limpia: solo periodos conocidos, uno por duración,
     * descuento entero entre 0 y el tope, y ordenados de corto a largo.
     *
     * @param  array<int, mixed>  $periods
     * @return list<array{months: int, discount: int}>
     */
    public static function normalizePeriods(array $periods): array
    {
        return collect($periods)
            ->filter(fn ($p) => is_array($p) && isset(self::PERIODS[(int) ($p['months'] ?? 0)]))
            ->map(fn (array $p) => [
                'months' => (int) $p['months'],
                'discount' => max(0, min(self::MAX_DISCOUNT, (int) ($p['discount'] ?? 0))),
            ])
            ->unique('months')
            ->sortBy('months')
            ->values()
            ->all();
    }

    /** "Semestral −10 %, Anual −20 %" para la bitácora; "Solo mensual" si no hay. */
    public static function periodsSummary(?array $periods): string
    {
        $periods = self::normalizePeriods($periods ?? []);

        return $periods
            ? collect($periods)->map(fn (array $p) => self::PERIODS[$p['months']].($p['discount'] ? " −{$p['discount']} %" : ''))->implode(', ')
            : 'Solo mensual';
    }

    /** La forma que espera config('plans.{key}') en todo el código. */
    public function toConfigArray(): array
    {
        // La verdad de los módulos es la columna `modules`; ai_enabled queda
        // como columna espejo un ciclo por compatibilidad (se sincroniza al
        // guardar en PlanController). Si la migración aún no corrió
        // (modules null), la columna vieja manda.
        $modules = $this->modules ?? ($this->ai_enabled ? ['pos', 'agente-ia'] : ['pos']);

        return [
            'label' => $this->label,
            'price_monthly' => (int) $this->price_monthly,
            'activation_fee' => (int) $this->activation_fee,
            'billing_options' => $this->billingOptions(),
            'max_properties' => $this->max_properties !== null ? (int) $this->max_properties : null,
            'max_rooms' => $this->max_rooms !== null ? (int) $this->max_rooms : null,
            'max_users' => $this->max_users !== null ? (int) $this->max_users : null,
            'max_channels' => $this->max_channels !== null ? (int) $this->max_channels : null,
            'max_gateways' => $this->max_gateways !== null ? (int) $this->max_gateways : null,
            'modules' => array_values($modules),
            'ai' => [
                'enabled' => in_array('agente-ia', $modules, true),
                'monthly_replies' => $this->ai_monthly_replies !== null ? (int) $this->ai_monthly_replies : null,
            ],
            'active' => $this->active,
            'public' => $this->public,
        ];
    }
}
