<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\MetaChannelLink;
use App\Models\SocialComment;
use App\Models\SocialPost;
use App\Services\Meta\MetaApi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Escanea la Graph API para traer las publicaciones de un periodo (1, 3, 6 o
 * 12 meses) con sus comentarios.
 *
 * El webhook es la vía normal y llega al instante; esto sirve para poblar el
 * panel con lo que ya existía antes de conectar el módulo, o para recuperar
 * un evento perdido. Los comentarios traídos por aquí NO se auto-responden:
 * pueden tener meses y una respuesta tardía queda peor que ninguna.
 */
class SocialSyncController extends Controller
{
    /** Periodos ofrecidos en el panel, en meses. */
    public const PERIODS = [1, 3, 6, 12];

    public function __construct(protected MetaApi $api) {}

    public function store(Request $request): RedirectResponse
    {
        $months = (int) $request->input('meses', 1);

        if (! in_array($months, self::PERIODS, true)) {
            $months = 1;
        }

        $since = now()->subMonths($months)->startOfDay();

        $links = MetaChannelLink::query()
            ->where('tenant_id', (string) tenant('id'))
            ->whereIn('type', ['messenger', 'instagram'])
            ->where('active', true)
            ->get();

        if ($links->isEmpty()) {
            return back()->with('error', 'Todavía no hay una página de Facebook ni una cuenta de Instagram conectada.');
        }

        $total = 0;
        $roto = false;
        $reports = [];

        foreach ($links as $link) {
            $network = $link->type === 'messenger'
                ? SocialPost::NETWORK_FACEBOOK
                : SocialPost::NETWORK_INSTAGRAM;

            $identity = $this->api->accountIdentity($link);
            $red = SocialPost::NETWORK_LABELS[$network] ?? $link->type;
            $cuenta = $identity['name'] ? "{$red} ({$identity['name']})" : $red;

            // Un token caducado es la causa más común de "no jala nada", y
            // hasta ahora moría callado en el log.
            if (! $identity['ok']) {
                $roto = true;
                $reports[] = "{$cuenta}: no se pudo leer la cuenta, vuelve a conectarla desde el asistente.";

                Log::warning('Redes: cuenta ilegible en el escaneo', [
                    'type' => $link->type,
                    'external_id' => $link->external_id,
                    'error' => $identity['error'],
                ]);

                continue;
            }

            // Un token sin los permisos de publicaciones devuelve la página
            // vacía SIN error: hay que decirlo o el escaneo miente.
            $missing = $this->api->missingCommentPermissions($link);

            if ($missing !== []) {
                $roto = true;
                $reports[] = "{$cuenta}: al acceso le faltan permisos ("
                    .implode(', ', array_keys($missing))
                    .'), por eso no se ven las publicaciones. Hay que volver a generar el acceso con esos permisos.';

                continue;
            }

            $seen = 0;
            $created = 0;
            $comments = 0;

            foreach ($this->api->accountPosts($link, $since) as $data) {
                if (($data['external_id'] ?? '') === '') {
                    continue;
                }

                $seen++;

                // Una publicación rara no puede tirar el escaneo entero: se
                // salta con su causa en el log y siguen las demás.
                try {
                    [$new, $found] = $this->syncPost($link, $network, $data);
                    $created += $new;
                    $comments += $found;
                } catch (Throwable $e) {
                    report($e);

                    Log::warning('Redes: publicación omitida en el escaneo', [
                        'post' => $data['external_id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $total += $seen;
            $reports[] = $seen === 0
                ? "{$cuenta}: sin publicaciones en el periodo."
                : "{$cuenta}: {$seen} ".($seen === 1 ? 'publicación' : 'publicaciones')
                    .", {$created} ".($created === 1 ? 'nueva' : 'nuevas')
                    .", {$comments} ".($comments === 1 ? 'comentario nuevo' : 'comentarios nuevos').'.';
        }

        // El aviso de permisos del panel se recalcula: acaba de comprobarse.
        cache()->forget('social:permisos:'.tenant('id'));

        $period = $months === 1 ? 'del último mes' : ($months === 12 ? 'del último año' : "de los últimos {$months} meses");
        $message = "Escaneo {$period} — ".implode(' · ', $reports);

        if ($total === 0 && ! $roto) {
            $message .= ' Si tu página sí tiene publicaciones, prueba con un periodo más amplio o revisa que esté conectada la página correcta.';
        }

        return back()->with($total === 0 || $roto ? 'error' : 'success', $message);
    }

    /**
     * Guarda una publicación con sus comentarios.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: int, 1: int} publicaciones nuevas, comentarios nuevos
     */
    protected function syncPost(MetaChannelLink $link, string $network, array $data): array
    {
        $post = SocialPost::updateOrCreate(
            ['network' => $network, 'external_id' => $data['external_id']],
            [
                'account_external_id' => $link->external_id,
                'message' => $data['message'] ?? null,
                'permalink' => $data['permalink'] ?? null,
                'media_url' => $data['media_url'] ?? null,
                'published_at' => $data['published_at'] ? Carbon::parse($data['published_at']) : null,
                'last_synced_at' => now(),
            ],
        );

        $created = $post->wasRecentlyCreated ? 1 : 0;
        $comments = 0;

        // Sin comentarios en la red no hay para qué pedir el edge.
        if ((int) ($data['comments_count'] ?? 0) > 0 || $post->comments()->exists()) {
            foreach ($this->api->postComments($link, $data['external_id']) as $incoming) {
                if (($incoming['comment_id'] ?? '') === '') {
                    continue;
                }

                // Nuestras propias respuestas no son comentarios que atender.
                if (($incoming['author_id'] ?? null) === $link->external_id) {
                    continue;
                }

                $comment = SocialComment::firstOrNew(['external_id' => $incoming['comment_id']]);

                // Lo ya trabajado no se pisa: solo se refresca el texto.
                if ($comment->exists) {
                    $comment->update(['body' => $incoming['body'] ?? $comment->body]);

                    continue;
                }

                $comment->fill([
                    'social_post_id' => $post->id,
                    'parent_external_id' => $incoming['parent_id'] ?? null,
                    'author_external_id' => $incoming['author_id'] ?? null,
                    'author_name' => $incoming['author_name'] ?? null,
                    'body' => $incoming['body'] ?? null,
                    'commented_at' => $incoming['commented_at'] ? Carbon::parse($incoming['commented_at']) : null,
                    'status' => ($incoming['hidden'] ?? false)
                        ? SocialComment::STATUS_HIDDEN
                        : SocialComment::STATUS_NEW,
                    'hidden_at' => ($incoming['hidden'] ?? false) ? now() : null,
                    'hidden_reason' => ($incoming['hidden'] ?? false) ? 'oculto en la red social' : null,
                ])->save();

                $comments++;
            }
        }

        $post->update(['comments_count' => max(
            (int) ($data['comments_count'] ?? 0),
            $post->comments()->count(),
        )]);

        return [$created, $comments];
    }
}
