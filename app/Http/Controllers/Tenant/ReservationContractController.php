<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\Channels\DirectGuestMessenger;
use App\Services\Guests\ReservationContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * El contrato de hospedaje, a mano desde la ficha de la reserva.
 *
 * Hasta hoy el contrato solo salía SOLO, en el correo de confirmación
 * (PaymentGuestNotifier::reservationConfirmed), y únicamente si el huésped
 * tenía correo en su ficha. Cuando no lo tenía, `sendEmail()` regresaba
 * false en silencio: nadie en el hotel se enteraba de que ese contrato no
 * había salido, y recepción terminaba mandando a mano lo que encontraba.
 *
 * Caso real cabañas 2026-09-18, Daysi Gómez (RES-2026-1773): dictó su
 * correo por WhatsApp a las 15:25, la reserva se capturó en mostrador a las
 * 15:39 sin ese correo, y a las 16:11 recepción le mandó un link del sitio
 * diciéndole "le adjunto su contrato".
 *
 * Aquí se puede: ver el PDF (para mandarlo por donde sea) y enviarlo por
 * correo capturando de paso el correo que faltaba en la ficha.
 */
class ReservationContractController extends Controller
{
    public function __construct(
        protected ReservationContract $contract,
        protected DirectGuestMessenger $direct,
    ) {}

    /** El PDF en pantalla: sirve para descargarlo y mandarlo por WhatsApp. */
    public function pdf(Reservation $reservation): Response
    {
        $pdf = $this->contract->pdf($reservation);

        if ($pdf === null) {
            // Sin texto capturado no hay contrato que enseñar; el hotel lo
            // escribe en /ajustes/general.
            throw new NotFoundHttpException('El hotel no ha capturado su contrato de hospedaje.');
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->contract->filename($reservation).'"',
        ]);
    }

    /**
     * Manda el contrato por correo. Si viene un correo en la petición se
     * guarda antes en la ficha del huésped: casi siempre el motivo de que
     * el contrato no saliera es justo que ahí no había ninguno.
     */
    public function send(Request $request, Reservation $reservation): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['nullable', 'email:rfc', 'max:255'],
        ], [], ['email' => 'correo']);

        if (! $this->contract->available()) {
            return back()->with('error', 'El hotel todavía no captura su contrato de hospedaje. Se escribe en Ajustes, Datos generales.');
        }

        $reservation->loadMissing('guest');
        $guest = $reservation->guest;
        $email = trim((string) ($data['email'] ?? '')) ?: $guest?->email;

        if (! $email) {
            return back()->with('error', 'Esta reserva no tiene correo del huésped. Captúralo para poder enviarle el contrato.');
        }

        if ($guest && $guest->email !== $email) {
            // El correo que dictó el huésped se queda en su ficha: la
            // próxima confirmación, cobro o recordatorio ya sale solo.
            $guest->forceFill(['email' => $email])->save();
            $reservation->setRelation('guest', $guest->refresh());
        }

        $sent = $this->direct->mailTo(
            $reservation,
            $this->body($reservation),
            'Tu contrato de hospedaje',
            withCalendar: false,
            withContract: true,
        );

        if (! $sent) {
            return back()->with('error', 'No se pudo enviar el correo. Revisa la configuración de correo del hotel e inténtalo de nuevo.');
        }

        // La constancia la deja DirectGuestMessenger al mandarlo, para que
        // cuente igual el envío automático de la confirmación.

        return back()->with('success', "Contrato enviado a {$email}.");
    }

    /** El cuerpo del correo: el contrato viaja adjunto, esto lo presenta. */
    protected function body(Reservation $reservation): string
    {
        $arrival = $reservation->starts_at->locale('es')->isoFormat('dddd D [de] MMMM [a las] HH:mm');

        return "Te compartimos el contrato de hospedaje de tu reserva {$reservation->displayCode()},"
            ." con llegada el {$arrival}. Viene adjunto en PDF: consérvalo y tráelo"
            .' —impreso o en tu teléfono— junto con una identificación oficial el día de tu llegada.';
    }
}
