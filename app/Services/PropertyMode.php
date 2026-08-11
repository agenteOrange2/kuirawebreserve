<?php

namespace App\Services;

use App\Models\Property;

/**
 * Modo de operación de la propiedad (settings del Property, se elige en
 * /ajustes/general): hotel (default, el comportamiento histórico completo)
 * o motel (caseta: registro exprés desde el plano con placa/identificación
 * y cobro en la llegada). El modo no decide noche/bloque — eso ya se
 * infiere de las tarifas activas; solo gobierna lo que no es inferible:
 * qué se le pide al huésped y qué atajos ofrece el panel.
 */
class PropertyMode
{
    public const HOTEL = 'hotel';

    public const MOTEL = 'motel';

    /** @var array<string, mixed>|null */
    protected ?array $settings = null;

    public function mode(): string
    {
        $mode = (string) ($this->settings()['property_mode'] ?? self::HOTEL);

        return in_array($mode, [self::HOTEL, self::MOTEL], true) ? $mode : self::HOTEL;
    }

    public function isMotel(): bool
    {
        return $this->mode() === self::MOTEL;
    }

    /**
     * ¿El plano ofrece el registro exprés (tap → tarifa → placa → cobrar)?
     */
    public function expressCheckInEnabled(): bool
    {
        return $this->isMotel();
    }

    /** @return array<string, mixed> */
    protected function settings(): array
    {
        return $this->settings ??= Property::query()->first()?->settings ?? [];
    }
}
