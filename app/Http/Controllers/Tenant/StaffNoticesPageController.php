<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Mail\StaffNoticeMail;
use App\Models\Property;
use App\Services\StaffAlerts;
use App\Services\TenantMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Área AISLADA de avisos al HOTEL (/ajustes/avisos-hotel): a qué correos
 * llegan las reservas nuevas, los pagos, las cancelaciones y las salidas
 * (StaffAlerts). Hermana de /ajustes/avisos, que es lo que se le manda al
 * huésped.
 */
class StaffNoticesPageController extends Controller
{
    public function __invoke(StaffAlerts $alerts): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        return Inertia::render('tenant/settings/StaffNotices', [
            'property' => $property->only(['id', 'name']),
            'settings' => [
                'staff_notice_emails' => array_values($settings['staff_notice_emails'] ?? []),
                'staff_notice_events' => collect(StaffAlerts::EVENTS)
                    ->mapWithKeys(fn (string $event) => [$event => $alerts->eventEnabled($event)])
                    ->all(),
            ],
            // Sin SMTP propio los avisos salen igual, por el correo de la
            // plataforma; la página solo lo dice.
            // Misma condición que TenantMailer::mailer().
            'hasOwnSmtp' => ! empty($settings['smtp_host']) && ! empty($settings['smtp_from_address']),
        ]);
    }

    /**
     * Correo de prueba a los correos que están en pantalla (aún sin
     * guardar): así se confirma que llega antes de depender de él.
     */
    public function test(Request $request, TenantMailer $tenantMailer): JsonResponse
    {
        $data = $request->validate([
            'emails' => ['required', 'array', 'min:1', 'max:10'],
            'emails.*' => ['email', 'max:255'],
        ]);

        $property = Property::firstOrFail();

        $mail = new StaffNoticeMail(
            subjectLine: 'Correo de prueba',
            intro: 'Así te van a llegar los avisos del hotel: reservas nuevas, pagos, cancelaciones y salidas.',
            lines: [
                'Hotel' => $property->name,
                'Enviado por' => $request->user()?->name,
                'Fecha' => now()->locale('es')->isoFormat('ddd D [de] MMM YYYY, HH:mm'),
            ],
            url: url('/ajustes/avisos-hotel'),
        );

        try {
            ($tenantMailer->mailer() ?? Mail::mailer())->to($data['emails'])->send($mail);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No se pudo enviar: '.$e->getMessage(),
            ], 422);
        }

        return response()->json(['sent' => count($data['emails'])]);
    }
}
