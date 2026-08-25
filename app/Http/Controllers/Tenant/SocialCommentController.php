<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\MetaChannelLink;
use App\Models\SocialComment;
use App\Models\SocialPost;
use App\Services\Meta\MetaApi;
use App\Services\Social\SocialResponder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Acciones del staff sobre un comentario: responder a mano, mandar el
 * privado, ocultar/mostrar y volver a intentar con el asistente.
 *
 * Todo lo que sale a la red pasa por MetaApi (un solo punto de salida) y
 * queda sellado en la fila del comentario.
 */
class SocialCommentController extends Controller
{
    public function __construct(
        protected MetaApi $api,
        protected SocialResponder $responder,
    ) {}

    /** Respuesta pública escrita por una persona. */
    public function reply(Request $request, SocialComment $comment): RedirectResponse
    {
        $data = $request->validate([
            'texto' => ['required', 'string', 'max:600'],
        ]);

        $link = $this->link($comment);

        if (! $link) {
            return back()->with('error', 'La cuenta de esa publicación ya no está conectada.');
        }

        $replyId = $this->api->replyToComment($link, $comment->external_id, $data['texto']);

        if (! $replyId) {
            return back()->with('error', 'La red social rechazó la respuesta. Revisa los permisos de la cuenta.');
        }

        $comment->update([
            'public_reply_text' => $data['texto'],
            'public_reply_external_id' => $replyId,
            'public_replied_at' => now(),
            'status' => SocialComment::STATUS_ANSWERED,
            'handled_by' => $request->user()?->id,
            'handled_at' => now(),
        ]);

        return back()->with('success', 'Respuesta publicada.');
    }

    /** Mensaje privado escrito por una persona (abre la conversación). */
    public function privateReply(Request $request, SocialComment $comment): RedirectResponse
    {
        $data = $request->validate([
            'texto' => ['required', 'string', 'max:900'],
        ]);

        if (! $comment->canPrivateReply()) {
            return back()->with('error', 'Ya se envió el mensaje privado de este comentario, o pasaron más de 7 días.');
        }

        $link = $this->link($comment);

        if (! $link) {
            return back()->with('error', 'La cuenta de esa publicación ya no está conectada.');
        }

        $sent = $this->responder->sendPrivateReply($comment, $link, $data['texto']);

        if (! $sent) {
            return back()->with('error', 'La red social rechazó el mensaje privado.');
        }

        $comment->update([
            'status' => SocialComment::STATUS_ANSWERED,
            'handled_by' => $request->user()?->id,
            'handled_at' => now(),
        ]);

        return back()->with('success', 'Mensaje privado enviado; la conversación está en la bandeja.');
    }

    /** Ocultar o volver a mostrar. Nunca borra y siempre deja rastro. */
    public function hide(Request $request, SocialComment $comment): RedirectResponse
    {
        $data = $request->validate([
            'oculto' => ['required', 'boolean'],
            'motivo' => ['nullable', 'string', 'max:200'],
        ]);

        $link = $this->link($comment);

        if (! $link) {
            return back()->with('error', 'La cuenta de esa publicación ya no está conectada.');
        }

        $userId = $request->user()?->id;

        $done = $data['oculto']
            ? $this->responder->hide($comment, $link, $data['motivo'] ?: 'ocultado por el personal', $userId)
            : $this->responder->unhide($comment, $link, $userId);

        if (! $done) {
            return back()->with('error', 'La red social no aceptó el cambio. Revisa los permisos de la cuenta.');
        }

        return back()->with('success', $data['oculto'] ? 'Comentario oculto.' : 'Comentario visible de nuevo.');
    }

    /** Vuelve a pasar el comentario por el asistente. */
    public function rerun(Request $request, SocialComment $comment): RedirectResponse
    {
        $link = $this->link($comment);

        if (! $link) {
            return back()->with('error', 'La cuenta de esa publicación ya no está conectada.');
        }

        $comment->update(['status' => SocialComment::STATUS_NEW]);
        $this->responder->handle($comment->fresh(), $link);

        return back()->with('success', 'El asistente revisó el comentario otra vez.');
    }

    /**
     * Canal Meta dueño de la publicación del comentario. Se resuelve por la
     * red y la cuenta guardadas en el post, no por el orden de la tabla.
     */
    protected function link(SocialComment $comment): ?MetaChannelLink
    {
        $post = $comment->post;
        $type = $post?->channelType();

        if (! $type) {
            return null;
        }

        return MetaChannelLink::query()
            ->where('tenant_id', (string) tenant('id'))
            ->where('type', $type)
            ->where('active', true)
            ->when(
                $post->account_external_id && $post->network === SocialPost::NETWORK_FACEBOOK,
                fn ($query) => $query->where('external_id', $post->account_external_id),
            )
            ->first();
    }
}
