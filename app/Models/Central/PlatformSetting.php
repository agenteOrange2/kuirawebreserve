<?php

namespace App\Models\Central;

use Illuminate\Support\Facades\Cache;

/**
 * Llave-valor de plataforma (branding y similares). Lecturas cacheadas:
 * app.blade.php consulta el nombre/favicon en CADA request, incluidos los
 * de tenants. get() nunca lanza (antes de migrar devuelve el default).
 */
class PlatformSetting extends CentralModel
{
    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        try {
            return Cache::rememberForever(
                "platform_setting.{$key}",
                fn () => self::query()->where('key', $key)->value('value'),
            ) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    public static function set(string $key, ?string $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("platform_setting.{$key}");
    }
}
