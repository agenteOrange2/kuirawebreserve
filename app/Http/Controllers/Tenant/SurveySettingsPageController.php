<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\StaySurvey;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Área AISLADA del cuestionario de experiencia (/ajustes/encuestas): los
 * ASPECTOS que cada hotel pregunta (1 a 5 estrellas) se personalizan
 * aquí; la calificación general y el comentario son fijos para que el
 * promedio siga siendo comparable en el tiempo. Config con superficie
 * propia = página propia, misma regla que métodos de pago y limpieza.
 */
class SurveySettingsPageController extends Controller
{
    public function __invoke(): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        return Inertia::render('tenant/settings/Surveys', [
            'property' => $property->only(['id', 'name']),
            'aspects' => StaySurvey::aspects(),
            // Estado del envío (se administra junto al agradecimiento en
            // /ajustes/avisos); aquí solo se informa.
            'sending' => [
                'thanks_enabled' => (bool) ($settings['post_stay_thanks_enabled'] ?? true),
                'survey_enabled' => (bool) ($settings['post_stay_survey_enabled'] ?? true),
            ],
            'answeredCount' => StaySurvey::query()->submitted()->count(),
            // QR por habitación: una URL fija por cuarto que resuelve la
            // estancia en curso — se imprimen desde aquí y se pegan en la
            // habitación (acceso del documento base: "QR dentro de la
            // habitación").
            'qrRooms' => \App\Models\Room::query()
                ->orderBy('number')
                ->get(['id', 'number', 'name'])
                ->map(fn (\App\Models\Room $room) => [
                    'id' => $room->id,
                    'label' => trim($room->number.' '.($room->name ?? '')),
                    'url' => route('tenant.survey.room', ['room' => $room->id]),
                ]),
        ]);
    }
}
