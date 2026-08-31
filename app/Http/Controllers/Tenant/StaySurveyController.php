<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\StaySurvey;
use App\Services\Channels\DirectGuestMessenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Seguimiento de una respuesta del cuestionario (/encuestas): cerrarla con
 * nota, reabrirla, levantar la incidencia que salió de la queja,
 * responderle al huésped o borrar una respuesta de prueba. Antes la
 * pantalla era solo lectura: una queja de dos estrellas se leía y ahí
 * moría.
 */
class StaySurveyController extends Controller
{
    /** Cerrar el caso (o reabrirlo) con constancia de quién y cuándo. */
    public function handle(Request $request, StaySurvey $survey): JsonResponse
    {
        $data = $request->validate([
            'handled' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $survey->update($data['handled']
            ? [
                'handled_at' => now(),
                'handled_by' => $request->user()->id,
                'handled_notes' => $data['notes'] ?? null,
            ]
            : [
                'handled_at' => null,
                'handled_by' => null,
                'handled_notes' => null,
            ]);

        return response()->json([
            'handled_at' => $survey->handled_at?->format('d/m/Y H:i'),
            'handled_by' => $survey->handler?->name,
            'handled_notes' => $survey->handled_notes,
        ]);
    }

    /**
     * Levanta la incidencia que la queja destapó y la deja ligada a la
     * respuesta: el comentario del huésped deja de ser un texto suelto y
     * se convierte en trabajo con responsable y tiempo objetivo.
     */
    public function raiseIncident(Request $request, StaySurvey $survey): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'category' => ['nullable', 'string'],
            'priority' => ['required', 'in:low,medium,high'],
        ]);

        if ($survey->incident_id !== null) {
            return response()->json([
                'message' => 'Esta respuesta ya tiene una incidencia levantada.',
            ], 422);
        }

        $incident = Incident::create([
            'room_id' => $survey->stay?->room_id,
            'stay_id' => $survey->stay_id,
            'title' => $data['title'],
            'category' => $data['category'] ?: null,
            // La levantó el huésped en su encuesta, no el staff.
            'source' => Incident::SOURCE_GUEST,
            'description' => $survey->comment
                ? "Reportado en la encuesta de experiencia: \"{$survey->comment}\""
                : 'Reportado en la encuesta de experiencia.',
            'priority' => $data['priority'],
            'status' => Incident::STATUS_OPEN,
            'reported_by' => $request->user()->id,
        ]);

        $survey->update(['incident_id' => $incident->id]);

        return response()->json([
            'incident_id' => $incident->id,
            'url' => route('tenant.incidents.show', $incident->id, false),
        ], 201);
    }

    /**
     * Responderle al huésped por sus canales (WhatsApp y correo). El texto
     * lo escribe quien atiende: una disculpa de plantilla se nota.
     */
    public function reply(Request $request, StaySurvey $survey, DirectGuestMessenger $messenger): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $guest = $survey->guest ?? $survey->stay?->guest;

        if (! $guest || (blank($guest->phone) && blank($guest->email))) {
            return response()->json([
                'message' => 'Este huésped no dejó teléfono ni correo.',
            ], 422);
        }

        $sent = $messenger->sendToGuestFull(
            $guest,
            'Sobre tu opinión',
            $data['message'],
        );

        if (! $sent) {
            return response()->json([
                'message' => 'No salió por ningún canal. Revisa el WhatsApp del hotel y el correo.',
            ], 422);
        }

        return response()->json(['ok' => true]);
    }

    /** Respuestas de prueba o spam: se borran. */
    public function destroy(StaySurvey $survey): JsonResponse
    {
        $survey->delete();

        return response()->json(status: 204);
    }
}
