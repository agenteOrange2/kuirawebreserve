<?php

namespace App\Services;

use App\Events\StaffNotified;
use App\Models\StaffNotification;
use Illuminate\Database\Eloquent\Model;

/**
 * Punto único para avisar al staff en la campana del panel.
 *
 * Deduplica por sujeto: si el mismo huésped manda cinco mensajes seguidos,
 * la campana enseña UN aviso actualizado y no cinco. Un aviso ya leído sí
 * vuelve a levantarse — algo nuevo pasó después de que lo miraron.
 */
class StaffNotifier
{
    public function notify(
        string $type,
        string $title,
        ?string $body = null,
        ?string $url = null,
        ?Model $subject = null,
        ?int $userId = null,
    ): StaffNotification {
        $existing = $subject !== null
            ? StaffNotification::query()
                ->where('type', $type)
                ->where('subject_type', $subject->getMorphClass())
                ->where('subject_id', $subject->getKey())
                ->whereNull('read_at')
                ->latest('id')
                ->first()
            : null;

        if ($existing !== null) {
            $existing->update(['title' => $title, 'body' => $body, 'url' => $url]);
            // Se emite igual: la campana debe volver a moverse.
            StaffNotified::dispatch($existing);

            return $existing;
        }

        $notification = StaffNotification::create([
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'user_id' => $userId,
        ]);

        StaffNotified::dispatch($notification);

        return $notification;
    }
}
