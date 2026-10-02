<?php

namespace App\Models\Central;

use Illuminate\Support\Facades\Cache;

/**
 * Llave-valor de plataforma (branding y similares). Lecturas cacheadas:
 * app.blade.php consulta el nombre/favicon en CADA request, incluidos los
 * de tenants. get() nunca lanza (antes de migrar devuelve el default).
 *
 * La caché va SIEMPRE al store sin etiquetas (Cache::store()): con el
 * CacheTenancyBootstrapper, Cache::rememberForever() dentro de un hotel
 * etiqueta la llave con ese tenant, y el Cache::forget() que hace el admin
 * desde el dominio central nunca la alcanzaba: el login y el favicon de los
 * hoteles se quedaban con la marca vieja para siempre.
 */
class PlatformSetting extends CentralModel
{
    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        try {
            return Cache::store()->rememberForever(
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
        Cache::store()->forget("platform_setting.{$key}");
    }
}
