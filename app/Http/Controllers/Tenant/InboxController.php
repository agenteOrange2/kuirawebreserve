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

        // La bandeja activa excluye lo archivado; ?archived=1 muestra el
        // archivo (histórico consultable, restaurable).
        $archived = $request->boolean('archived');

        // Traspasos sin dueño: el asistente pasa el hilo a una persona, se
        // apaga, y el huésped se queda esperando. En cabañas hubo esperas de
        // 1 h 12, 5 h, 9.6 h y 22 h (13 al 15 de septiembre) sin que nada en
        // la pantalla las hiciera visibles.
        $waiting = $request->boolean('esperando');

        $conversations = $this->conversationQuery()
            ->when(
                $archived,
                fn ($q) => $q->whereNotNull('archived_at'),
                fn ($q) => $q->whereNull('archived_at'),
            )
            ->when($waiting, fn ($q) => $q->where('status', Conversation::STATUS_PENDING))
            ->orderByDesc('last_message_at')
            ->take(100)
            ->get();

        $esperas = Conversation::waitingSinceFor(
            $conversations->where('status', Conversation::STATUS_PENDING)->pluck('id')->all(),
        );

        $conversations = $conversations
            ->map(fn (Conversation $c) => $this->serializeConversation($c, $esperas[$c->id] ?? null))
            // Esperando: primero el que lleva más tiempo colgado.
            ->when($waiting, fn ($rows) => $rows->sortByDesc('waiting_minutes')->values());

        return Inertia::render('tenant/inbox/Index', [
            // Para suscribirse al canal privado de la bandeja (Reverb).
            'tenantId' => tenant('id'),
            'property' => $property->only(['id', 'name']),
            'conversations' => $conversations,
            'filters' => ['archived' => $archived, 'esperando' => $waiting],
            'counts' => [
                'active' => Conversation::query()->whereNull('archived_at')
                    ->whereIn('status', [Conversation::STATUS_OPEN, Conversation::STATUS_PENDING])->count(),
                'resolved' => Conversation::query()->whereNull('archived_at')
                    ->where('status', Conversation::STATUS_RESOLVED)->count(),
                'archived' => Conversation::query()->whereNotNull('archived_at')->count(),
                'waiting' => Conversation::query()->whereNull('archived_at')
                    ->where('status', Conversation::STATUS_PENDING)->count(),
            ],
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
            // Los pagos (transferencias por verificar, saldos vencidos)
            // viven en su propia página /pagos — la bandeja es solo
            // conversaciones (feedback 2026-07-17).
        ]);
    }

    /** Hilo completo (y marca como leído). */
    public function show(Conversation $conversation): JsonResponse
    {
        $conversation->messages()->where('direction', 'in')->whereNull('read_at')->update(['read_at' => now()]);

        $refreshed = $this->conversationQuery()->findOrFail($conversation->getKey());

        return response()->json([
            'conversation' => $this->serializeConversation(
                $refreshed,
                $refreshed->status === Conversation::STATUS_PENDING ? $refreshed->waitingSince() : null,
            ),
            // La reserva y su dinero en la misma pantalla donde se contesta:
            // antes había que salir a /reservas o /pagos para saber si el
            // huésped ya había pagado o hasta qué hora se le sostiene.
            'reservation' => $this->reservationCard($refreshed),
            'messages' => $conversation->messages()->with(['sender:id,name', 'media'])->orderBy('id')->get()->map(fn (Message $m) => [
                'id' => $m->id,
                'direction' => $m->direction,
                'sender_type' => $m->sender_type,
                'sender' => $m->sender?->name,
                'body' => $m->body,
                // El staff tiene que saber que ese texto salió de un audio:
                // la transcripción se equivoca con fechas y nombres.
                'voice_note' => (bool) ($m->meta['voice_note'] ?? false),
                // El canal lo rechazó: el huésped NO lo recibió.
                'undelivered' => (bool) ($m->meta['undelivered'] ?? false),
                'attachments' => $m->attachmentsPayload(),
                'at' => $m->created_at->format('d/m H:i'),
            ]),
        ]);
    }

    /**
     * Adjunto entrante de WhatsApp (imagen/PDF): privado — se valida que
     * el archivo pertenezca a un mensaje de ESTA conversación.
     */
    public function attachment(Conversation $conversation, \Spatie\MediaLibrary\MediaCollections\Models\Media $media): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $belongsToConversation = $media->model_type === (new Message)->getMorphClass()
            && $media->collection_name === 'attachments'
            && $conversation->messages()->whereKey($media->model_id)->exists();

        abort_unless($belongsToConversation, 404);

        return response()->file($media->getPath());
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
            // Con adjunto el texto es opcional: mandar solo la foto es
            // legítimo (el comprobante, la habitación, el mapa).
            'body' => [$request->hasFile('attachment') ? 'nullable' : 'required', 'string', 'max:2000'],
            'copilot' => ['sometimes', 'boolean'],
            'attachment' => ['sometimes', 'file', 'mimes:jpeg,png,webp,pdf', 'max:6144'],
        ], [
            'attachment.max' => 'El archivo puede pesar máximo 6 MB.',
            'attachment.mimes' => 'Se pueden mandar imágenes (JPG, PNG, WebP) o PDF.',
        ]);

        $message = $conversation->messages()->create([
            'direction' => 'out',
            'sender_type' => 'staff',
            'sender_id' => $request->user()?->id,
            'body' => $data['body'] ?? '',
            // Trazabilidad: la respuesta nació como borrador del copiloto.
            'meta' => ($data['copilot'] ?? false) ? ['copilot' => true] : null,
            'created_at' => now(),
        ]);

        $attachment = $request->file('attachment');

        if ($attachment !== null) {
            $message->addMedia($attachment)->toMediaCollection('attachments');
        }

        $conversation->update([
            'status' => Conversation::STATUS_OPEN,
            'bot_enabled' => false, // el humano tomó la conversación
            'assigned_to' => $conversation->assigned_to ?? $request->user()?->id,
            'last_message_at' => now(),
            'archived_at' => null, // responder la regresa a la bandeja activa
        ]);

        // El mensaje sale por el transporte del canal (Meta o Evolution;
        // webchat no necesita: el visitante lee por polling).
        $messenger = app(\App\Services\Channels\OutboundMessenger::class);
        $delivered = true;

        if ($attachment !== null) {
            $media = $message->getFirstMedia('attachments');

            $delivered = $media !== null && $messenger->pushMediaToConversation(
                $conversation,
                $media->getPath(),
                (string) $media->mime_type,
                $media->file_name,
                $data['body'] ?: null,
            );
        } elseif ($data['body'] !== null && $data['body'] !== '') {
            // El resultado se ignoraba: si el canal rechazaba el texto (fuera
            // de la ventana de 24 h, instancia caída, número inválido) la
            // bandeja lo mostraba como enviado y el huésped nunca lo recibía.
            // El webchat no tiene transporte y no cuenta como fallo: el
            // visitante lo lee al refrescar su hilo.
            $delivered = $messenger->pushToConversation($conversation, $data['body'])
                || $conversation->channel?->type === Channel::TYPE_WEBCHAT;

            if (! $delivered) {
                $message->forceFill([
                    'meta' => array_merge($message->meta ?? [], ['undelivered' => true]),
                ])->saveQuietly();
            }
        }

        return response()->json([
            'id' => $message->id,
            // El adjunto queda en el hilo aunque el canal no lo soporte; el
            // staff necesita saber que al huésped no le llegó.
            'delivered' => $delivered,
        ], 201);
    }

    /** Estado, asignación, devolución al bot y archivado. */
    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in([Conversation::STATUS_OPEN, Conversation::STATUS_PENDING, Conversation::STATUS_RESOLVED])],
            'assigned_to' => ['sometimes', 'nullable', 'exists:users,id'],
            'bot_enabled' => ['sometimes', 'boolean'],
            'archived' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('archived', $data)) {
            // Archivar implica cerrar; al desarchivar conserva su estado.
            $data['archived_at'] = $data['archived'] ? now() : null;

            if ($data['archived']) {
                $data['status'] ??= Conversation::STATUS_RESOLVED;
            }

            unset($data['archived']);
        }

        $conversation->update($data);

        return response()->json(['ok' => true]);
    }

    /** Archiva de golpe todas las conversaciones ya resueltas. */
    public function archiveResolved(): JsonResponse
    {
        $archived = Conversation::query()
            ->where('status', Conversation::STATUS_RESOLVED)
            ->whereNull('archived_at')
            ->update(['archived_at' => now()]);

        return response()->json(['archived' => $archived]);
    }

    /** Elimina la conversación; los mensajes caen en cascada (FK). */
    public function destroy(Conversation $conversation): JsonResponse
    {
        $conversation->delete();

        return response()->json(['ok' => true]);
    }

    /** Vacía el archivo: borra definitivamente todas las archivadas. */
    public function destroyArchived(): JsonResponse
    {
        $deleted = 0;

        Conversation::query()
            ->whereNotNull('archived_at')
            ->orderBy('id')
            ->chunkById(100, function ($conversations) use (&$deleted): void {
                foreach ($conversations as $conversation) {
                    $conversation->delete();
                    $deleted++;
                }
            });

        return response()->json(['deleted' => $deleted]);
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
     * Consulta base de una conversación lista para serializar: trae de una
     * vez todo lo que pinta la bandeja, incluida la bandera de transferencia
     * por verificar (EXISTS). La lista carga 100 de golpe en cada refresco,
     * así que serializar NO debe costar consultas extra por fila.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Conversation>
     */
    protected function conversationQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Conversation::query()
            ->with([
                'channel:id,type,name,mode',
                'guest:id,first_name,last_name,phone',
                'assignee:id,name',
                // El folio que se le dio al huésped: el del GRUPO cuando la
                // reserva es de un grupo (el chip mostraba el de una sola
                // cabaña y el huésped contestaba con ese, GRP-2026-0152).
                'reservation:id,code,payment_status,reservation_group_id',
                'reservation.group:id,code',
            ])
            ->withCount(['messages as unread_count' => fn ($q) => $q->where('direction', 'in')->whereNull('read_at')])
            ->withExists(['reservation as payment_pending_verification' => fn ($q) => $q
                // El cobro de un grupo cuelga del GRP-, no de la habitación:
                // sin esto, un comprobante de grupo no encendía el chip.
                ->where(fn ($reservation) => $reservation
                    ->whereHas('paymentRequests', fn ($pr) => $pr
                        ->where('method', \App\Models\PaymentRequest::METHOD_TRANSFER)
                        ->where('status', \App\Models\PaymentRequest::STATUS_PENDING))
                    ->orWhereHas('group.paymentRequests', fn ($pr) => $pr
                        ->where('method', \App\Models\PaymentRequest::METHOD_TRANSFER)
                        ->where('status', \App\Models\PaymentRequest::STATUS_PENDING))),
                // De dónde salió la conversación: saber que nació de un
                // comentario cambia el tono con el que se contesta.
                'socialComments as from_social']);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Teléfono presentable del contacto, o null. En WhatsApp a veces llega
     * un identificador interno de 15+ dígitos (LID) que no es un número:
     * ese no se muestra; se cae al teléfono de la ficha del huésped.
     *
     * @return array{label: string, digits: string}|null
     */
    protected function displayPhone(Conversation $c): ?array
    {
        $candidates = [
            $c->phoneIsIdentity() ? $c->contact_phone : null,
            $c->guest?->phone,
        ];

        foreach ($candidates as $raw) {
            $digits = preg_replace('/\D+/', '', (string) $raw);

            if (strlen($digits) < 10 || strlen($digits) > 13) {
                continue;
            }

            // México: 52 + (1 heredado de WhatsApp) + 10 dígitos.
            if (str_starts_with($digits, '521') && strlen($digits) === 13) {
                $digits = '52'.substr($digits, 3);
            }

            if (strlen($digits) === 10) {
                $digits = '52'.$digits;
            }

            $national = substr($digits, -10);
            $country = substr($digits, 0, strlen($digits) - 10);

            return [
                'label' => '+'.$country.' '.substr($national, 0, 3).' '.substr($national, 3, 3).' '.substr($national, 6),
                'digits' => $digits,
            ];
        }

        return null;
    }

    /**
     * La reserva de la conversación con su dinero: folio (GRP- si es grupo),
     * fechas, cuánto lleva pagado, hasta cuándo se sostiene el apartado y el
     * comprobante que espera verificación con lo que se leyó en él. Solo para
     * la conversación abierta: en la lista sería una consulta por fila.
     *
     * @return array<string, mixed>|null
     */
    protected function reservationCard(Conversation $conversation): ?array
    {
        $reservation = $conversation->reservation()
            ->with(['roomType:id,name', 'group.reservations.roomType:id,name'])
            ->first();

        if (! $reservation) {
            return null;
        }

        $group = $reservation->group;
        $rooms = $group?->reservations ?? collect([$reservation]);
        $total = round((float) $rooms->sum('total_amount'), 2);
        $paid = round((float) $rooms->sum(fn (\App\Models\Reservation $r) => $r->paidTotal()), 2);

        $request = \App\Models\PaymentRequest::query()
            ->where('method', \App\Models\PaymentRequest::METHOD_TRANSFER)
            ->where('status', \App\Models\PaymentRequest::STATUS_PENDING)
            ->where(fn ($query) => $query
                ->whereIn('reservation_id', $rooms->pluck('id'))
                ->when($group, fn ($query, $g) => $query->orWhere('reservation_group_id', $g->id)))
            ->with('media')
            ->latest('id')
            ->first();

        return [
            'code' => $group?->displayCode() ?? $reservation->displayCode(),
            'is_group' => $group !== null,
            'url' => $group
                ? route('tenant.groups.show', $group, absolute: false)
                : route('tenant.reservations.detail', $reservation, absolute: false),
            'status' => $reservation->status->value,
            'status_label' => $reservation->status->label(),
            'rooms_count' => $rooms->count(),
            'rooms_label' => $rooms->map(fn (\App\Models\Reservation $r) => $r->roomType?->name)->filter()->unique()->implode(' · '),
            'guests' => (int) $rooms->sum('num_people'),
            'starts_label' => $reservation->starts_at->locale('es')->isoFormat('ddd D MMM HH:mm'),
            'ends_label' => $reservation->ends_at->locale('es')->isoFormat('ddd D MMM HH:mm'),
            'total_label' => '$'.number_format($total, 2),
            'paid_label' => '$'.number_format($paid, 2),
            'pending_label' => '$'.number_format(max(0, round($total - $paid, 2)), 2),
            'payment_status' => $reservation->payment_status?->value,
            'payment_status_label' => $reservation->payment_status?->label(),
            // El reloj del apartado: es lo que pregunta el huésped y lo que
            // el personal necesita ver antes de contestarle.
            'hold_expires_label' => $reservation->status === \App\Enums\ReservationStatus::Pending && $reservation->hold_expires_at?->isFuture()
                ? $reservation->hold_expires_at->locale('es')->isoFormat('ddd D MMM HH:mm')
                : null,
            'request' => $request ? [
                'id' => $request->id,
                'concept' => $request->conceptLabel(),
                'amount_label' => $request->amountLabel(),
                'has_receipt' => $request->media->contains('collection_name', 'receipt'),
                'verdict' => $request->meta['receipt_check']['verdict'] ?? null,
                'summary' => $request->meta['receipt_check']['summary'] ?? null,
                'warnings' => $request->meta['receipt_check']['warnings'] ?? [],
            ] : null,
        ];
    }

    protected function serializeConversation(Conversation $c, ?\Illuminate\Support\Carbon $waitingSince = null): array
    {
        return [
            'id' => $c->id,
            'uuid' => $c->uuid,
            'channel' => $c->channel?->type,
            'channel_mode' => $c->channel?->mode,
            'name' => $c->guest?->full_name ?? $c->contact_name ?? 'Visitante',
            // El número para llamarle o escribirle: en WhatsApp es el propio
            // contacto; en Messenger/IG solo si la ficha del huésped lo tiene.
            'phone' => $this->displayPhone($c),
            'guest_id' => $c->guest_id,
            'status' => $c->status,
            'archived' => $c->archived_at !== null,
            'lead_status' => $c->lead_status,
            'summary' => $c->summary,
            'bot_enabled' => $c->bot_enabled,
            'assigned_to' => $c->assigned_to,
            'assignee' => $c->assignee?->name,
            'unread' => (int) ($c->unread_count ?? 0),
            'from_social' => (bool) ($c->from_social ?? false),
            'last_message_at' => $c->last_message_at?->diffForHumans(short: true),
            'preview' => $c->last_message_preview,
            // Chip de pago (spec-pagos §9.3) para conversaciones con reserva.
            'reservation_code' => $c->reservation?->group?->displayCode() ?? $c->reservation?->displayCode(),
            'payment_status' => $c->reservation?->payment_status?->value,
            'payment_status_label' => $c->reservation?->payment_status?->label(),
            'payment_pending_verification' => (bool) ($c->payment_pending_verification ?? false),
            // Cuánto lleva esperando a una persona del hotel (solo pendientes).
            'waiting_minutes' => $waitingSince ? (int) $waitingSince->diffInMinutes(now()) : null,
            'waiting_label' => $waitingSince ? $this->waitingLabel((int) $waitingSince->diffInMinutes(now())) : null,
        ];
    }

    /** "1 h 12 min" se lee de un vistazo; "72 minutos", no. */
    protected function waitingLabel(int $minutes): string
    {
        if ($minutes < 60) {
            return max($minutes, 1).' min';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $hours >= 24
            ? intdiv($hours, 24).' d '.($hours % 24).' h'
            : $hours.' h'.($rest > 0 ? ' '.$rest.' min' : '');
    }
}
