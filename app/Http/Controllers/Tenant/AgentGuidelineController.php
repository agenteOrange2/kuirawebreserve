<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AgentGuideline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aprendizajes del asistente: el staff captura correcciones de
 * conversaciones reales y el bot las recibe como reglas en su prompt.
 * Es la vía para "alimentar" al bot con control humano, lección por
 * lección — nunca aprende solo ni en silencio.
 */
class AgentGuidelineController extends Controller
{
    public function __construct()
    {
        // Palanca del super-admin (mismo patrón que /asistente/contexto):
        // apagada, ni el panel ni la Bandeja pueden capturar lecciones.
        abort_unless(
            (bool) \App\Models\Central\TenantAgentSetting::for((string) tenant('id'))->guidelines_editable,
            403,
            'Los aprendizajes del bot los gestiona la plataforma para este hotel.',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'instruction' => ['required', 'string', 'min:10', 'max:500'],
            'source_conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
        ]);

        if (AgentGuideline::query()->count() >= 50) {
            return response()->json([
                'message' => 'Máximo 50 aprendizajes: depura los que ya no apliquen antes de agregar más (un prompt kilométrico también confunde al bot).',
            ], 422);
        }

        $guideline = AgentGuideline::create([
            ...$data,
            'created_by' => $request->user()->id,
            'sort_order' => (int) AgentGuideline::query()->max('sort_order') + 1,
        ]);

        return response()->json(self::serialize($guideline), 201);
    }

    public function update(Request $request, AgentGuideline $guideline): JsonResponse
    {
        $data = $request->validate([
            'instruction' => ['sometimes', 'string', 'min:10', 'max:500'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $guideline->update($data);

        return response()->json(self::serialize($guideline->fresh()));
    }

    public function destroy(AgentGuideline $guideline): JsonResponse
    {
        $guideline->delete();

        return response()->json(status: 204);
    }

    /** @return array<string, mixed> */
    public static function serialize(AgentGuideline $guideline): array
    {
        return [
            'id' => $guideline->id,
            'instruction' => $guideline->instruction,
            'active' => $guideline->active,
            'source_conversation_id' => $guideline->source_conversation_id,
            'created_by' => $guideline->createdBy?->name,
            'created_at' => $guideline->created_at->toIso8601String(),
            'created_at_human' => $guideline->created_at->diffForHumans(short: true),
        ];
    }
}
