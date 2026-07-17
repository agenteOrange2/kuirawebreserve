<?php

namespace App\Exceptions;

use Exception;

class NoAvailabilityException extends Exception
{
    public static function forRoom(string $number): self
    {
        return new self("La habitación {$number} ya no está disponible en ese horario.");
    }

    public static function forRoomState(string $number, string $stateLabel): self
    {
        return new self("La habitación {$number} está \"{$stateLabel}\"; libérala desde el plano para poder hacer el check-in.");
    }

    public static function forRoomType(): self
    {
        return new self('No hay habitaciones disponibles de ese tipo en el rango solicitado.');
    }

    public static function minAdvance(string $label): self
    {
        return new self("Esta tarifa requiere reservar con al menos {$label} de antelación.");
    }

    public static function exceedsCapacity(string $number, int $capacity): self
    {
        return new self("La habitación {$number} admite hasta {$capacity} personas.");
    }

    public static function forExperienceSession(int $remaining): self
    {
        return new self($remaining > 0
            ? "Esa sesión solo tiene {$remaining} lugar(es) disponible(s)."
            : 'Esa sesión ya no tiene cupo disponible; elige otra fecha u horario.');
    }
}
