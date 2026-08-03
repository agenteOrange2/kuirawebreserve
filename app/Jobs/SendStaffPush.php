<?php

namespace App\Jobs;

use App\Models\StaffNotification;
use App\Services\WebPushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Manda el push fuera de la petición: hablar con Google/Mozilla/Apple tarda,
 * y el huésped que escribió por WhatsApp no tiene por qué esperar a que su
 * mensaje termine de avisarle al mostrador.
 *
 * Corre bajo el tenant del aviso (stancl serializa el contexto en el job).
 */
class SendStaffPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $notificationId) {}

    public function handle(WebPushSender $sender): void
    {
        $notification = StaffNotification::find($this->notificationId);

        if ($notification === null) {
            return;
        }

        $sender->send([
            'title' => $notification->title,
            'body' => $notification->body,
            'url' => $notification->url,
            'tag' => $notification->type.':'.$notification->id,
        ], $notification->user_id);
    }
}
