<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * Unidad del periodo de una tarifa (spec-profundidad §2.6.1) y de la
 * antelación mínima (§2.6.2 — solo hour/day/week aplican ahí).
 */
enum RateDurationUnit: string
{
    case Minute = 'minute';
    case Hour = 'hour';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /** Unidades válidas para antelación mínima. */
    public static function advanceUnits(): array
    {
        return [self::Hour, self::Day, self::Week];
    }

    /**
     * Minutos exactos de la unidad; null para month (es calendario).
     */
    public function minutes(): ?int
    {
        return match ($this) {
            self::Minute => 1,
            self::Hour => 60,
            self::Day => 1440,
            self::Week => 10080,
            self::Month => null,
        };
    }

    public function addTo(CarbonInterface $moment, int $value): CarbonInterface
    {
        return match ($this) {
            self::Minute => $moment->copy()->addMinutes($value),
            self::Hour => $moment->copy()->addHours($value),
            self::Day => $moment->copy()->addDays($value),
            self::Week => $moment->copy()->addWeeks($value),
            self::Month => $moment->copy()->addMonths($value),
        };
    }

    public function subtractFrom(CarbonInterface $moment, int $value): CarbonInterface
    {
        return match ($this) {
            self::Minute => $moment->copy()->subMinutes($value),
            self::Hour => $moment->copy()->subHours($value),
            self::Day => $moment->copy()->subDays($value),
            self::Week => $moment->copy()->subWeeks($value),
            self::Month => $moment->copy()->subMonths($value),
        };
    }

    public function label(int $value): string
    {
        $plural = $value !== 1;

        return $value.' '.match ($this) {
            self::Minute => $plural ? 'minutos' : 'minuto',
            self::Hour => $plural ? 'horas' : 'hora',
            self::Day => $plural ? 'días' : 'día',
            self::Week => $plural ? 'semanas' : 'semana',
            self::Month => $plural ? 'meses' : 'mes',
        };
    }
}
