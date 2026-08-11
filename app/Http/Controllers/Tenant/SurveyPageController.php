<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\StaySurvey;
use App\Services\ReservationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cuestionario público de experiencia (/encuesta/{token}): el huésped
 * llega desde el mensaje de agradecimiento post-estancia y responde una
 * sola vez. Misma apariencia que el resto de páginas públicas del hotel.
 */
class SurveyPageController extends Controller
{
    /**
     * Umbral de "mala evaluación": general o cualquier aspecto en 1-2
     * estrellas dispara la alerta a la campana del staff.
     */
    protected const LOW_RATING = 2;

    /**
     * Entrada del QR impreso en la habitación (/encuesta/habitacion/{room}):
     * resuelve la estancia en curso (o la salida de las últimas 48 horas
     * que aún no responde) y manda a SU encuesta por token. El QR es uno
     * solo por habitación y se imprime desde /ajustes/encuestas.
     */
    public function room(\App\Models\Room $room)
    {
        $stay = $room->stays()->active()->latest('check_in_at')->first()
            ?? $room->stays()
                ->whereNotNull('check_out_at')
                ->where('check_out_at', '>=', now()->subHours(48))
                ->latest('check_out_at')
                ->first();

        if ($stay === null) {
            $property = Property::firstOrFail();
            $appearance = $property->wizardAppearance();

            return Inertia::render('tenant/survey/Show', [
                'token' => null,
                'appearance' => $appearance,
                'property' => [
                    'name' => $property->name,
                    'logo_url' => $appearance['logo_url'],
                ],
                'aspects' => StaySurvey::aspects(),
                'submitted' => false,
                // Sin estancia que evaluar: la página lo explica amable.
                'unavailable' => true,
                'stay' => ['check_in' => null, 'check_out' => null],
                'review_url' => app(ReservationPolicy::class)->reviewUrl(),
            ]);
        }

        return redirect()->route('tenant.survey', ['token' => StaySurvey::forStay($stay)->token]);
    }

    public function page(string $token): Response
    {
        $survey = StaySurvey::query()->where('token', $token)->with('stay')->firstOrFail();

        $property = Property::firstOrFail();
        $appearance = $property->wizardAppearance();

        return Inertia::render('tenant/survey/Show', [
            'token' => $survey->token,
            'appearance' => $appearance,
            'property' => [
                'name' => $property->name,
                'logo_url' => $appearance['logo_url'],
            ],
            // Aspectos personalizados del hotel (/ajustes/encuestas).
            'aspects' => StaySurvey::aspects(),
            'submitted' => $survey->isSubmitted(),
            'unavailable' => false,
            'stay' => [
                'check_in' => $survey->stay?->check_in_at?->locale('es')->isoFormat('D [de] MMMM'),
                'check_out' => $survey->stay?->check_out_at?->locale('es')->isoFormat('D [de] MMMM'),
            ],
            // Para invitar a la reseña pública DESPUÉS de responder.
            'review_url' => app(ReservationPolicy::class)->reviewUrl(),
        ]);
    }

    /** Guarda la respuesta (stateless, una sola vez por token). */
    public function store(Request $request, string $token): JsonResponse
    {
        $survey = StaySurvey::query()->where('token', $token)->firstOrFail();

        if ($survey->isSubmitted()) {
            return response()->json([
                'message' => 'Esta encuesta ya fue respondida. Gracias por tu opinión.',
            ], 409);
        }

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'answers' => ['nullable', 'array'],
            'answers.*' => ['integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        // Solo los aspectos vigentes del hotel: llaves ajenas se descartan.
        $validKeys = collect(StaySurvey::aspects())->pluck('key')->all();
        $answers = collect($validated['answers'] ?? [])
            ->only($validKeys)
            ->map(fn ($value) => (int) $value)
            ->all();

        $survey->update([
            'rating' => $validated['rating'],
            'answers' => $answers ?: null,
            'comment' => $validated['comment'] ?? null,
            'submitted_at' => now(),
        ]);

        // Mala evaluación (general o cualquier aspecto en 1-2): alerta a la
        // campana del staff para atenderla antes de que sea reseña pública.
        // Módulo satisfacción avanzada (Empresarial); sin tenant (tests)
        // aplica.
        $tenant = tenant();
        $alertsEnabled = $tenant === null || $tenant->hasModule('encuestas-avanzado');
        $lowAspect = collect($answers)->filter(fn (int $value) => $value <= self::LOW_RATING)->isNotEmpty();

        if ($alertsEnabled && ($validated['rating'] <= self::LOW_RATING || $lowAspect)) {
            try {
                $survey->loadMissing('stay.room:id,number');
                $room = $survey->stay?->room?->number;

                app(\App\Services\StaffNotifier::class)->notify(
                    type: \App\Models\StaffNotification::TYPE_SURVEY,
                    title: 'Evaluación baja · '.$validated['rating'].'/5',
                    body: trim(sprintf(
                        '%s%s%s',
                        $survey->stay?->guest_name ?? 'Huésped',
                        $room !== null ? ' · Habitación '.$room : '',
                        filled($validated['comment'] ?? null) ? ' · "'.Str::limit($validated['comment'], 80).'"' : '',
                    )),
                    url: '/encuestas',
                    subject: $survey,
                );
            } catch (\Throwable $e) {
                report($e); // la alerta nunca debe tirar la respuesta del huésped
            }
        }

        return response()->json(['submitted' => true]);
    }
}
