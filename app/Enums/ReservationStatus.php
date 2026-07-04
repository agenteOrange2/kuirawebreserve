<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Confirmed => 'Confirmada',
            self::CheckedIn => 'En casa',
            self::Completed => 'Completada',
            self::Cancelled => 'Cancelada',
            self::NoShow => 'No show',
        };
    }

    /**
     * Estados que bloquean disponibilidad (pending solo con hold vigente,
     * eso lo filtra el query del motor).
     */
    public static function blocking(): array
    {
        return [self::Pending, self::Confirmed, self::CheckedIn];
    }
}
