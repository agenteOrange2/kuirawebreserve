<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AgentGuideline;
use App\Models\Central\TenantAgentSetting;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aprendizajes del asistente (/asistente/aprendizajes): área AISLADA,
 * habilitada por el super-admin (guidelines_editable) — mismo patrón que
 * /asistente/contexto. Apagada, las lecciones del bot las gestiona solo
 * la plataforma.
 */
class AgentLearningsPageController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(
            (bool) TenantAgentSetting::for((string) tenant('id'))->guidelines_editable,
            403,
            'Los aprendizajes del bot los gestiona la plataforma para este hotel.',
        );

        return Inertia::render('tenant/agent/Learnings', [
            'guidelines' => AgentGuideline::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (AgentGuideline $g) => AgentGuidelineController::serialize($g)),
        ]);
    }
}
