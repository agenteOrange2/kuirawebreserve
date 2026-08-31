<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\MetaChannelLink;
use App\Models\SocialPost;
use App\Services\Meta\MetaApi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Sirve la imagen de una publicación desde una copia en el disco del tenant.
 *
 * Las URLs del CDN de Meta van firmadas y caducan a las pocas semanas: sin
 * esta copia el panel se queda sin fotos en cuanto vence la firma. La primera
 * vez se descarga (y si la URL guardada ya murió, se pide una fresca a la
 * Graph API); las siguientes se responde del disco.
 */
class SocialPostImageController extends Controller
{
    /** Hosts del CDN de Meta desde los que se permite descargar. */
    protected const HOSTS = ['fbcdn.net', 'cdninstagram.com', 'fbsbx.com'];

    public function __invoke(SocialPost $post): Response
    {
        $disk = Storage::disk('local');
        $path = $post->mediaCachePath();

        // La copia local se lee con tolerancia: un problema de disco (los
        // permisos ya tumbaron todas las imágenes una vez) degrada a volver
        // a descargar, nunca a un 500.
        try {
            $bytes = $disk->exists($path) ? $disk->get($path) : null;
        } catch (Throwable $e) {
            report($e);
            $bytes = null;
        }

        if ($bytes === null) {
            abort_if(cache()->has($post->mediaFailCacheKey()), 404);

            $bytes = $this->download($post->media_url) ?? $this->downloadFresh($post);

            if ($bytes === null) {
                // Sin este marcador, cada carga del listado reintentaría la
                // descarga (y el viaje a Graph) por cada imagen rota.
                cache()->put($post->mediaFailCacheKey(), true, now()->addHours(6));
                abort(404);
            }

            // Mejor esfuerzo: si el disco no deja guardar, la imagen sale
            // igual desde memoria y se reintenta cachear en la siguiente.
            try {
                $disk->put($path, $bytes);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return response($bytes, 200, [
            'Content-Type' => (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * La URL guardada caducó: se pide el detalle a la Graph API, se guarda la
     * URL nueva (eso invalida el marcador de caída) y se intenta con ella.
     */
    protected function downloadFresh(SocialPost $post): ?string
    {
        $type = $post->channelType();

        if ($type === null) {
            return null;
        }

        $link = MetaChannelLink::query()
            ->where('tenant_id', (string) tenant('id'))
            ->where('type', $type)
            ->where('active', true)
            ->when($post->account_external_id, fn ($query, $account) => $query->where('external_id', $account))
            ->first();

        if (! $link) {
            return null;
        }

        $detail = app(MetaApi::class)->postDetail($link, $post->external_id);
        $fresh = $detail['media_url'] ?? null;

        if (! is_string($fresh) || $fresh === '' || $fresh === $post->media_url) {
            return null;
        }

        $post->update(['media_url' => $fresh]);

        return $this->download($fresh);
    }

    protected function download(?string $url): ?string
    {
        // Solo el CDN de Meta: la URL viene de la Graph API, pero servirla a
        // ciegas convertiría esta ruta en un proxy de cualquier cosa.
        $host = (string) parse_url((string) $url, PHP_URL_HOST);

        $allowed = is_string($url)
            && str_starts_with($url, 'https://')
            && collect(self::HOSTS)->contains(
                fn (string $suffix) => $host === $suffix || str_ends_with($host, '.'.$suffix),
            );

        if (! $allowed) {
            return null;
        }

        try {
            $response = Http::timeout(10)->get($url);

            if ($response->failed()
                || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
                return null;
            }

            $body = $response->body();

            // full_picture jamás pesa 10 MB: algo más grande es sospechoso.
            return ($body === '' || strlen($body) > 10 * 1024 * 1024) ? null : $body;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
