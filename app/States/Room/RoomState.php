<?php

namespace App\States\Room;

use App\Enums\RoomStatus;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Máquina de estados del semáforo de habitaciones (spec §6).
 *
 * available → reserved → occupied → dirty → cleaning → available
 * available → occupied (walk-in) · reserved → available (cancelación)
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
            ->allowTransition(Available::class, Occupied::class)
            ->allowTransition(Occupied::class, Dirty::class)
            ->allowTransition(Dirty::class, Cleaning::class)
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
