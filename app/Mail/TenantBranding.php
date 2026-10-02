<?php

namespace App\Mail;

use App\Models\Central\PlatformSetting;
use App\Models\Property;
use Illuminate\Mail\Mailables\Address;

/**
 * Quién firma los correos: el hotel, no la plataforma. Si el hotel subió
 * logo (/reservas/ajustes → Apariencia) el encabezado lleva el logo; si no,
 * su nombre. Fuera del tenant (correos de la plataforma: prospectos, prueba
 * de SMTP) cae en el nombre de la app.
 *
 * Lo consultan las vistas publicadas en resources/views/vendor/mail, así que
 * cualquier mailable hereda la identidad sin pasar props. Todo se resuelve
 * con tolerancia a fallos: un correo nunca debe reventar por el logo.
 */
class TenantBranding
{
    public function __construct(
        public readonly string $name,
        /** URL absoluta del logo (null = se rotula con el nombre). */
        public readonly ?string $logoUrl,
        /** A dónde lleva el encabezado: sitio del hotel o su dominio. */
        public readonly string $url,
        public readonly string $accent,
        /** true = correo de un hotel; false = de la plataforma (central). */
        public readonly bool $isTenant = false,
        /** Contacto del hotel para el pie del correo (vacío = no se pinta). */
        public readonly ?string $address = null,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
        public readonly ?string $website = null,
        public readonly ?string $mapsUrl = null,
    ) {}

    /** Color del theme del panel: el acento de los correos de la plataforma. */
    public const PLATFORM_ACCENT = '#03045e';

    /** Nombre de la plataforma (/admin/settings/brand), para firmar. */
    public static function platformName(): string
    {
        try {
            return PlatformSetting::get('app_name') ?: (string) config('app.name');
        } catch (\Throwable) {
            return (string) config('app.name');
        }
    }

    public static function resolve(): self
    {
        $platformLogo = null;
        try {
            $path = PlatformSetting::get('logo_path');
            $platformLogo = $path ? rtrim((string) config('app.url'), '/').'/storage/'.$path : null;
        } catch (\Throwable) {
        }

        $fallback = new self(
            name: static::platformName(),
            logoUrl: $platformLogo,
            url: (string) config('app.url'),
            accent: self::PLATFORM_ACCENT,
        );

        try {
            // Sin guarda de tenancy a propósito: en el dominio central la
            // tabla `properties` no existe y la consulta cae al catch, que es
            // exactamente el fallback que queremos (correos de la plataforma).
            $property = Property::query()->first();

            if ($property === null) {
                return $fallback;
            }

            $settings = $property->settings ?? [];
            $logo = $property->getFirstMedia('wizard_logo');

            $website = static::website($settings);
            $email = trim((string) ($settings['email'] ?? ''))
                ?: trim((string) (($settings['emails'] ?? [])[0] ?? ''));

            return new self(
                name: $property->name ?: $fallback->name,
                // ?v= : al resubir cambia el id del media y revienta el caché
                // (mismo criterio que wizardAppearance()).
                logoUrl: $logo ? static::tenantUrl('/fotos/logo?v='.$logo->id) : null,
                url: $website ?? static::tenantUrl('/'),
                accent: $settings['wizard_accent'] ?? Property::WIZARD_APPEARANCE_DEFAULTS['accent'],
                isTenant: true,
                address: trim((string) $property->address) ?: null,
                phone: trim((string) ($settings['phone'] ?? '')) ?: null,
                email: $email ?: null,
                website: $website,
                mapsUrl: trim((string) ($settings['maps_url'] ?? '')) ?: null,
            );
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * Remitente visible: el hotel, no la plataforma. La dirección nunca se
     * toca (es la autenticada en el SMTP) y el nombre solo se sustituye si
     * quedó el de la plataforma — si el hotel capturó su propio remitente en
     * /ajustes (TenantMailer ya lo puso en la config), ese manda.
     */
    public function fromAddress(): Address
    {
        $configured = trim((string) config('mail.from.name'));
        $platform = trim((string) config('app.name'));

        return new Address(
            (string) config('mail.from.address'),
            ($configured === '' || $configured === $platform) ? $this->name : $configured,
        );
    }

    /** El sitio del hotel, si lo capturó en /ajustes/general. */
    protected static function website(array $settings): ?string
    {
        $website = trim((string) ($settings['website'] ?? ''));

        if ($website === '') {
            return null;
        }

        return str_starts_with($website, 'http') ? $website : 'https://'.$website;
    }

    /**
     * URL absoluta SIEMPRE en el dominio del hotel: estos correos salen de
     * colas y de webhooks que entran por el dominio central, donde url() a
     * secas hereda el host equivocado (mismo criterio que
     * AgentToolsController::publicTenantUrl).
     */
    public static function tenantUrl(string $relative): string
    {
        $domain = tenant()?->domains()->value('domain');

        if (! $domain) {
            return url($relative);
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return "{$scheme}://{$domain}{$relative}";
    }
}
