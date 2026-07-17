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
            ->with(['channel:id,type,name,mode', 'guest:id,first_name,last_name', 'assignee:id,name', 'reservation:id,code,payment_status'])
            ->withCount(['messages as unread_count' => fn ($q) => $q->where('direction', 'in')->whereNull('read_at')])
            ->orderByDesc('last_message_at')
            ->take(100)
            ->get()
            ->map(fn (Conversation $c) => $this->serializeConversation($c));

        return Inertia::render('tenant/inbox/Index', [
            'property' => $property->only(['id', 'name']),
            'conversations' => $conversations,
            // Solo canales vivos: los desconectados conservan su historial en
            // la lista, pero no ofrecen selector de modo que "desconfigurar".
            'channels' => Channel::query()->where('active', true)->get()->map(fn (Channel $ch) => [
                'id' => $ch->id,
                'type' => $ch->type,
                'name' => $ch->name,
                'mode' => $ch->mode,
            ]),
            'staff' => User::query()->orderBy('name')->get(['id', 'name']),
            'canManage' => $request->user()->can('reservations.manage'),
            // Botón "Enseñar al asistente": solo si la plataforma habilitó
            // los aprendizajes para este hotel (guidelines_editable).
            'canTeach' => $request->user()->can('reservations.manage')
                && (bool) \App\Models\Central\TenantAgentSetting::for((string) tenant('id'))->guidelines_editable,
            'llmReady' => app(AgentBrain::class)->isConfigured(),
            // Transferencias reportadas que esperan verificación humana
            // (spec-pagos §7.4): aprobar registra el pago y confirma.
            'paymentQueue' => $request->user()->can('reservations.manage')
                ? PaymentRequestController::queue()
                : [],
            // Saldos vencidos (spec-pagos §7.2): el impago NO cancela solo
            // por default — alerta aquí y el equipo decide.
            'overdueBalances' => $request->user()->can('reservations.manage')
                ? $this->overdueBalances()
                : [],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function overdueBalances(): array
    {
        return \App\Models\Reservation::query()
            ->where('status', \App\Enums\ReservationStatus::Confirmed)
            ->where('payment_status', '!=', \App\Enums\PaymentStatus::Paid)
            ->whereNotNull('payment_due_at')
            ->where('payment_due_at', '<', now())
            ->orderBy('payment_due_at')
            ->get()
            ->filter(fn ($r) => $r->pendingBalance() > 0)
            ->map(fn ($r) => [
                'id' => $r->id,
                'code' => $r->displayCode(),
                'guest_name' => $r->guest_name ?? 'Huésped',
                'pending_label' => '$'.number_format($r->pendingBalance(), 2),
                'due_label' => $r->payment_due_at->diffForHumans(),
                'starts_label' => $r->starts_at->format('d/m'),
                'conversation_id' => Conversation::query()
                    ->where('reservation_id', $r->id)->latest('id')->value('id'),
            ])
            ->values()
            ->all();
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

        // El mensaje sale por el transporte del canal (Meta o Evolution;
        // webchat no necesita: el visitante lee por polling).
        app(\App\Services\Channels\OutboundMessenger::class)->pushToConversation($conversation, $data['body']);

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

    /** Elimina la conversación; los mensajes caen en cascada (FK). */
    public function destroy(Conversation $conversation): JsonResponse
    {
        $conversation->delete();

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
            // Chip de pago (spec-pagos §9.3) para conversaciones con reserva.
            'reservation_code' => $c->reservation?->displayCode(),
            'payment_status' => $c->reservation?->payment_status?->value,
            'payment_status_label' => $c->reservation?->payment_status?->label(),
            'payment_pending_verification' => $c->reservation_id !== null && \App\Models\PaymentRequest::query()
                ->where('reservation_id', $c->reservation_id)
                ->where('method', \App\Models\PaymentRequest::METHOD_TRANSFER)
                ->where('status', \App\Models\PaymentRequest::STATUS_PENDING)
                ->exists(),
        ];
    }
}
