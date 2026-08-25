<?php

namespace App\Services;

use App\Models\Property;

/**
 * Modo de operación de la propiedad (settings del Property, se elige al crear
 * o editar el hotel en /admin): hotel (default, el comportamiento histórico
 * completo), motel (caseta: registro exprés desde el plano con placa/
 * identificación y cobro en la llegada) o both, "ambos" — la misma propiedad
 * opera las dos cosas y NO se apaga nada: conserva todo lo de hotel y además
 * enciende los atajos de caseta. El modo no decide noche/bloque — eso ya se
 * infiere de las tarifas activas; solo gobierna lo que no es inferible:
 * qué se le pide al huésped y qué atajos ofrece el panel.
 */
class PropertyMode
{
    public const HOTEL = 'hotel';

    public const MOTEL = 'motel';

    public const BOTH = 'both';

    /** @var list<string> */
    public const MODES = [self::HOTEL, self::MOTEL, self::BOTH];

    /** @var array<string, string> etiqueta para el panel y los mensajes */
    public const LABELS = [
        self::HOTEL => 'Hotel',
        self::MOTEL => 'Motel',
        self::BOTH => 'Hotel y motel',
    ];

    /** @var array<string, mixed>|null */
    protected ?array $settings = null;

    public function mode(): string
    {
        $mode = (string) ($this->settings()['property_mode'] ?? self::HOTEL);

        return in_array($mode, self::MODES, true) ? $mode : self::HOTEL;
    }

    /** Motel puro (sin la operación de hotel). */
    public function isMotel(): bool
    {
        return $this->mode() === self::MOTEL;
    }

    /** Hotel puro (sin los atajos de caseta). */
    public function isHotel(): bool
    {
        return $this->mode() === self::HOTEL;
    }

    public function isBoth(): bool
    {
        return $this->mode() === self::BOTH;
    }

    /**
     * ¿La operación de caseta está disponible? Para GATEAR funcionalidad usa
     * esto y no isMotel(): en modo "ambos" también tiene que estar encendida.
     */
    public function hasMotel(): bool
    {
        return $this->isMotel() || $this->isBoth();
    }

    /** ¿La operación de hotel está disponible? */
    public function hasHotel(): bool
    {
        return $this->isHotel() || $this->isBoth();
    }

    /**
     * ¿El plano ofrece el registro exprés (tap → tarifa → placa → cobrar)?
     */
    public function expressCheckInEnabled(): bool
    {
        return $this->hasMotel();
    }

    public function label(): string
    {
        return self::labelFor($this->mode());
    }

    public static function labelFor(string $mode): string
    {
        return self::LABELS[$mode] ?? self::LABELS[self::HOTEL];
    }

    /**
     * Semillas de settings al dar de alta el hotel desde /admin: EDITABLES
     * después por el tenant (no es derivación en runtime). Motel puro nace
     * como caseta — solo adultos y menú pagado al recibirse. "Ambos" no
     * puede asumir eso, porque también vende noches a familias: fija el modo
     * y deja intactos los defaults de hotel.
     *
     * @return array<string, mixed>|null
     */
    public static function seedSettings(string $mode): ?array
    {
        return match ($mode) {
            self::MOTEL => [
                'property_mode' => self::MOTEL,
                'guest_policy' => 'adults_only',
                'menu_billing_mode' => 'motel',
            ],
            self::BOTH => ['property_mode' => self::BOTH],
            default => null,
        };
    }

    /** @return array<string, mixed> */
    protected function settings(): array
    {
        return $this->settings ??= Property::query()->first()?->settings ?? [];
    }
}
