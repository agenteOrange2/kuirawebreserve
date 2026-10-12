<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Property extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\PropertyFactory> */
    use HasFactory;

    use InteractsWithMedia;

    protected $fillable = [
        'name',
        'timezone',
        'address',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * Colores de fábrica del wizard público (los del theme Raze). Si el
     * hotel no personaliza nada en /reservas/ajustes, /reservar se ve así.
     */
    public const WIZARD_APPEARANCE_DEFAULTS = [
        'bg_from' => '#03045e',
        'bg_to' => '#0c4a6e',
        'accent' => '#03045e',
        'theme' => 'light',
        // Texto sobre el degradado: nombre del hotel, pasos y pie.
        'header_text' => '#ffffff',
        // Títulos de las tarjetas en modo claro; null = el del theme.
        'title_color' => null,
        // Texto de los botones llenos: 'auto' elige blanco o casi negro
        // según qué tan claro sea el acento.
        'button_text' => 'auto',
        'title_font' => 'default',
    ];

    /** Textos del wizard que el hotel puede cambiar (null = el de fábrica). */
    public const WIZARD_TEXT_DEFAULTS = [
        'tagline' => 'Reserva directamente con nosotros, sin intermediarios',
        'title' => '¿Cuándo nos visitas?',
        'subtitle' => 'Elige tus fechas y te mostramos lo disponible al momento, con su precio real.',
        'search_button' => 'Ver habitaciones disponibles',
    ];

    /** Tipografías de los títulos: pilas del sistema, sin cargar fuentes de fuera. */
    public const WIZARD_TITLE_FONTS = ['default', 'serif', 'rounded', 'condensed'];

    /** Logo del wizard y fondo del login: un archivo cada uno; subir otro reemplaza al anterior. */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('wizard_logo')->singleFile()->useDisk('public');
        // Foto del lado derecho del login del hotel (/ajustes/general/apariencia).
        $this->addMediaCollection('login_background')->singleFile()->useDisk('public');
    }

    /**
     * Apariencia del wizard público ya resuelta (personalización del hotel
     * o defaults): colores del fondo, acento, modo claro/oscuro y logo.
     *
     * @return array<string, mixed>
     */
    public function wizardAppearance(): array
    {
        $settings = $this->settings ?? [];
        $logo = $this->getFirstMedia('wizard_logo');
        $defaults = self::WIZARD_APPEARANCE_DEFAULTS;
        $text = fn (string $key) => filled($settings["wizard_text_{$key}"] ?? null)
            ? (string) $settings["wizard_text_{$key}"]
            : self::WIZARD_TEXT_DEFAULTS[$key];

        return [
            'bg_from' => $settings['wizard_bg_from'] ?? $defaults['bg_from'],
            'bg_to' => $settings['wizard_bg_to'] ?? $defaults['bg_to'],
            'accent' => $settings['wizard_accent'] ?? $defaults['accent'],
            'theme' => $settings['wizard_theme'] ?? $defaults['theme'],
            'header_text' => $settings['wizard_header_text'] ?? $defaults['header_text'],
            'title_color' => $settings['wizard_title_color'] ?? $defaults['title_color'],
            'button_text' => $settings['wizard_button_text'] ?? $defaults['button_text'],
            'title_font' => in_array($settings['wizard_title_font'] ?? null, self::WIZARD_TITLE_FONTS, true)
                ? $settings['wizard_title_font']
                : $defaults['title_font'],
            'texts' => [
                'tagline' => $text('tagline'),
                'title' => $text('title'),
                'subtitle' => $text('subtitle'),
                'search_button' => $text('search_button'),
            ],
            // ?v= : al resubir cambia el id del media y revienta el caché.
            'logo_url' => $logo ? '/fotos/logo?v='.$logo->id : null,
        ];
    }

    /** Redes con el icono Lucide de cada una (fallback genérico). */
    public const SOCIAL_ICONS = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'tiktok' => 'Music2',
        'youtube' => 'Youtube',
        'x' => 'Twitter',
        'whatsapp' => 'MessageCircle',
        'other' => 'Link',
    ];

    /**
     * Contacto público del hotel normalizado (sitio, mapa, redes) — lo
     * consumen las páginas públicas (retorno de pago, wizards) para
     * "bombardear" por todos los canales. Los teléfonos/emails legacy
     * siguen viviendo en settings['phone'|'email'] para los 7 lectores que
     * los leen como string; aquí se exponen las listas nuevas.
     */
    public function publicContact(): array
    {
        $settings = $this->settings ?? [];

        return [
            'name' => $this->name,
            'website' => $settings['website'] ?? null,
            'maps_url' => $settings['maps_url'] ?? null,
            'socials' => collect($settings['socials'] ?? [])
                ->filter(fn ($s) => ! empty($s['url']))
                ->map(fn ($s) => [
                    'type' => $s['type'] ?? 'other',
                    'url' => $s['url'],
                    'icon' => self::SOCIAL_ICONS[$s['type'] ?? 'other'] ?? 'Link',
                ])
                ->values()
                ->all(),
        ];
    }

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class)->orderBy('sort_order');
    }

    public function roomTypes(): HasMany
    {
        return $this->hasMany(RoomType::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}
