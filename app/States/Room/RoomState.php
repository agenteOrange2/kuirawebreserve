<?php

namespace App\States\Room;

use App\Enums\RoomStatus;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Máquina de estados del semáforo de habitaciones (spec §6).
 *
 * available → reserved → occupied → dirty → cleaning → available
 * dirty → available (liberar en un paso: la habitación ya quedó lista sin
 * registrar el ciclo de limpieza) · available → occupied (walk-in) ·
 * reserved → available (cancelación) · reserved → dirty (cierre de día: la
 * salida venció sin check-in y se asume que la habitación se usó) ·
 * cualquiera → maintenance → available
 */
abstract class RoomState extends State
{
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Available::class)
            ->allowTransition(Available::class, Reserved::class)
            ->allowTransition(Reserved::class, Occupied::class)
            ->allowTransition(Reserved::class, Available::class)
            ->allowTransition(Reserved::class, Dirty::class)
            ->allowTransition(Available::class, Occupied::class)
            ->allowTransition(Occupied::class, Dirty::class)
            ->allowTransition(Dirty::class, Cleaning::class)
            ->allowTransition(Dirty::class, Available::class)
            ->allowTransition(Cleaning::class, Available::class)
            ->allowTransition([Available::class, Reserved::class, Occupied::class, Dirty::class, Cleaning::class], Maintenance::class)
            ->allowTransition(Maintenance::class, Available::class);
    }

    public function enum(): RoomStatus
    {
        return RoomStatus::from(static::getMorphClass());
    }

    public function color(): string
    {
        return $this->enum()->color();
    }

    public function label(): string
    {
        return $this->enum()->label();
    }
}
