<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Mail\GuestNoticeMail;
use App\Mail\GuestReservationMail;
use App\Mail\StaffNoticeMail;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\ResetPasswordNotification;
use App\Services\TenantMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Vista previa de los correos que salen a nombre del hotel (/ajustes/mails):
 * el hotel ve cómo los recibe el huésped o su equipo, con SU logo, color y
 * datos de contacto, sin tener que hacer una reserva de prueba. Todo con
 * datos de ejemplo: nada se guarda ni se le manda a un huésped real.
 */
class MailPreviewController extends Controller
{
    public const TYPES = ['reservation', 'notice', 'staff', 'reset', 'changed'];

    public function show(Request $request, string $type): Response
    {
        abort_unless(in_array($type, self::TYPES, true), 404);

        return response($this->render($request, $type))
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /** Manda el ejemplo al correo de quien lo pide, por el SMTP del hotel. */
    public function send(Request $request, string $type): JsonResponse
    {
        abort_unless(in_array($type, self::TYPES, true), 404);

        $email = (string) $request->user()->email;
        $mailer = app(TenantMailer::class)->mailer();

        if ($mailer === null) {
            return response()->json([
                'message' => 'Configura primero el SMTP del hotel: sin él los correos al huésped no salen.',
            ], 422);
        }

        try {
            $mailable = $this->mailable($request, $type);
            $mailer->to($email)->send($mailable);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se pudo enviar: '.$e->getMessage()], 422);
        }

        return response()->json(['message' => "Enviado a {$email}."]);
    }

    protected function render(Request $request, string $type): string
    {
        return match ($type) {
            'reset' => (string) (new ResetPasswordNotification('ejemplo'))->toMail($request->user())->render(),
            'changed' => (string) (new PasswordChangedNotification('reset', $request->ip(), $request->userAgent()))->toMail($request->user())->render(),
            default => $this->mailable($request, $type)->render(),
        };
    }

    /** Los correos de cuenta salen como notificación; para la prueba se envuelven en un Mailable. */
    protected function mailable(Request $request, string $type): \Illuminate\Mail\Mailable
    {
        return match ($type) {
            'reservation' => new GuestReservationMail(
                $this->sampleReservation(),
                'Hola Karla, tu reserva quedó confirmada. Te esperamos; cualquier duda, contesta este correo o escríbenos por WhatsApp.',
                'Reserva confirmada',
            ),
            'notice' => new GuestNoticeMail(
                'Experiencia confirmada',
                'Hola Karla, tu lugar en la experiencia quedó apartado. Te esperamos 15 minutos antes.',
                'EXP-0001',
                [
                    ['label' => 'Fecha', 'value' => now()->addDays(5)->locale('es')->isoFormat('dddd D [de] MMMM')],
                    ['label' => 'Personas', 'value' => '2'],
                ],
            ),
            'staff' => new StaffNoticeMail(
                'Reserva nueva',
                'Entró una reserva nueva desde el sitio web.',
                [
                    'Folio' => 'RES-EJEMPLO',
                    'Huésped' => 'Karla Villalobos',
                    'Llegada' => now()->addDays(7)->locale('es')->isoFormat('dddd D [de] MMMM'),
                    'Total' => '$2,500.00',
                ],
                url('/reservas'),
            ),
            'reset', 'changed' => (new \Illuminate\Mail\Mailable)
                ->subject($type === 'reset' ? 'Crea tu contraseña nueva (prueba)' : 'Tu contraseña cambió (prueba)')
                ->html($this->render($request, $type)),
        };
    }

    /** Reserva de ejemplo sin guardar: no toca la base ni el folio real. */
    protected function sampleReservation(): Reservation
    {
        $reservation = new Reservation([
            'adults' => 2,
            'children' => 0,
        ]);
        $reservation->code = 'RES-EJEMPLO';
        $reservation->starts_at = now()->addDays(7)->setTime(15, 0);
        $reservation->ends_at = now()->addDays(9)->setTime(12, 0);
        $reservation->total_amount = 2500;
        $reservation->setRelation('roomType', RoomType::query()->first() ?? new RoomType(['name' => 'Habitación doble']));

        return $reservation;
    }
}
