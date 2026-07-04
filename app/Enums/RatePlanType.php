<?php

namespace App\Enums;

enum RatePlanType: string
{
    // Por noche (hotel clásico).
    case Night = 'night';

    // Por bloque de tiempo (motel: "rato" de N minutos; una tarifa por hora
    // es un block con duration_minutes = 60).
    case Block = 'block';

    public function label(): string
    {
        return match ($this) {
            self::Night => 'Por noche',
            self::Block => 'Por bloque',
        };
    }
}
