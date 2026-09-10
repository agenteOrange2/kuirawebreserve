<?php

namespace App\Exceptions;

use Exception;

class NoAvailabilityException extends Exception
{
    public static function forRoom(string $number): self
    {
        return new self("La habitación {$number} ya no está disponible en ese horario.");
    }

    /**
     * El cuarto no está para entregarse AHORA (sucio, en limpieza, ocupado,
     * en mantenimiento). Distinto de forRoom(), que es un choque de fechas:
     * el mostrador necesita saber cuál de las dos cosas le pasa, porque una
     * se resuelve limpiando y la otra cambiando el horario.
     */
    public static function forRoomState(string $number, string $stateLabel, string $action = 'el check-in'): self
    {
        return new self("La habitación {$number} está \"{$stateLabel}\"; libérala desde el plano para poder hacer {$action}.");
    }

    /**
     * Llegada anticipada: entrar un día ANTES no es "llegar temprano", es
     * cambiar la fecha real de entrada. Se permite, pero con intención
     * explícita — un clic distraído en la reserva de mañana abría la
     * estancia hoy y nadie se enteraba hasta el corte.
     */
    public static function earlyArrival(string $code, string $startsAt): self
    {
        return new self("La reserva {$code} llega el {$startsAt}. Si el huésped ya está aquí, confirma la llegada anticipada.");
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
