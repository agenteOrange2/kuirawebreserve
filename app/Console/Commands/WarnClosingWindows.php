<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\StaffNotification;
use App\Services\StaffNotifier;
use Illuminate\Console\Command;

/**
 * La ventana de 24 h se cierra y nadie se da cuenta hasta que el mensaje
 * del personal sale con "No se entregó".
 *
 * Auditoría cabañas 24-sep → 8-oct (2026-10-08): los traspasos se
 * contestaban de 2 a 40 horas después, y las respuestas que llegaban
 * pasadas las 24 h rebotaban (#131047). Este aviso suena en la campana
 * cuando a un huésped que espera al personal le quedan 4 horas o menos de
 * ventana, una vez por cada mensaje del huésped. Después de eso solo se le
 * puede escribir con una plantilla aprobada por Meta.
 *
 * Correr por tenant: tenants:run.
 */
class WarnClosingWindows extends Command
{
    protected $signature = 'conversations:warn-closing-windows';

    protected $description = 'Avisa al personal cuando a un huésped que espera respuesta se le cierra la ventana de 24 h';

    /** Horas antes del cierre en que suena el aviso. */
    public const WARN_HOURS = 4;

    public function handle(StaffNotifier $notifier): int
    {
        $from = now()->subHours(Conversation::WINDOW_HOURS);
        $until = now()->subHours(Conversation::WINDOW_HOURS - self::WARN_HOURS);
        $avisos = 0;

        // Esperan a una persona del hotel (pendientes, sin archivar) en un
        // canal con ventana. Las que atiende el bot no: él contesta al
        // instante y la ventana nunca se le cierra con el huésped esperando.
        $conversations = Conversation::query()
            ->with(['channel:id,type', 'guest:id,first_name,last_name'])
            ->whereNull('archived_at')
            ->where('status', Conversation::STATUS_PENDING)
            ->whereHas('channel', fn ($q) => $q->whereIn('type', Conversation::WINDOW_CHANNELS))
            ->get();

        foreach ($conversations as $conversation) {
            $lastIn = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('direction', 'in')
                ->latest('id')
                ->first(['id', 'created_at']);

            if ($lastIn === null || $lastIn->created_at->lt($from) || $lastIn->created_at->gt($until)) {
                continue;
            }

            // Si después del huésped ya escribió alguien del hotel, no espera.
            $answered = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('id', '>', $lastIn->id)
                ->where('sender_type', 'staff')
                ->exists();

            $key = 'window-warning:'.$lastIn->id;

            if ($answered || $conversation->followupSent($key)) {
                continue;
            }

            $conversation->markFollowup($key);
            $avisos++;

            $closes = $lastIn->created_at->copy()->addHours(Conversation::WINDOW_HOURS);
            $minutes = max(1, (int) now()->diffInMinutes($closes, false));
            $left = $minutes >= 60 ? intdiv($minutes, 60).' h '.($minutes % 60).' min' : $minutes.' min';
            $who = $conversation->guest?->full_name ?: ($conversation->contact_name ?: 'Un huésped');

            $notifier->notify(
                type: StaffNotification::TYPE_MESSAGE,
                title: 'Se cierra la ventana para contestar',
                body: "A {$who} le quedan {$left} para contestarle por ".(\App\Models\Channel::TYPE_LABELS[$conversation->channel?->type] ?? 'el chat')
                    .'. Después, WhatsApp solo deja escribirle con una plantilla aprobada.',
                url: '/bandeja?esperando=1',
                subject: $conversation,
            );
        }

        $this->info("Avisos de ventana por cerrar: {$avisos}.");

        return self::SUCCESS;
    }
}
