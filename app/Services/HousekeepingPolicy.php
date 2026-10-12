<?php

namespace App\Services;

use App\Enums\RateDurationUnit;
use App\Models\Property;

/**
 * Los relojes de la operación del día (settings del Property, se
 * administran en /ajustes/limpieza): check-in automático a la hora de
 * llegada, avance del semáforo de limpieza y cierre de día para una
 * reservada cuya salida venció sin check-in registrado. Los defaults
 * reproducen el comportamiento histórico: todo manual… excepto el cierre
 * de día, que nace prendido porque su ausencia ERA un bug — habitaciones
 * "reservada" para siempre cuando el hotel no registra check-ins (motel).
 */
class HousekeepingPolicy
{
    public const MODE_MANUAL = 'manual';

    public const MODE_AUTO = 'auto';

    public const MODE_BOTH = 'both';

    public const DAY_CLOSE_DIRTY = 'dirty';

    public const DAY_CLOSE_AVAILABLE = 'available';

    public const DAY_CLOSE_NONE = 'none';

    /** @var array<string, mixed>|null */
    protected ?array $settings = null;

    /** Modo del flujo sucia → limpieza → disponible. */
    public function mode(): string
    {
        $mode = (string) ($this->settings()['hk_mode'] ?? self::MODE_MANUAL);

        return in_array($mode, [self::MODE_MANUAL, self::MODE_AUTO, self::MODE_BOTH], true)
            ? $mode
            : self::MODE_MANUAL;
    }

    /** ¿El scheduler avanza sucia → limpieza → disponible por tiempo? */
    public function autoAdvances(): bool
    {
        return $this->mode() !== self::MODE_MANUAL;
    }

    /**
     * ¿El reloj libera una habitación "en limpieza" al cumplirse el tiempo
     * asignado? Siempre en automático y ambos; en manual solo si el hotel
     * prendió el switch. Así la camarista sigue iniciando la limpieza a mano
     * (con su nombre) sin que el cuarto se quede en limpieza para siempre
     * cuando nadie la termina.
     */
    public function releasesCleaningByClock(): bool
    {
        return $this->autoAdvances() || (bool) ($this->settings()['hk_cleaning_auto_release'] ?? false);
    }

    /** ¿El plano ofrece los botones manuales de limpieza? */
    public function manualAllowed(): bool
    {
        return $this->mode() !== self::MODE_AUTO;
    }

    /**
     * Modo del check-in a la llegada: manual (solo el personal), auto (a la
     * hora de llegada la reserva confirmada hace check-in sola y los botones
     * se ocultan) o both (el personal puede adelantarlo y el reloj remata).
     */
    public function checkinMode(): string
    {
        $mode = (string) ($this->settings()['checkin_mode'] ?? self::MODE_MANUAL);

        return in_array($mode, [self::MODE_MANUAL, self::MODE_AUTO, self::MODE_BOTH], true)
            ? $mode
            : self::MODE_MANUAL;
    }

    /** ¿El scheduler registra el check-in al llegar la hora? */
    public function autoCheckIn(): bool
    {
        return $this->checkinMode() !== self::MODE_MANUAL;
    }

    /** ¿El panel ofrece los botones de check-in manual? */
    public function manualCheckInAllowed(): bool
    {
        return $this->checkinMode() !== self::MODE_AUTO;
    }

    /** Minutos en "sucia" antes de pasar sola a "en limpieza". */
    public function dirtyMinutes(): int
    {
        return $this->minutesFrom('hk_dirty_value', 'hk_dirty_unit') ?? 30;
    }

    /**
     * Tiempo asignado a una limpieza: con él se califica si se entregó a
     * tiempo y, si el reloj libera, cuándo pasa sola a "disponible".
     */
    public function cleaningMinutes(): int
    {
        return $this->minutesFrom('hk_cleaning_value', 'hk_cleaning_unit') ?? 45;
    }

    /**
     * Qué hacer con una habitación "reservada" cuya reserva confirmada ya
     * terminó sin check-in: 'dirty' asume que se ocupó (reserva completada,
     * habitación a sucia — flujo motel), 'available' asume que no llegó
     * (no-show y habitación libre), 'none' la deja para gestión manual.
     */
    public function dayCloseAction(): string
    {
        $action = (string) ($this->settings()['day_close_no_checkin'] ?? self::DAY_CLOSE_DIRTY);

        return in_array($action, [self::DAY_CLOSE_DIRTY, self::DAY_CLOSE_AVAILABLE, self::DAY_CLOSE_NONE], true)
            ? $action
            : self::DAY_CLOSE_DIRTY;
    }

    /**
     * Margen tras la salida prevista antes de cerrar el día — el mismo que
     * usa el auto-checkout de estancias: son la misma tolerancia operativa.
     */
    public function dayCloseGraceMinutes(): int
    {
        return (int) config('reservations.auto_checkout.grace_minutes', 15);
    }

    /**
     * ¿El hotel da por perdida la llegada que no aparece? El cierre de día
     * mira la SALIDA, así que una reserva de tres noches que nadie ocupó
     * mantenía el cuarto apartado las tres noches. Esto mira la ENTRADA.
     *
     * Default apagado: prenderlo cambia la operación (marca no-show solo) y
     * eso lo decide cada hotel en /ajustes/limpieza, no un deploy.
     */
    public function noShowEnabled(): bool
    {
        return (bool) ($this->settings()['arrival_no_show_enabled'] ?? false);
    }

    /**
     * Minutos tras la hora de entrada antes de dar la llegada por perdida.
     * 0 = sin ventana: el cuarto queda apartado hasta la salida, como
     * operaba el panel antes del ajuste.
     */
    public function noShowAfterMinutes(): int
    {
        if (! $this->noShowEnabled()) {
            return 0;
        }

        return $this->minutesFrom('arrival_no_show_value', 'arrival_no_show_unit') ?? 360;
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
