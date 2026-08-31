<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\WaitlistEntry;
use App\Services\Channels\WaitlistNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Seguimiento de la lista de espera desde el panel: avisar a mano cuando
 * el aviso automático no salió (o el interesado no contestó), ligar la
 * reserva que salió de la espera (conversión con prueba) y depurar
 * entradas. El alta viene del wizard público (WaitlistPublicController) y
 * el aviso automático de la cancelación (WaitlistNotifier::roomFreed).
 */
class WaitlistEntryController extends Controller
{
    public function __construct(protected WaitlistNotifier $notifier) {}

    /** Reintento/segundo toque a mano por WhatsApp y correo. */
    public function notify(WaitlistEntry $entry): JsonResponse
    {
        $sent = $this->notifier->notifyNow($entry);

        return response()->json([
            'sent' => $sent,
            'message' => $sent
                ? 'Aviso enviado por '.$entry->channelLabel().'.'
                : ($entry->notify_error ?: 'No se pudo enviar el aviso.'),
            'entry' => $this->serialize($entry->fresh()),
        ], $sent ? 200 : 422);
    }

    /**
     * Reservas que pudieron nacer de esta espera: mismo contacto o fechas
     * solapadas, creadas después de que la persona se anotó. Se piden solo
     * al abrir el diálogo — la lista no las consulta por renglón.
     */
    public function candidates(WaitlistEntry $entry): JsonResponse
    {
        $phone = preg_replace('/\D+/', '', (string) $entry->guest_phone) ?? '';
        $email = trim((string) $entry->guest_email);

        $reservations = Reservation::query()
            ->with(['guest:id,first_name,last_name,phone,email', 'roomType:id,name', 'room:id,number'])
            ->where('created_at', '>=', $entry->created_at)
            ->where(function ($q) use ($entry, $phone, $email) {
                $q->overlapping($entry->starts_at, $entry->ends_at);

                if ($phone !== '') {
                    // Los últimos 10 dígitos: el hotel guarda el teléfono
                    // con y sin lada según de dónde entró el dato.
                    $tail = substr($phone, -10);
                    $q->orWhereHas('guest', fn ($g) => $g->where('phone', 'like', "%{$tail}"));
                }

                if ($email !== '') {
                    $q->orWhereHas('guest', fn ($g) => $g->where('email', $email));
                }
            })
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (Reservation $r) => [
                'id' => $r->id,
                'code' => $r->code,
                'guest_name' => $r->guest_name ?: trim((string) $r->guest?->first_name.' '.$r->guest?->last_name),
                'room' => $r->room?->number,
                'room_type' => $r->roomType?->name,
                'dates' => $r->starts_at->format('d/m/Y').' - '.$r->ends_at->format('d/m/Y'),
                'status_label' => $r->status->label(),
            ]);

        return response()->json(['reservations' => $reservations]);
    }

    /**
     * Marcar convertida. reservation_id opcional pero recomendado: sin él
     * la conversión no es comprobable y el módulo no puede decir cuánto
     * dinero recuperó.
     */
    public function convert(Request $request, WaitlistEntry $entry): JsonResponse
    {
        $data = $request->validate([
            'reservation_id' => ['nullable', 'integer', 'exists:reservations,id'],
        ]);

        $entry->update([
            'status' => WaitlistEntry::STATUS_CONVERTED,
            'converted_at' => now(),
            'reservation_id' => $data['reservation_id'] ?? $entry->reservation_id,
        ]);

        return response()->json(['entry' => $this->serialize($entry->fresh())]);
    }

    public function destroy(WaitlistEntry $entry): JsonResponse
    {
        $entry->delete();

        return response()->json(status: 204);
    }

    /** Mismo contrato de renglón que WaitlistPageController. */
    protected function serialize(WaitlistEntry $entry): array
    {
        $entry->loadMissing(['roomType:id,name', 'reservation:id,code']);

        return WaitlistPageController::rowFor(
            $entry,
            $this->notifier->messageFor($entry),
            WaitlistPageController::countryCode(),
        );
    }
}
