<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Stay;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class ReservationsPageController extends Controller
{
    /** Líneas de bitácora que muestra el detalle de cada reserva. */
    private const TIMELINE_LIMIT = 8;

    /** Horizonte de la lista de próximas: más allá vive el calendario. */
    private const UPCOMING_DAYS = 90;

    /** Tope duro por si un hotel llena esos 90 días. */
    private const UPCOMING_LIMIT = 200;

    public function __invoke(Request $request): Response
    {
        $property = Property::firstOrFail();

        $relations = [
            'room:id,number',
            'roomType:id,name',
            'ratePlan:id,name,type',
            'guest:id,first_name,last_name,phone,email',
        ];

        // Próximas reservas: acotadas a un horizonte y con tope duro. Traer
        // TODO lo futuro sin límite crecía sin freno con la operación, y el
        // mostrador nunca necesita ver una reserva de dentro de ocho meses
        // en esta lista (para eso está el calendario, que pide su propio
        // rango, y /reservas/historial con buscador).
        $upcomingQuery = Reservation::query()
            ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed])
            ->where('ends_at', '>=', now())
            ->where('starts_at', '<=', now()->addDays(self::UPCOMING_DAYS));

        $upcomingTotal = (clone $upcomingQuery)->count();

        $reservationModels = $upcomingQuery
            ->with($relations)
            // ¿Hay transferencia esperando verificación? El modal de
            // confirmar avisa: confirmar a mano NO registra ese dinero —
            // se aprueba en /pagos (incidente RES-2026-0032, 2026-07-24).
            ->withExists(['paymentRequests as pending_transfer_request' => fn ($q) => $q
                ->where('method', \App\Models\PaymentRequest::METHOD_TRANSFER)
                ->where('status', \App\Models\PaymentRequest::STATUS_PENDING)])
            ->orderBy('starts_at')
            ->limit(self::UPCOMING_LIMIT)
            ->get();

        // Historial: lo que ya salió del flujo (en casa vive en "stays").
        // Sin esto, un no-show/cancelación "desaparece" de la UI. Solo las
        // últimas 20 — el resto vive en /reservas/historial con buscador.
        $historyStatuses = [
            ReservationStatus::Completed,
            ReservationStatus::Cancelled,
            ReservationStatus::NoShow,
        ];
        $historyTotal = Reservation::query()->whereIn('status', $historyStatuses)->count();
        $historyModels = Reservation::query()
            ->with($relations)
            ->whereIn('status', $historyStatuses)
            ->latest('updated_at')
            ->limit(20)
            ->get();

        // En casa: reservas con check-in. No van en la lista de próximas ni
        // en historial, pero el calendario abre su detalle desde la barra.
        $inHouseModels = Reservation::query()
            ->with($relations)
            ->where('status', ReservationStatus::CheckedIn)
            ->orderBy('starts_at')
            ->get();

        $reservationTimeline = $this->recentTimelines([
            ...$reservationModels->modelKeys(),
            ...$historyModels->modelKeys(),
            ...$inHouseModels->modelKeys(),
        ]);

        $serialize = fn (Reservation $r) => $this->serializeReservation($r, $reservationTimeline->get($r->id, collect()));

        $reservations = $reservationModels->map($serialize);
        $history = $historyModels->map($serialize);
        $inHouse = $inHouseModels->map($serialize);

        $stays = Stay::query()
            ->active()
            ->with(['room:id,number', 'ratePlan:id,name'])
            ->orderBy('planned_end_at')
            ->get()
            ->map(fn (Stay $stay) => [
                'id' => $stay->id,
                'room' => $stay->room?->number,
                'guest_name' => $stay->guest_name,
                'num_people' => $stay->num_people,
                'vehicle_plate' => $stay->vehicle_plate,
                'vehicle_desc' => $stay->vehicle_desc,
                'rate_plan' => $stay->ratePlan?->name,
                'check_in_at' => $stay->check_in_at->format('d/m/Y H:i'),
                'planned_end_at' => $stay->planned_end_at->format('d/m/Y H:i'),
                'planned_end_at_iso' => $stay->planned_end_at->toIso8601String(),
                'overdue' => $stay->planned_end_at->isPast(),
                'amount' => $stay->amount,
                'channel' => $stay->channel,
            ]);

        $prefillRoom = $request->integer('room')
            ? Room::query()
                ->where('property_id', $property->id)
                ->whereKey($request->integer('room'))
                ->with([
                    'roomType:id,name',
                    'roomType.ratePlans' => fn ($query) => $query
                        ->select(['id', 'room_type_id', 'name', 'active'])
                        ->where('active', true)
                        ->orderBy('price'),
                ])
                ->first()
            : null;

        // Huésped precargado (desde su ficha): "Nueva reserva".
        $prefillGuest = $request->integer('guest')
            ? Guest::query()
                ->withCount(['stays as visits' => fn ($q) => $q->where('status', 'completed')])
                ->find($request->integer('guest'))
            : null;

        $intent = $request->string('intent')->toString();
        $prefillIntent = in_array($intent, ['walkin', 'reserve'], true) ? $intent : null;
        // Si llega un huésped sin intención explícita, asumimos reserva.
        if ($prefillGuest && ! $prefillIntent) {
            $prefillIntent = 'reserve';
        }
        $focusReservationId = $request->integer('reservation') ?: null;

        return Inertia::render('tenant/reservations/Index', [
            // Lista y calendario comparten componente y datos; cambia la vista.
            'view' => $request->routeIs('tenant.reservations.calendar') ? 'calendar' : 'list',
            'property' => $property->only(['id', 'name']),
            'reservations' => $reservations,
            // Cuántas quedaron fuera del horizonte o del tope, para que la
            // lista pueda decirlo en vez de ocultarlas en silencio.
            'upcomingTotal' => $upcomingTotal,
            'upcomingDays' => self::UPCOMING_DAYS,
            'history' => $history,
            'historyTotal' => $historyTotal,
            'inHouse' => $inHouse,
            'stays' => $stays,
            'ratePlans' => RatePlan::query()
                ->where('active', true)
                ->with('roomType:id,name')
                ->get()
                ->map(fn (RatePlan $plan) => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'type' => $plan->type->value,
                    'room_type' => $plan->roomType->name,
                    'price' => $plan->price,
                    'duration_minutes' => $plan->duration_minutes,
                    'duration_unit' => $plan->duration_unit?->value,
                    'duration_value' => $plan->duration_value,
                    'duration_label' => $plan->durationLabel(),
                    'deposit_percent' => $plan->deposit_percent,
                    'min_advance_label' => $plan->minAdvanceLabel(),
                ]),
            'canManage' => $request->user()->can('reservations.manage'),
            // En modo de check-in "automático" puro (/ajustes/limpieza) la
            // llegada la registra el reloj: el botón manual se oculta.
            'manualCheckinAllowed' => app(\App\Services\HousekeepingPolicy::class)->manualCheckInAllowed(),
            // Walk-ins con cobro al llegar (/ajustes/metodos-pago): el modal
            // de llegada pide el método de pago y registra el cobro.
            'walkinChargeOnCheckin' => app(\App\Services\ReservationPolicy::class)->walkinChargeOnCheckIn(),
            // Fianza (depósito en garantía): monto fijo que los modales de
            // llegada cobran y el de salida devuelve. 0 = ajuste apagado.
            'guaranteeAmount' => app(\App\Services\ReservationPolicy::class)->guaranteeEnabled()
                ? app(\App\Services\ReservationPolicy::class)->guaranteeAmount()
                : 0,
            // Duración REAL del apartado (hold_value/unit de Métodos de
            // pago): la UI nunca debe decir "30 minutos" fijo.
            'holdMinutes' => app(\App\Services\ReservationPolicy::class)->holdMinutes(),
            'prefill' => [
                'intent' => $prefillIntent,
                'room' => $prefillRoom ? [
                    'id' => $prefillRoom->id,
                    'number' => $prefillRoom->number,
                    'room_type' => $prefillRoom->roomType?->name,
                    'rate_plan_id' => $prefillRoom->roomType?->ratePlans->first()?->id,
                    'included_occupancy' => $prefillRoom->included_occupancy,
                    'extra_guest_fee' => $prefillRoom->extra_guest_fee !== null ? (float) $prefillRoom->extra_guest_fee : null,
                    'optional_charges' => collect($prefillRoom->optional_charges ?? [])
                        ->map(fn (array $charge) => [
                            'concept' => (string) ($charge['concept'] ?? ''),
                            'amount' => round((float) ($charge['amount'] ?? 0), 2),
                        ])
                        ->values()
                        ->all(),
                ] : null,
                'guest' => $prefillGuest ? $this->prefillGuestPayload($prefillGuest) : null,
            ],
            'focusReservationId' => $focusReservationId,
        ]);
    }

    /**
     * Datos del huésped para precargar el modal de reserva, incluyendo su
     * vehículo (placa y descripción) si lo tiene en el CRM.
     *
     * @return array<string, mixed>
     */
    protected function prefillGuestPayload(Guest $guest): array
    {
        $vehicle = $guest->vehicle();
        $desc = collect([$vehicle['brand'] ?? null, $vehicle['model'] ?? null, $vehicle['color'] ?? null])
            ->filter()
            ->implode(' ');

        return [
            'id' => $guest->id,
            'full_name' => $guest->full_name,
            'phone' => $guest->phone,
            'visits' => (int) ($guest->visits ?? 0),
            'is_blacklisted' => $guest->is_blacklisted,
            'blacklist_reason' => $guest->blacklist_reason,
            'vehicle' => (($vehicle['plate'] ?? null) || $desc !== '') ? [
                'plate' => $vehicle['plate'] ?? null,
                'desc' => $desc !== '' ? $desc : null,
            ] : null,
        ];
    }

    /**
     * @param  Collection<int, Activity>  $timeline
     * @return array<string, mixed>
     */
    protected function serializeReservation(Reservation $r, Collection $timeline): array
    {
        return [
            'id' => $r->id,
            'code' => $r->displayCode(),
            'guest_id' => $r->guest_id,
            'guest_name' => $r->guest_name,
            'num_people' => $r->num_people,
            'adults' => $r->adults,
            'children' => $r->children,
            'vehicle_plate' => $r->vehicle_plate,
            'vehicle_desc' => $r->vehicle_desc,
            'eta' => $r->eta ? substr($r->eta, 0, 5) : null,
            'room' => $r->room?->number,
            'room_id' => $r->room_id,
            'room_type' => $r->roomType?->name,
            'rate_plan' => $r->ratePlan?->name,
            'rate_plan_id' => $r->rate_plan_id,
            'starts_at' => $r->starts_at->format('d/m/Y H:i'),
            'starts_at_input' => $r->starts_at->format('Y-m-d\TH:i'),
            'ends_at' => $r->ends_at->format('d/m/Y H:i'),
            'ends_at_input' => $r->ends_at->format('Y-m-d\TH:i'),
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'hold_expires_at' => $r->hold_expires_at?->format('H:i'),
            // ISO para la tarjeta "holds por vencer" (se calcula en cliente).
            'hold_expires_at_iso' => $r->hold_expires_at?->toIso8601String(),
            'total_amount' => $r->total_amount,
            'extra_charges' => $r->extra_charges ?? [],
            // Cupón aplicado en el wizard (módulo cupones): el descuento ya
            // vive dentro de total_amount; el detalle muestra la línea.
            'coupon_code' => $r->coupon_code,
            'discount_amount' => (float) ($r->discount_amount ?? 0),
            // Líneas congeladas del wizard: productos POS, add-ons y
            // experiencias — el detalle desglosa de qué está hecho el total.
            'products' => $r->products ?? [],
            'extras' => $r->extras ?? [],
            'experiences' => $r->experiences ?? [],
            'starts_today' => $r->starts_at->isToday(),
            'source_channel' => $r->source_channel,
            'notes' => $r->notes,
            'guest_notes' => $r->guest_notes,
            'cancellation_reason' => $r->cancellation_reason,
            'guest_phone' => $r->guest?->phone,
            'guest_email' => $r->guest?->email,
            'deposit_amount' => $r->deposit_amount,
            'payment_status' => $r->payment_status->value,
            'payment_status_label' => $r->payment_status->label(),
            'pending_transfer_request' => (bool) ($r->pending_transfer_request ?? false),
            'payment_due_at' => $r->payment_due_at?->format('d/m/Y H:i'),
            'payment_overdue' => $r->isPaymentOverdue(),
            'paid_total' => $r->paidTotal(),
            'pending_balance' => $r->pendingBalance(),
            'updated_at' => $r->updated_at?->format('d/m/Y H:i'),
            'timeline' => $this->timelineFor($timeline),
        ];
    }

    /**
     * Últimas actividades POR reserva, acotadas en SQL con ROW_NUMBER.
     *
     * Antes esto traía la bitácora COMPLETA de todas las reservas cargadas
     * para quedarse con las más recientes de cada una ya en PHP: a un año
     * de operación son miles de filas en cada visita a /reservas.
     *
     * @param  array<int, int>  $subjectIds
     * @return Collection<int, Collection<int, Activity>>
     */
    protected function recentTimelines(array $subjectIds): Collection
    {
        if ($subjectIds === []) {
            return collect();
        }

        $ranked = Activity::query()
            ->select('*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY subject_id ORDER BY created_at DESC, id DESC) AS rn')
            ->where('subject_type', Reservation::class)
            ->whereIn('subject_id', $subjectIds);

        return Activity::query()
            ->fromSub($ranked, 'ranked_activities')
            ->where('rn', '<=', self::TIMELINE_LIMIT)
            ->orderBy('subject_id')
            ->orderBy('rn')
            // Cada línea pinta el nombre de quien hizo el cambio; sin esto
            // cada fila iba a buscar su causer por separado.
            ->with('causer')
            ->get()
            ->groupBy('subject_id');
    }

    /**
     * @param  Collection<int, Activity>  $activities
     * @return array<int, array<string, string|null>>
     */
    protected function timelineFor(Collection $activities): array
    {
        return $activities
            ->take(self::TIMELINE_LIMIT)
            ->map(function (Activity $activity) {
                $old = $activity->properties['old'] ?? [];
                $attributes = $activity->properties['attributes'] ?? [];
                $message = $this->timelineMessage($activity, $old, $attributes);

                return [
                    'id' => (string) $activity->id,
                    'message' => $message,
                    'by' => $activity->causer?->name,
                    'at' => $activity->created_at?->format('d/m/Y H:i'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $attributes
     */
    protected function timelineMessage(Activity $activity, array $old, array $attributes): string
    {
        // Entradas con mensaje propio (cupones aplicados/canjeados, estancia
        // extendida...): su descripción ya viene escrita para humanos.
        if (! in_array($activity->description, ['created', 'updated', 'deleted'], true)) {
            return $activity->description;
        }

        if (($old['status'] ?? null) && ($attributes['status'] ?? null) && $old['status'] !== $attributes['status']) {
            $from = ReservationStatus::tryFrom((string) $old['status'])?->label() ?? $old['status'];
            $to = ReservationStatus::tryFrom((string) $attributes['status'])?->label() ?? $attributes['status'];

            return "Estado: {$from} → {$to}";
        }

        if ($activity->event === 'created') {
            return 'Reserva creada';
        }

        if (($old['room_id'] ?? null) !== ($attributes['room_id'] ?? null) && ($attributes['room_id'] ?? null)) {
            return 'Se cambió la habitación asignada';
        }

        if (($old['starts_at'] ?? null) !== ($attributes['starts_at'] ?? null) || ($old['ends_at'] ?? null) !== ($attributes['ends_at'] ?? null)) {
            return 'Se ajustó el rango de la reserva';
        }

        if (array_key_exists('coupon_code', $attributes)) {
            return ($attributes['coupon_code'] ?? null)
                ? 'Cupón '.$attributes['coupon_code'].' aplicado a la reserva'
                : 'Se quitó el cupón de la reserva';
        }

        if (array_key_exists('guest_id', $attributes) || array_key_exists('guest_name', $attributes)) {
            return 'Se actualizaron los datos del huésped';
        }

        if (array_key_exists('num_people', $attributes) || array_key_exists('adults', $attributes) || array_key_exists('children', $attributes)) {
            return 'Se ajustó el número de personas';
        }

        if (array_key_exists('rate_plan_id', $attributes)) {
            return 'Se cambió la tarifa de la reserva';
        }

        if (array_key_exists('total_amount', $attributes) && isset($old['total_amount'])) {
            return sprintf('Total: $%s → $%s', number_format((float) $old['total_amount'], 2), number_format((float) $attributes['total_amount'], 2));
        }

        if (array_key_exists('notes', $attributes)) {
            return 'Se editaron las notas internas';
        }

        return 'Reserva actualizada';
    }
}
