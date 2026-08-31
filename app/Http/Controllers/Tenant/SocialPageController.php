<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\MetaChannelLink;
use App\Models\SocialComment;
use App\Models\SocialPost;
use App\Services\Meta\MetaApi;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Índice del módulo de redes sociales: las publicaciones del periodo elegido
 * (1, 3, 6 o 12 meses) con su termómetro de actividad.
 *
 * El trabajo fino de cada publicación vive en su propia pantalla
 * (SocialPostPageController): aquí se decide QUÉ atender, ahí se atiende.
 */
class SocialPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $months = (int) $request->input('meses', 1);

        if (! in_array($months, SocialSyncController::PERIODS, true)) {
            $months = 1;
        }

        $since = now()->subMonths($months)->startOfDay();

        // Una publicación sin fecha (evento sin `created_time`) no debe
        // desaparecer del panel por culpa del filtro.
        $inPeriod = fn ($query) => $query->where(
            fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '>=', $since),
        );

        $network = (string) $request->input('red', 'todas');

        if (! in_array($network, SocialPost::NETWORKS, true)) {
            $network = 'todas';
        }

        $posts = SocialPost::query()
            ->tap($inPeriod)
            ->when($network !== 'todas', fn ($query) => $query->network($network))
            ->withCount([
                'comments',
                'comments as pending_count' => fn ($query) => $query->needsAttention(),
                'comments as purchase_count' => fn ($query) => $query->where('classification', SocialComment::CLASS_PURCHASE),
            ])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString()
            ->through(fn (SocialPost $post) => [
                'id' => $post->id,
                'network' => $post->network,
                'network_label' => $post->networkLabel(),
                'excerpt' => $post->excerpt(),
                'permalink' => $post->permalink,
                // Copia local: la URL del CDN de Meta caduca a las semanas.
                'media_url' => $post->media_url ? route('tenant.social.post.image', $post->id) : null,
                'published_at' => $post->published_at?->toDateTimeString(),
                'published_label' => $post->published_at?->locale('es')->isoFormat('D [de] MMMM, YYYY'),
                'comments_count' => $post->comments_count,
                'pending_count' => $post->pending_count,
                'purchase_count' => $post->purchase_count,
                'url' => route('tenant.social.post', $post->id),
            ]);

        // Un solo viaje a la base para los cuatro números del resumen, en
        // vez de un COUNT por tarjeta.
        $stats = SocialComment::query()
            ->whereHas('post', $inPeriod)
            ->selectRaw(
                'coalesce(sum(status = ?), 0) as nuevos, '
                .'coalesce(sum(status = ?), 0) as respondidos, '
                .'coalesce(sum(status = ?), 0) as pendientes, '
                .'count(distinct conversation_id) as conversaciones',
                [
                    SocialComment::STATUS_NEW,
                    SocialComment::STATUS_ANSWERED,
                    SocialComment::STATUS_PENDING_STAFF,
                ],
            )
            ->first();

        return Inertia::render('tenant/social/Index', [
            'posts' => $posts,
            'filters' => ['meses' => $months, 'red' => $network],
            'periods' => array_map(fn (int $m) => [
                'value' => $m,
                'label' => $m === 1 ? 'Último mes' : ($m === 12 ? 'Último año' : "Últimos {$m} meses"),
            ], SocialSyncController::PERIODS),
            'stats' => [
                'nuevos' => (int) $stats->nuevos,
                'respondidos' => (int) $stats->respondidos,
                'pendientes' => (int) $stats->pendientes,
                'conversaciones' => (int) $stats->conversaciones,
            ],
            'networks' => $this->networks(),
            'lastSyncedAt' => SocialPost::max('last_synced_at'),
            'permissionIssues' => $this->permissionIssues(),
        ]);
    }

    /**
     * Permisos faltantes del token, en cristiano y con qué hacer.
     *
     * Se cachea 15 minutos: es una llamada a Meta y esto se pinta en cada
     * carga de la página, pero enterarse tarde de un permiso faltante sale
     * mucho más caro que la llamada.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function permissionIssues(): array
    {
        return cache()->remember(
            'social:permisos:'.tenant('id'),
            now()->addMinutes(15),
            function () {
                $issues = [];

                $links = MetaChannelLink::query()
                    ->where('tenant_id', (string) tenant('id'))
                    ->whereIn('type', ['messenger', 'instagram'])
                    ->where('active', true)
                    ->get();

                foreach ($links as $link) {
                    $found = app(MetaApi::class)->commentSetupIssues($link);

                    if ($found !== []) {
                        $issues[] = [
                            'account' => $link->name ?: $link->external_id,
                            'network' => $link->type === 'messenger' ? 'Facebook' : 'Instagram',
                            'items' => $found,
                        ];
                    }
                }

                return $issues;
            },
        );
    }

    /**
     * Estado de cada red: cuáles están conectadas y por qué TikTok todavía
     * no. Se dice en la UI en vez de mostrar una pestaña muerta.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function networks(): array
    {
        // Nombre de la cuenta conectada: conectar la página equivocada (o
        // una de pruebas) explica el 90% de los "no me jala nada", y sin
        // verlo en pantalla es indiagnosticable.
        $links = MetaChannelLink::query()
            ->where('tenant_id', (string) tenant('id'))
            ->where('active', true)
            ->get()
            ->keyBy('type');

        $facebook = $links->get('messenger');
        $instagram = $links->get('instagram');

        return [
            [
                'key' => SocialPost::NETWORK_FACEBOOK,
                'label' => 'Facebook',
                'connected' => $facebook !== null,
                'account' => $facebook?->name ?: $facebook?->external_id,
                'note' => $facebook
                    ? null
                    : 'Conecta la página de Facebook desde el asistente para recibir sus comentarios.',
            ],
            [
                'key' => SocialPost::NETWORK_INSTAGRAM,
                'label' => 'Instagram',
                'connected' => $instagram !== null,
                'account' => $instagram?->name ?: $instagram?->external_id,
                'note' => $instagram
                    ? null
                    : 'Conecta la cuenta de Instagram desde el asistente para recibir sus comentarios.',
            ],
            [
                'key' => SocialPost::NETWORK_TIKTOK,
                'label' => 'TikTok',
                'connected' => false,
                'account' => null,
                'note' => 'TikTok todavía no abre sus comentarios a aplicaciones externas: en cuanto aprueben la nuestra, aparecen aquí.',
            ],
        ];
    }
}
