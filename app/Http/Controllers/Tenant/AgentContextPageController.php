<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Admin\AiAgentsController;
use App\Http\Controllers\Controller;
use App\Models\Central\TenantAgentSetting;
use App\Models\Property;
use App\Services\Agent\AgentBrain;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contexto del bot para el HOTEL: edita sus propias instrucciones
 * (settings.agent_instructions) y ve el prompt efectivo. Solo disponible si
 * el super-admin habilitó la palanca context_editable para este tenant.
 */
class AgentContextPageController extends Controller
{
    public function __invoke(AgentBrain $brain): Response
    {
        abort_unless(
            (bool) TenantAgentSetting::for((string) tenant('id'))->context_editable,
            403,
            'El contexto del bot lo gestiona la plataforma para este hotel.',
        );

        $property = Property::firstOrFail();

        return Inertia::render('tenant/agent/Context', [
            'property' => $property->only(['id', 'name']),
            'agentInstructions' => $property->settings['agent_instructions'] ?? '',
            'template' => AiAgentsController::INSTRUCTIONS_TEMPLATE,
            'prompt' => $brain->promptPreview(),
        ]);
    }
}
