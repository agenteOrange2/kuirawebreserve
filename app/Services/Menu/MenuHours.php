<?php

namespace App\Services\Menu;

/**
 * Horario del menú digital (settings de la propiedad): fuera de horario la
 * carta pública avisa y la API rechaza pedidos. Sin horario capturado, la
 * cocina "siempre atiende". Soporta rangos que cruzan medianoche
 * (22:00 → 02:00).
 */
class MenuHours
{
    /**
     * @param  array<string, mixed>  $settings
     * @return array{open: bool, from: string|null, to: string|null}
     */
    public static function status(array $settings): array
    {
        $from = $settings['menu_hours_from'] ?? null;
        $to = $settings['menu_hours_to'] ?? null;

        if (! $from || ! $to || $from === $to) {
            return ['open' => true, 'from' => $from, 'to' => $to];
        }

        $current = now()->format('H:i');

        $open = $from < $to
            ? ($current >= $from && $current < $to)
            : ($current >= $from || $current < $to); // cruza medianoche

        return ['open' => $open, 'from' => $from, 'to' => $to];
    }
}
