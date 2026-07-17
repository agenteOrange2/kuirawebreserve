<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\TenantMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Prueba de envío del SMTP del hotel (/ajustes → Correo saliente): manda
 * un correo real a la dirección que se pida usando la configuración
 * GUARDADA — el mismo camino que usarán los avisos al huésped.
 */
class SmtpTestController extends Controller
{
    public function __invoke(Request $request, TenantMailer $tenantMailer): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'email', 'max:255'],
        ]);

        $mailer = $tenantMailer->mailer();

        if ($mailer === null) {
            return response()->json([
                'message' => 'Aún no hay SMTP configurado: guarda al menos el servidor y el remitente antes de probar.',
            ], 422);
        }

        $hotel = Property::query()->first()?->name ?? config('app.name');

        try {
            $mailer->raw(
                "Este es un correo de prueba de {$hotel}. Si lo estás leyendo, el correo saliente quedó configurado correctamente.",
                fn ($message) => $message->to($data['to'])->subject("Prueba de correo — {$hotel}"),
            );
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'El servidor SMTP rechazó el envío: '.$e->getMessage(),
            ], 422);
        }

        return response()->json(['message' => "Correo de prueba enviado a {$data['to']}."]);
    }
}
