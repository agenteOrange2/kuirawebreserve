<?php

namespace App\Exceptions;

use Exception;

class NoAvailabilityException extends Exception
{
    public static function forRoom(string $number): self
    {
        return new self("La habitación {$number} ya no está disponible en ese horario.");
    }

    public static function forRoomType(): self
    {
        return new self('No hay habitaciones disponibles de ese tipo en el rango solicitado.');
    }

    public static function minAdvance(string $label): self
    {
        return new self("Esta tarifa requiere reservar con al menos {$label} de antelación.");
    }
}
