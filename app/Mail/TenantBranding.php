<?php

namespace App\Mail;

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
    ) {}

    public static function resolve(): self
    {
        $fallback = new self(
            name: (string) config('app.name'),
            logoUrl: null,
            url: (string) config('app.url'),
            accent: Property::WIZARD_APPEARANCE_DEFAULTS['accent'],
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

            return new self(
                name: $property->name ?: $fallback->name,
                // ?v= : al resubir cambia el id del media y revienta el caché
                // (mismo criterio que wizardAppearance()).
                logoUrl: $logo ? static::tenantUrl('/fotos/logo?v='.$logo->id) : null,
                url: static::website($settings) ?? static::tenantUrl('/'),
                accent: $settings['wizard_accent'] ?? $fallback->accent,
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
    protected static function tenantUrl(string $relative): string
    {
        $domain = tenant()?->domains()->value('domain');

        if (! $domain) {
            return url($relative);
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return "{$scheme}://{$domain}{$relative}";
    }
}
