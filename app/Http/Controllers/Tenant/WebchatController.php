<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Property;
use App\Services\Agent\AgentBrain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webchat público del hotel (sin login): página /chat + API de sesión y
 * mensajes por UUID. Primer canal del asistente IA (spec-pendientes §4.5).
 */
class WebchatController extends Controller
{
    public function page(AgentBrain $brain): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];
        $channel = Channel::webchat();

        return Inertia::render('tenant/webchat/Chat', [
            'hotel' => [
                'name' => $property->name,
                'phone' => $settings['phone'] ?? null,
            ],
            'botActive' => $channel->active && $channel->mode === 'auto' && $brain->isConfigured(),
        ]);
    }

    /** Crea la conversación del visitante y devuelve su UUID. */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        $conversation = Channel::webchat()->conversations()->create([
            'contact_name' => $data['name'] ?? null,
            'status' => Conversation::STATUS_OPEN,
            'last_message_at' => now(),
        ]);

        $conversation->messages()->create([
            'direction' => 'out',
            'sender_type' => 'system',
            'body' => '¡Hola'.(filled($data['name'] ?? null) ? ' '.$data['name'] : '').'! Soy el asistente del hotel. Puedo darte precios, revisar disponibilidad y apartarte una habitación. ¿En qué te ayudo?',
            'created_at' => now(),
        ]);

        return response()->json(['uuid' => $conversation->uuid], 201);
    }

    /** Mensajes de la conversación (el visitante los consulta por UUID). */
    public function messages(string $uuid): JsonResponse
    {
        $conversation = Conversation::query()->where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'status' => $conversation->status,
            'bot_enabled' => $conversation->bot_enabled,
            'messages' => $conversation->messages()->orderBy('id')->get()->map(fn (Message $m) => [
                'id' => $m->id,
                'from' => $m->direction === 'in' ? 'me' : ($m->sender_type === 'staff' ? 'staff' : 'bot'),
                'body' => $m->body,
                'at' => $m->created_at->format('H:i'),
            ]),
        ]);
    }

    /** El visitante envía un mensaje; el bot responde si el canal lo permite. */
    public function send(Request $request, string $uuid, AgentBrain $brain): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
        ]);

        $conversation = Conversation::query()->where('uuid', $uuid)->firstOrFail();

        if ($conversation->status === Conversation::STATUS_RESOLVED) {
            $conversation->update(['status' => Conversation::STATUS_OPEN]);
        }

        $conversation->messages()->create([
            'direction' => 'in',
            'sender_type' => 'visitor',
            'body' => $data['body'],
            'created_at' => now(),
        ]);
        $conversation->update(['last_message_at' => now()]);

        $channel = $conversation->channel;
        $reply = null;

        if ($channel->mode === 'auto' && $conversation->bot_enabled && $brain->isConfigured()) {
            $reply = $brain->reply($conversation);
        } elseif ($conversation->status !== Conversation::STATUS_PENDING) {
            // Sin bot: la conversación queda esperando a un humano.
            $conversation->update(['status' => Conversation::STATUS_PENDING]);
        }

        return response()->json([
            'reply' => $reply ? [
                'id' => $reply->id,
                'from' => $reply->sender_type === 'staff' ? 'staff' : 'bot',
                'body' => $reply->body,
                'at' => $reply->created_at->format('H:i'),
            ] : null,
            'bot_enabled' => $conversation->refresh()->bot_enabled,
        ]);
    }
}
