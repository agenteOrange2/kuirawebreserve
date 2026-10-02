<?php

namespace App\Support;

use App\Models\Property;

/**
 * Marca del hotel en SU login (cabanas.kuirawebreserve.com/login): logo,
 * nombre, colores del panel y, si los personalizó, textos y foto de fondo.
 * Lo que el hotel no personaliza cae en la marca de la plataforma, y el
 * login siempre dice "con la tecnología de <plataforma>".
 *
 * null = dominio central, o el hotel apagó su marca (settings.login_brand_enabled
 * = false): el login se ve con la marca de la plataforma tal cual.
 */
class TenantLoginBrand
{
    /**
     * @return array{name: string, logo_url: string|null, hint: string|null, title: string|null, subtitle: string|null, background_url: string|null, colors: array{primary: string|null, menu_from: string|null, menu_to: string|null}}|null
     */
    public static function resolve(): ?array
    {
        if (! tenancy()->initialized) {
            return null;
        }

        try {
            $property = Property::query()->first();
        } catch (\Throwable) {
            return null;
        }

        $settings = $property?->settings ?? [];

        if (! filter_var($settings['login_brand_enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return null;
        }

        $logo = $property?->getFirstMedia('wizard_logo');

        return [
            'name' => $property?->name ?: (string) tenant('name'),
            'logo_url' => $logo ? '/fotos/logo?v='.$logo->id : null,
            'hint' => self::text($settings['login_hint'] ?? null),
            'title' => self::text($settings['login_title'] ?? null),
            'subtitle' => self::text($settings['login_subtitle'] ?? null),
            'background_url' => $property ? self::backgroundUrl($property) : null,
            'colors' => [
                'primary' => $settings['panel_primary'] ?? null,
                'menu_from' => $settings['panel_menu_from'] ?? null,
                'menu_to' => $settings['panel_menu_to'] ?? null,
            ],
        ];
    }

    public static function backgroundUrl(Property $property): ?string
    {
        $media = $property->getFirstMedia('login_background');

        // ?v= : al resubir cambia el id del media y revienta el caché.
        return $media ? '/fotos/fondo-login?v='.$media->id : null;
    }

    protected static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
