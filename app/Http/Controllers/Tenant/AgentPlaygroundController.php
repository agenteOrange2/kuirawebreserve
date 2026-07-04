<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Agent\AgentToolsController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Playground del panel: prueba las herramientas del agente con la sesión
 * del owner, ejecutando EXACTAMENTE el mismo código que la Agent API.
 */
class AgentPlaygroundController extends Controller
{
    public function __invoke(Request $request, AgentToolsController $tools): JsonResponse
    {
        abort_unless(
            app(\App\Services\Agent\PlatformAgentGate::class)->status()['api_allowed'],
            403,
            'La API de integraciones no está habilitada para este hotel.',
        );

        $request->validate([
            'tool' => ['required', Rule::in(['policies', 'rate_plans', 'availability', 'reservation', 'hold'])],
            'params' => ['sometimes', 'array'],
        ]);

        $params = $request->input('params', []);
        $inner = Request::create('/playground', 'POST', $params);
        $inner->setUserResolver(fn () => $request->user());

        return match ($request->string('tool')->toString()) {
            'policies' => $tools->policies(),
            'rate_plans' => $tools->ratePlans(),
            'availability' => $tools->availability($inner, app(\App\Services\AvailabilityService::class)),
            'reservation' => $tools->showReservation((string) ($params['code'] ?? '')),
            'hold' => $tools->storeHold($inner, app(\App\Actions\Reservations\CreateReservation::class)),
        };
    }
}
