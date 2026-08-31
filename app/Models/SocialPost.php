<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Publicación de la página (Facebook, Instagram o TikTok) registrada para
 * agrupar sus comentarios y medir cuáles generan demanda real.
 *
 * No se publica desde el sistema: estas filas nacen del webhook de un
 * comentario o de la sincronización bajo demanda.
 */
class SocialPost extends Model
{
    public const NETWORK_FACEBOOK = 'fb';

    public const NETWORK_INSTAGRAM = 'ig';

    public const NETWORK_TIKTOK = 'tiktok';

    public const NETWORKS = [
        self::NETWORK_FACEBOOK,
        self::NETWORK_INSTAGRAM,
        self::NETWORK_TIKTOK,
    ];

    public const NETWORK_LABELS = [
        self::NETWORK_FACEBOOK => 'Facebook',
        self::NETWORK_INSTAGRAM => 'Instagram',
        self::NETWORK_TIKTOK => 'TikTok',
    ];

    protected $fillable = [
        'network',
        'external_id',
        'account_external_id',
        'message',
        'permalink',
        'media_url',
        'published_at',
        'comments_count',
        'stats',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'stats' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // La copia local de la imagen corresponde a la media_url guardada:
        // si cambia (escaneo, webhook o refresco), la copia vieja no vale.
        static::updated(function (self $post) {
            if ($post->wasChanged('media_url')) {
                Storage::disk('local')->delete($post->mediaCachePath());
                cache()->forget($post->mediaFailCacheKey());
            }
        });
    }

    /** Ruta (en el disco local del tenant) de la copia cacheada de la imagen. */
    public function mediaCachePath(): string
    {
        return 'social-media/post-'.$this->id.'.img';
    }

    /** Marcador de "esta imagen no se pudo descargar", para no reintentar en cada carga. */
    public function mediaFailCacheKey(): string
    {
        return 'social:img-caida:'.$this->id;
    }

    public function comments(): HasMany
    {
        return $this->hasMany(SocialComment::class);
    }

    public function networkLabel(): string
    {
        return self::NETWORK_LABELS[$this->network] ?? $this->network;
    }

    /**
     * Cómo se nombra la publicación en el panel. Muchas son solo foto o
     * video: sin texto se nombra por su fecha, nunca "sin título".
     */
    public function excerpt(int $length = 90): string
    {
        $message = trim((string) $this->message);

        if ($message !== '') {
            return mb_strlen($message) > $length
                ? mb_substr($message, 0, $length).'...'
                : $message;
        }

        return $this->published_at
            ? 'Publicación del '.$this->published_at->locale('es')->isoFormat('D [de] MMMM')
            : 'Publicación sin texto';
    }

    /**
     * Tipo de canal (MetaChannelLink) al que pertenece la publicación: es
     * como se ubica el token con el que se responde.
     */
    public function channelType(): ?string
    {
        return match ($this->network) {
            self::NETWORK_FACEBOOK => 'messenger',
            self::NETWORK_INSTAGRAM => 'instagram',
            default => null,
        };
    }

    public function scopeNetwork(Builder $query, string $network): Builder
    {
        return $query->where('network', $network);
    }
}
