<?php

namespace App\Enums;

/**
 * Semáforo de habitaciones (spec §6). En fase 1 las transiciones se
 * controlarán con spatie/laravel-model-states; por ahora el enum define
 * los estados válidos y su color para el plano.
 */
enum RoomStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Occupied = 'occupied';
    case Dirty = 'dirty';
    case Cleaning = 'cleaning';
    case Maintenance = 'maintenance';

    public function color(): string
    {
        return match ($this) {
            self::Available => 'green',
            self::Reserved => 'cyan',
            self::Occupied => 'red',
            self::Dirty => 'orange',
            self::Cleaning => 'blue',
            self::Maintenance => 'gray',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponible',
            self::Reserved => 'Reservada',
            self::Occupied => 'Ocupada',
            self::Dirty => 'Sucia',
            self::Cleaning => 'En limpieza',
            self::Maintenance => 'Mantenimiento',
        };
    }
}
