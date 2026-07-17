<?php

namespace App\Services;

use App\Enums\RateDurationUnit;
use App\Models\Property;
use App\Models\RatePlan;
use Carbon\CarbonInterface;

/**
 * Plazos de reservas y cobros configurables por hotel (settings del
 * Property, se administran en /ajustes/metodos-pago). Un solo lugar lee y
 * traduce valor+unidad a minutos/fechas; los defaults son los mismos que
 * regían cuando esto era config fija — un hotel sin ajustes guardados se
 * comporta idéntico que antes.
 */
class ReservationPolicy
{
    /** @var array<string, mixed>|null */
    protected ?array $settings = null;

    /**
     * Cuánto vive un apartado (reserva pendiente) antes de liberarse solo.
     */
    public function holdMinutes(): int
    {
        $minutes = $this->minutesFrom('hold_value', 'hold_unit');

        return $minutes ?? (int) config('reservations.hold_minutes', 30);
    }

    /**
     * Vigencia de un cobro por transferencia (hay banco de por medio; la
     * de pasarela se queda en config: la limita el proveedor, no el hotel).
     */
    public function transferMinutes(): int
    {
        $minutes = $this->minutesFrom('transfer_valid_value', 'transfer_valid_unit');

        return $minutes ?? ((int) config('payments.transfer_hours', 24)) * 60;
    }

    /**
     * ¿El hotel exige el pago total antes de la llegada? (interruptor
     * global del módulo de fecha límite / cobro automático de saldos).
     */
    public function balanceDueEnabled(): bool
    {
        return (bool) ($this->settings()['balance_due_enabled'] ?? true);
    }

    /**
     * Fecha límite de pago total para una reserva: la tarifa manda si
     * define su propia anticipación (comportamiento de siempre); si no, el
     * default del hotel (5 días). El default solo aplica cuando queda al
     * menos 24 h en el futuro — para llegadas más próximas no tiene caso
     * abrir una fecha límite ya vencida que dispararía cancelaciones.
     */
    public function paymentDueAt(RatePlan $ratePlan, CarbonInterface $start): ?CarbonInterface
    {
        if (! $this->balanceDueEnabled()) {
            return null;
        }

        $due = $ratePlan->paymentDueAt($start);

        if ($due !== null) {
            return $due;
        }

        $value = (int) ($this->settings()['balance_due_value'] ?? 5);
        $unit = RateDurationUnit::tryFrom((string) ($this->settings()['balance_due_unit'] ?? 'day')) ?? RateDurationUnit::Day;

        if ($value < 1) {
            return null;
        }

        $due = $unit->subtractFrom($start, $value);

        return $due->gt(now()->addDay()) ? $due : null;
    }

    /** Valor+unidad de settings traducido a minutos; null si no está configurado. */
    protected function minutesFrom(string $valueKey, string $unitKey): ?int
    {
        $value = (int) ($this->settings()[$valueKey] ?? 0);
        $unit = RateDurationUnit::tryFrom((string) ($this->settings()[$unitKey] ?? ''));

        if ($value < 1 || $unit === null || $unit->minutes() === null) {
            return null;
        }

        return $value * $unit->minutes();
    }

    /** @return array<string, mixed> */
    protected function settings(): array
    {
        return $this->settings ??= Property::query()->first()?->settings ?? [];
    }
}
