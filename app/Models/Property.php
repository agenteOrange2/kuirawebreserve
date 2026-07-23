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
    ];

    /** Logo del wizard: un solo archivo; subir otro reemplaza al anterior. */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('wizard_logo')->singleFile()->useDisk('public');
    }

    /**
     * Apariencia del wizard público ya resuelta (personalización del hotel
     * o defaults): colores del fondo, acento, modo claro/oscuro y logo.
     *
     * @return array{bg_from: string, bg_to: string, accent: string, theme: string, logo_url: string|null}
     */
    public function wizardAppearance(): array
    {
        $settings = $this->settings ?? [];
        $logo = $this->getFirstMedia('wizard_logo');

        return [
            'bg_from' => $settings['wizard_bg_from'] ?? self::WIZARD_APPEARANCE_DEFAULTS['bg_from'],
            'bg_to' => $settings['wizard_bg_to'] ?? self::WIZARD_APPEARANCE_DEFAULTS['bg_to'],
            'accent' => $settings['wizard_accent'] ?? self::WIZARD_APPEARANCE_DEFAULTS['accent'],
            'theme' => $settings['wizard_theme'] ?? self::WIZARD_APPEARANCE_DEFAULTS['theme'],
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
