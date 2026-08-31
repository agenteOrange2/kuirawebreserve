<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\WaitlistEntry;
use App\Services\Channels\WaitlistNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página del módulo Lista de espera (/lista-espera): quiénes esperan un
 * hueco, por dónde salió el aviso (y por qué no salió, si falló), qué
 * terminó en reserva. El alta la hace el wizard público; aquí el staff da
 * seguimiento — avisar a mano, ligar la reserva convertida, depurar.
 *
 * Paginada y con filtro desde el arranque: la espera crece con la
 * operación y una lista sin freno se vuelve inservible.
 */
class WaitlistPageController extends Controller
{
    /** Lada por defecto para los links wa.me; la pisa el ajuste del hotel. */
    protected string $countryCode = '52';

    public function __invoke(Request $request, WaitlistNotifier $notifier): Response
    {
        $search = trim($request->string('q')->toString());
        $status = $request->string('status')->toString();

        if (! in_array($status, self::statuses(), true)) {
            $status = '';
        }

        $this->countryCode = self::countryCode();

        $paginator = WaitlistEntry::query()
            ->with(['roomType:id,name', 'reservation:id,code'])
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where(function ($qq) use ($search) {
                $qq->where('guest_name', 'like', "%{$search}%")
                    ->orWhere('guest_phone', 'like', "%{$search}%")
                    ->orWhere('guest_email', 'like', "%{$search}%");
            }))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $paginator->through(fn (WaitlistEntry $entry) => self::rowFor(
            $entry,
            $notifier->messageFor($entry),
            $this->countryCode,
        ));

        return Inertia::render('tenant/waitlist/Index', [
            'entries' => $paginator,
            'filters' => ['q' => $search, 'status' => $status],
            'stats' => $this->stats(),
            'canManage' => $request->user()->can('reservations.manage'),
        ]);
    }

    /** Lada del hotel para los links wa.me (una consulta, no una por fila). */
    public static function countryCode(): string
    {
        $settings = Property::query()->first()?->settings ?? [];

        return (string) ($settings['phone_country_code'] ?? '52');
    }

    /** @return list<string> */
    protected static function statuses(): array
    {
        return [
            WaitlistEntry::STATUS_WAITING,
            WaitlistEntry::STATUS_NOTIFIED,
            WaitlistEntry::STATUS_CONVERTED,
            WaitlistEntry::STATUS_EXPIRED,
        ];
    }

    /**
     * Contadores de la cabecera: en una consulta, no una por estado.
     * "failed" son las que siguen esperando porque el aviso no salió — el
     * número que de verdad hay que atender.
     *
     * @return array<string, int>
     */
    protected function stats(): array
    {
        $counts = WaitlistEntry::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'waiting' => (int) $counts->get(WaitlistEntry::STATUS_WAITING, 0),
            'notified' => (int) $counts->get(WaitlistEntry::STATUS_NOTIFIED, 0),
            'converted' => (int) $counts->get(WaitlistEntry::STATUS_CONVERTED, 0),
            'expired' => (int) $counts->get(WaitlistEntry::STATUS_EXPIRED, 0),
            'failed' => WaitlistEntry::query()
                ->whereNotNull('notify_failed_at')
                ->whereIn('status', [WaitlistEntry::STATUS_WAITING, WaitlistEntry::STATUS_EXPIRED])
                ->count(),
        ];
    }

    /**
     * Contrato del renglón, compartido con WaitlistEntryController para que
     * la respuesta de una acción pueda reemplazar la fila tal cual.
     *
     * @return array<string, mixed>
     */
    public static function rowFor(WaitlistEntry $entry, ?string $waText = null, string $countryCode = '52'): array
    {
        return [
            'id' => $entry->id,
            'guest_name' => $entry->guest_name,
            'guest_phone' => $entry->guest_phone,
            'guest_email' => $entry->guest_email,
            'room_type' => $entry->roomType?->name,
            'starts_at' => $entry->starts_at->format('d/m/Y'),
            'ends_at' => $entry->ends_at->format('d/m/Y'),
            'status' => $entry->status,
            'status_label' => $entry->statusLabel(),
            'notified_at' => $entry->notified_at?->format('d/m/Y H:i'),
            'notified_channel' => $entry->channelLabel(),
            'notify_attempts' => $entry->notify_attempts,
            'notify_failed_at' => $entry->notify_failed_at?->format('d/m/Y H:i'),
            'notify_error' => $entry->notify_error,
            // El hilo que abrió el asistente: la prueba clicable del aviso.
            'inbox_url' => $entry->conversation_id
                ? route('tenant.inbox', ['conversation' => $entry->conversation_id], false)
                : null,
            'reservation_code' => $entry->reservation?->code,
            'reservation_id' => $entry->reservation_id,
            'converted_at' => $entry->converted_at?->format('d/m/Y H:i'),
            'created_at' => $entry->created_at->format('d/m/Y H:i'),
            // Link manual a WhatsApp con el mismo texto que manda el sistema.
            'wa_phone' => $entry->whatsappNumber($countryCode),
            'wa_text' => $waText,
        ];
    }
}
