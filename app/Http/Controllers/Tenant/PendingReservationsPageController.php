<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ReservationStatus;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Stay;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Pendientes (/reservas/pendientes): los dos trabajos que nadie ve hasta
 * que alguien los va a buscar —lo que falta CONFIRMAR y lo que falta
 * COBRAR— en una sola pantalla.
 *
 * Los apartados paginan de diez en diez y se trabajan en la ficha de cada
 * reserva; las cuentas son un asomo, porque su bandeja con acciones
 * (cobrar, agregar cargos, cerrar con motivo) vive en /reservas/cuentas.
 */
class PendingReservationsPageController extends ReservationsPageController
{
    /** Diez por página: el tablero manda aquí a revisar, no a leer un archivo. */
    protected const PER_PAGE = 10;

    protected const SETTLEMENTS_PREVIEW = 10;

    public function __invoke(Request $request): Response
    {
        $property = Property::firstOrFail();

        $paginator = Reservation::query()
            ->with([
                'room:id,number',
                'roomType:id,name',
                'ratePlan:id,name,type',
                'guest:id,first_name,last_name,phone,email',
            ])
            ->withSum('payments', 'amount')
            // ¿Hay transferencia esperando verificación? Confirmar a mano NO
            // registra ese dinero: se aprueba en /pagos.
            ->withExists(['paymentRequests as pending_transfer_request' => fn ($q) => $q
                ->where('method', \App\Models\PaymentRequest::METHOD_TRANSFER)
                ->where('status', \App\Models\PaymentRequest::STATUS_PENDING)])
            ->where('status', ReservationStatus::Pending)
            // Un apartado de una fecha que ya pasó no es trabajo pendiente:
            // lo resolvió el vencimiento o el no-show.
            ->where('ends_at', '>=', now())
            // Primero lo que está por vencerse, que es lo que se pierde solo.
            ->orderByRaw('hold_expires_at is null, hold_expires_at')
            ->orderBy('starts_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $timeline = Activity::query()
            ->where('subject_type', Reservation::class)
            ->whereIn('subject_id', $paginator->getCollection()->modelKeys())
            ->latest()
            ->get()
            ->groupBy('subject_id');

        $paginator->through(function (Reservation $r) use ($timeline) {
            $row = $this->serializeReservation($r, $timeline->get($r->id, collect()));

            // El reloj, en palabras. La hora sola ("11:20") no dice si ya
            // pasó: un apartado vencido se veía igual que uno que aguanta
            // tres horas.
            [$row['hold_state'], $row['hold_countdown']] = $this->holdClock($r->hold_expires_at);

            return $row;
        });

        return Inertia::render('tenant/reservations/Pending', [
            'property' => $property->only(['id', 'name']),
            'reservations' => $paginator,
            'settlements' => Stay::query()
                ->pendingSettlement()
                ->with(['room:id,number', 'guest:id,first_name,last_name', 'reservation:id,code,created_at'])
                // Primero la más vieja: es la que más se va a tardar en cobrar.
                ->orderBy('stays.check_out_at')
                ->limit(self::SETTLEMENTS_PREVIEW)
                ->get()
                ->map(fn (Stay $stay) => [
                    'id' => $stay->id,
                    'room' => $stay->room?->number,
                    'guest_name' => $stay->guest?->full_name ?? $stay->guest_name ?? 'Anónimo',
                    'reservation_code' => $stay->reservation?->displayCode(),
                    'check_out_at' => $stay->check_out_at?->format('d/m/Y H:i'),
                    'pending' => $stay->pendingSettlementAmount(),
                    // La cerró el reloj: nadie estuvo en el mostrador para
                    // cobrar, y eso cambia a quién se le pregunta qué pasó.
                    'auto_closed' => $stay->auto_closed_at !== null,
                ]),
            'settlementsTotal' => Stay::query()->pendingSettlement()->count(),
            'summary' => $this->summary(),
            'canManage' => $request->user()->can('reservations.manage'),
            'holdMinutes' => app(\App\Services\ReservationPolicy::class)->holdMinutes(),
        ]);
    }

    /**
     * Cuánto le queda al apartado, dicho como lo diría una persona.
     *
     * @return array{0: string|null, 1: string|null} estado y frase
     */
    protected function holdClock(?\Carbon\CarbonInterface $expiresAt): array
    {
        if (! $expiresAt) {
            return [null, null];
        }

        $minutes = (int) round(now()->diffInMinutes($expiresAt, false));

        if ($minutes < 0) {
            return ['expired', 'venció '.$this->spellMinutes(abs($minutes)).' antes'];
        }

        // Media hora o menos: es lo que se pierde solo mientras nadie mira.
        return [$minutes <= 30 ? 'urgent' : 'live', 'en '.$this->spellMinutes($minutes)];
    }

    /** "45 min", "3 h", "2 días" — sin decimales ni locales del sistema. */
    protected function spellMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return max(1, $minutes).' min';
        }

        if ($minutes < 60 * 24) {
            $hours = intdiv($minutes, 60);

            return $hours.' h';
        }

        $days = intdiv($minutes, 60 * 24);

        return $days.($days === 1 ? ' día' : ' días');
    }

    /**
     * Las dos cifras de la pantalla: lo que está por perderse y el dinero
     * que sigue sin cobrarse.
     *
     * @return array<string, mixed>
     */
    protected function summary(): array
    {
        $now = now();
        $base = fn () => Reservation::query()
            ->where('status', ReservationStatus::Pending)
            ->where('ends_at', '>=', $now);

        $total = (float) $base()->sum('total_amount');
        $paid = (float) \App\Models\Payment::query()
            ->whereIn('reservation_id', $base()->select('reservations.id'))
            ->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '<>', 'guarantee'))
            ->sum('amount');
        $balance = round(max(0, $total - $paid), 2);

        // El saldo de las cuentas cerradas: contarlas no dice cuánto dinero
        // hay ahí afuera, y es la cifra por la que pregunta el dueño.
        $settlementAmount = round((float) Stay::query()
            ->pendingSettlement()
            ->get()
            ->sum(fn (Stay $stay) => $stay->pendingSettlementAmount()), 2);

        return [
            'holds' => $base()->count(),
            'expiring' => $base()
                ->whereNotNull('hold_expires_at')
                ->whereBetween('hold_expires_at', [$now, $now->copy()->addMinutes(30)])
                ->count(),
            'expired' => $base()
                ->whereNotNull('hold_expires_at')
                ->where('hold_expires_at', '<', $now)
                ->count(),
            'balance_label' => '$'.number_format($balance, 2),
            'settlement_amount_label' => '$'.number_format($settlementAmount, 2),
        ];
    }
}
