<?php

namespace App\Jobs;

use App\Models\Central\MetaChannelLink;
use App\Models\SocialComment;
use App\Models\SocialPost;
use App\Models\Tenant;
use App\Services\Meta\MetaApi;
use App\Services\Social\SocialResponder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Ingesta de un comentario de red social fuera del webhook.
 *
 * Por qué job y no inline como los DMs: un post con pauta trae ráfagas de
 * comentarios, y clasificar cada uno con el LLM dentro del request de Meta
 * lo tumbaría por timeout — con el agravante de que Meta reintenta, y un
 * reintento a medio camino publica la misma respuesta dos veces. Aquí la
 * idempotencia la da `social_comments.external_id` (único).
 *
 * El webhook corre en el dominio central SIN tenancy: por eso el tenant
 * llega como parámetro y se entra con run(), igual que handleInbound().
 */
class ProcessSocialComment implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    /**
     * @param  array<string, mixed>  $payload  Comentario normalizado por MetaWebhookController::commentChangeToPayload()
     */
    public function __construct(
        public string $tenantId,
        public int $linkId,
        public array $payload,
    ) {}

    public function handle(MetaApi $api, SocialResponder $responder): void
    {
        $tenant = Tenant::find($this->tenantId);

        if (! $tenant) {
            return;
        }

        // Gate del módulo en la orilla: sin contratarlo, los comentarios ni
        // se guardan (mismo criterio que un canal no vinculado).
        if (! $tenant->hasModule('redes-sociales')) {
            Log::info('Redes: comentario descartado, el hotel no tiene el módulo', [
                'tenant' => $this->tenantId,
                'comment_id' => $this->payload['comment_id'] ?? null,
            ]);

            return;
        }

        $link = MetaChannelLink::find($this->linkId);

        if (! $link) {
            return;
        }

        $tenant->run(function () use ($api, $responder, $link) {
            $commentId = (string) ($this->payload['comment_id'] ?? '');

            if ($commentId === '') {
                return;
            }

            $verb = (string) ($this->payload['verb'] ?? 'add');
            $existing = SocialComment::query()->where('external_id', $commentId)->first();

            // El autor borró su comentario: se conserva la fila con su sello.
            if ($verb === 'remove') {
                $existing?->update(['deleted_from_network_at' => now()]);

                return;
            }

            // Reintento de Meta sobre algo ya atendido: no se toca (volver a
            // clasificar publicaría una segunda respuesta pública).
            if ($existing && $existing->status !== SocialComment::STATUS_NEW) {
                if ($verb === 'edited') {
                    $existing->update(['body' => $this->payload['body'] ?? $existing->body]);
                }

                return;
            }

            $post = $this->resolvePost($api, $link);
            $comment = $this->storeComment($post, $existing);

            $responder->handle($comment, $link);
        });
    }

    /**
     * Publicación dueña del comentario. Si es la primera vez que la vemos se
     * completa con Graph; si Graph falla se guarda lo que trajo el webhook y
     * la siguiente sincronización la completa.
     */
    protected function resolvePost(MetaApi $api, MetaChannelLink $link): SocialPost
    {
        $network = (string) ($this->payload['network'] ?? SocialPost::NETWORK_FACEBOOK);
        $postId = (string) ($this->payload['post_id'] ?? '');

        // Sin post_id (raro, pero pasa en algunos eventos de IG) se agrupa
        // bajo una publicación marcador para no perder el comentario.
        if ($postId === '') {
            $postId = 'desconocida-'.$network;
        }

        $post = SocialPost::firstOrCreate(
            ['network' => $network, 'external_id' => $postId],
            [
                'account_external_id' => $link->type === 'messenger' ? $link->external_id : ($link->external_id ?: null),
                'permalink' => $this->payload['post_permalink'] ?? null,
            ],
        );

        if ($post->wasRecentlyCreated && ! str_starts_with($postId, 'desconocida-')) {
            $detail = $api->postDetail($link, $postId);

            if ($detail) {
                $post->update(array_filter([
                    'message' => $detail['message'] ?? null,
                    'permalink' => $detail['permalink'] ?? $post->permalink,
                    'media_url' => $detail['media_url'] ?? null,
                    'published_at' => isset($detail['published_at']) ? Carbon::parse($detail['published_at']) : null,
                    'last_synced_at' => now(),
                ], fn ($value) => $value !== null));
            }
        }

        $post->increment('comments_count');

        return $post;
    }

    protected function storeComment(SocialPost $post, ?SocialComment $existing): SocialComment
    {
        $attributes = [
            'social_post_id' => $post->id,
            'parent_external_id' => $this->payload['parent_id'] ?? null,
            'author_external_id' => $this->payload['author_id'] ?? null,
            'author_name' => $this->payload['author_name'] ?? null,
            'body' => $this->payload['body'] ?? null,
            'commented_at' => isset($this->payload['commented_at'])
                ? Carbon::parse($this->payload['commented_at'])
                : now(),
            'status' => SocialComment::STATUS_NEW,
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing->refresh();
        }

        return SocialComment::create($attributes + [
            'external_id' => (string) $this->payload['comment_id'],
        ]);
    }
}
