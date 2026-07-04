<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Property;
use App\Models\User;
use App\Services\Agent\AgentBrain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bandeja unificada: todas las conversaciones de todos los canales, con
 * hilo, respuesta del staff (handoff), estados, asignación y modo del canal.
 */
class InboxController extends Controller
{
    public function index(Request $request): Response
    {
        $property = Property::firstOrFail();
        Channel::webchat(); // garantiza el canal base

        $conversations = Conversation::query()
            ->with(['channel:id,type,name,mode', 'guest:id,first_name,last_name', 'assignee:id,name'])
            ->withCount(['messages as unread_count' => fn ($q) => $q->where('direction', 'in')->whereNull('read_at')])
            ->orderByDesc('last_message_at')
            ->take(100)
            ->get()
            ->map(fn (Conversation $c) => $this->serializeConversation($c));

        return Inertia::render('tenant/inbox/Index', [
            'property' => $property->only(['id', 'name']),
            'conversations' => $conversations,
            'channels' => Channel::query()->get()->map(fn (Channel $ch) => [
                'id' => $ch->id,
                'type' => $ch->type,
                'name' => $ch->name,
                'mode' => $ch->mode,
            ]),
            'staff' => User::query()->orderBy('name')->get(['id', 'name']),
            'canManage' => $request->user()->can('reservations.manage'),
            'llmReady' => app(AgentBrain::class)->isConfigured(),
        ]);
    }

    /** Hilo completo (y marca como leído). */
    public function show(Conversation $conversation): JsonResponse
    {
        $conversation->messages()->where('direction', 'in')->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json([
            'conversation' => $this->serializeConversation(
                $conversation->fresh(['channel:id,type,name,mode', 'guest:id,first_name,last_name', 'assignee:id,name'])
                    ->loadCount(['messages as unread_count' => fn ($q) => $q->where('direction', 'in')->whereNull('read_at')]),
            ),
            'messages' => $conversation->messages()->with('sender:id,name')->orderBy('id')->get()->map(fn (Message $m) => [
                'id' => $m->id,
                'direction' => $m->direction,
                'sender_type' => $m->sender_type,
                'sender' => $m->sender?->name,
                'body' => $m->body,
                'at' => $m->created_at->format('d/m H:i'),
            ]),
        ]);
    }

    /**
     * Sugerencia del copiloto: el bot redacta un borrador (solo lectura,
     * sin apartados) que el staff aprueba/edita antes de enviar.
     */
    public function suggest(Conversation $conversation, AgentBrain $brain): JsonResponse
    {
        if (! $brain->isConfigured()) {
            return response()->json(['message' => 'El asistente IA no está disponible (revisa plan o proveedores).'], 422);
        }

        $suggestion = $brain->suggest($conversation);

        if (! $suggestion) {
            return response()->json(['message' => 'No se pudo generar la sugerencia; intenta de nuevo.'], 422);
        }

        return response()->json($suggestion);
    }

    /** Respuesta del staff: toma la conversación (handoff automático). */
    public function reply(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'copilot' => ['sometimes', 'boolean'],
        ]);

        $message = $conversation->messages()->create([
            'direction' => 'out',
            'sender_type' => 'staff',
            'sender_id' => $request->user()?->id,
            'body' => $data['body'],
            // Trazabilidad: la respuesta nació como borrador del copiloto.
            'meta' => ($data['copilot'] ?? false) ? ['copilot' => true] : null,
            'created_at' => now(),
        ]);

        $conversation->update([
            'status' => Conversation::STATUS_OPEN,
            'bot_enabled' => false, // el humano tomó la conversación
            'assigned_to' => $conversation->assigned_to ?? $request->user()?->id,
            'last_message_at' => now(),
        ]);

        // Canales Meta: el mensaje sale por WhatsApp/Messenger/Instagram
        // (webchat no necesita: el visitante lee por polling).
        app(\App\Services\Meta\MetaApi::class)->pushToConversation($conversation, $data['body']);

        return response()->json(['id' => $message->id], 201);
    }

    /** Estado, asignación y devolución al bot. */
    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in([Conversation::STATUS_OPEN, Conversation::STATUS_PENDING, Conversation::STATUS_RESOLVED])],
            'assigned_to' => ['sometimes', 'nullable', 'exists:users,id'],
            'bot_enabled' => ['sometimes', 'boolean'],
        ]);

        $conversation->update($data);

        return response()->json(['ok' => true]);
    }

    /** Modo del canal: auto / copilot / off. */
    public function updateChannel(Request $request, Channel $channel): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(Channel::MODES)],
        ]);

        $channel->update($data);

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeConversation(Conversation $c): array
    {
        return [
            'id' => $c->id,
            'uuid' => $c->uuid,
            'channel' => $c->channel?->type,
            'channel_mode' => $c->channel?->mode,
            'name' => $c->guest?->full_name ?? $c->contact_name ?? 'Visitante',
            'guest_id' => $c->guest_id,
            'status' => $c->status,
            'lead_status' => $c->lead_status,
            'summary' => $c->summary,
            'bot_enabled' => $c->bot_enabled,
            'assigned_to' => $c->assigned_to,
            'assignee' => $c->assignee?->name,
            'unread' => (int) ($c->unread_count ?? 0),
            'last_message_at' => $c->last_message_at?->diffForHumans(short: true),
            'preview' => $c->messages()->latest('id')->value('body'),
        ];
    }
}
