<?php

namespace App\Support;

/**
 * Iconos que el hotel puede ponerle a cada tipo de habitación (catálogo).
 * Se guarda el nombre del icono del theme (set Lucide) y no una categoría
 * nuestra: el plano lo pinta tal cual, sin traducciones intermedias.
 *
 * Es lista cerrada a propósito — el tipo lo escribe cada hotel con el nombre
 * que quiera ("Master Junior VIP"), así que adivinar el icono por el nombre
 * sería una lotería; en cambio elegirlo una vez al crear el tipo no cuesta
 * nada y se ve igual en todos lados.
 */
class RoomTypeIcons
{
    /** @var array<string, string> icono del theme => etiqueta del selector */
    public const OPTIONS = [
        'BedSingle' => 'Cama individual',
        'BedDouble' => 'Cama matrimonial',
        'Bed' => 'Cama king',
        'Sofa' => 'Suite o sala',
        'Bath' => 'Jacuzzi o tina',
        'Waves' => 'Alberca',
        'Crown' => 'Master o VIP',
        'Star' => 'Preferente',
        'Users' => 'Familiar',
        'Baby' => 'Con cuna',
        'Accessibility' => 'Accesible',
        'Car' => 'Con cochera',
        'Mountain' => 'Con vista',
        'Sparkles' => 'Remodelada',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::OPTIONS);
    }

    /** @return list<array{value: string, label: string}> */
    public static function forSelect(): array
    {
        return array_map(
            fn (string $value, string $label) => ['value' => $value, 'label' => $label],
            array_keys(self::OPTIONS),
            array_values(self::OPTIONS),
        );
    }
}
