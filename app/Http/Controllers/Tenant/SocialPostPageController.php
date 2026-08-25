<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\SocialComment;
use App\Models\SocialPost;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Una publicación y sus comentarios, con espacio para trabajarlos: aquí se
 * responde, se manda el privado, se oculta y se salta a la conversación.
 *
 * Vive aparte del índice a propósito: el trabajo de atender comentarios
 * necesita la pantalla completa, no una columna apretada.
 */
class SocialPostPageController extends Controller
{
    public function __invoke(SocialPost $post): Response
    {
        $comments = $post->comments()
            ->with('conversation:id,uuid,lead_status')
            ->orderByDesc('commented_at')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('tenant/social/Show', [
            'post' => [
                'id' => $post->id,
                'network' => $post->network,
                'network_label' => $post->networkLabel(),
                'message' => $post->message,
                'excerpt' => $post->excerpt(),
                'permalink' => $post->permalink,
                'media_url' => $post->media_url,
                'published_label' => $post->published_at?->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm'),
                'last_synced_at' => $post->last_synced_at?->diffForHumans(),
            ],
            'comments' => $comments->map(fn (SocialComment $comment) => [
                'id' => $comment->id,
                'author_name' => $comment->author_name,
                'body' => $comment->body,
                'classification' => $comment->classification,
                'classification_label' => $comment->classificationLabel(),
                'status' => $comment->status,
                'status_label' => $comment->statusLabel(),
                'public_reply_text' => $comment->public_reply_text,
                'public_replied_at' => $comment->public_replied_at?->diffForHumans(),
                'private_reply_sent_at' => $comment->private_reply_sent_at?->diffForHumans(),
                'private_reply_error' => $comment->private_reply_error,
                'can_private_reply' => $comment->canPrivateReply(),
                'hidden' => $comment->isHidden(),
                'hidden_reason' => $comment->hidden_reason,
                'commented_label' => $comment->commented_at?->locale('es')->isoFormat('D MMM, HH:mm'),
                'conversation_uuid' => $comment->conversation?->uuid,
                'lead_status' => $comment->conversation?->lead_status,
                'deleted_from_network' => $comment->deleted_from_network_at !== null,
            ])->all(),
            'stats' => [
                'total' => $comments->count(),
                'pendientes' => $comments->whereIn('status', [
                    SocialComment::STATUS_NEW,
                    SocialComment::STATUS_PENDING_STAFF,
                ])->count(),
                'compras' => $comments->where('classification', SocialComment::CLASS_PURCHASE)->count(),
                'conversaciones' => $comments->whereNotNull('conversation_id')
                    ->pluck('conversation_id')->unique()->count(),
            ],
        ]);
    }
}
