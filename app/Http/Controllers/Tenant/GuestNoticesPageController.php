<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Área AISLADA de avisos al huésped (/ajustes/avisos): todo lo que el
 * hotel le manda solo al huésped — canal directo de WhatsApp, recordatorios
 * de llegada y agradecimiento post-estancia con encuesta y link de reseñas.
 * Vivía revuelto dentro de Métodos de pago; nada de esto es dinero, así que
 * se aplica la misma regla que wizard/mails/limpieza: superficie propia,
 * página propia.
 */
class GuestNoticesPageController extends Controller
{
    public function __invoke(): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        return Inertia::render('tenant/settings/GuestNotices', [
            'property' => $property->only(['id', 'name']),
            'settings' => [
                'direct_notify_channel' => $settings['direct_notify_channel'] ?? 'auto',
                'arrival_reminder_enabled' => (bool) ($settings['arrival_reminder_enabled'] ?? true),
                // Aviso el día de la llegada (segundo recordatorio, N horas
                // antes de la entrada) — ver ReservationPolicy::arrivalSoon*.
                'arrival_soon_enabled' => (bool) ($settings['arrival_soon_enabled'] ?? true),
                'arrival_soon_hours' => (int) ($settings['arrival_soon_hours'] ?? 2),
                // Agradecimiento al salir (post-estancia), con el link de
                // reseñas del hotel si lo capturó.
                'post_stay_thanks_enabled' => (bool) ($settings['post_stay_thanks_enabled'] ?? true),
                'post_stay_survey_enabled' => (bool) ($settings['post_stay_survey_enabled'] ?? true),
                'review_url' => $settings['review_url'] ?? '',
            ],
            // Qué canales de WhatsApp existen de verdad, para que el selector
            // de avisos directos avise si eliges uno que no está conectado.
            'notifyChannels' => [
                'meta_whatsapp' => \App\Models\Central\MetaChannelLink::query()
                    ->where('tenant_id', tenant('id'))->where('type', 'whatsapp')->where('active', true)->exists(),
                'evolution' => \App\Models\Central\EvolutionChannelLink::query()
                    ->where('tenant_id', tenant('id'))->where('active', true)->exists(),
            ],
        ]);
    }
}
