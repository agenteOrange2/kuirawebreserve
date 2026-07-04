<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AgentTokenController extends Controller
{
    /**
     * Usuario técnico del asistente (rol agent): dueño de los tokens y de
     * la auditoría de todo lo que el bot haga (created_by).
     */
    public static function ensureAgentUser(): User
    {
        $agent = User::role('agent')->first();

        if (! $agent) {
            $agent = User::create([
                'name' => 'Asistente IA',
                'email' => 'asistente@'.(tenant('id') ?? 'hotel').'.bot',
                'password' => Str::random(48), // nunca inicia sesión por password
            ]);
            $agent->assignRole('agent');
        }

        return $agent;
    }

    /**
     * Emite un token (se muestra una sola vez). Requiere la palanca "API de
     * integraciones" del hotel (la enciende la plataforma desde /admin).
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless(
            app(\App\Services\Agent\PlatformAgentGate::class)->status()['api_allowed'],
            403,
            'La API de integraciones no está habilitada para este hotel.',
        );

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        $agent = self::ensureAgentUser();
        $token = $agent->createToken($data['name'], ['agent']);

        return response()->json([
            'token' => $token->plainTextToken,
            'name' => $data['name'],
        ], 201);
    }

    public function destroy(Request $request, int $tokenId): JsonResponse
    {
        $agent = User::role('agent')->first();
        abort_unless($agent, 404);

        $agent->tokens()->whereKey($tokenId)->delete();

        return response()->json(status: 204);
    }
}
