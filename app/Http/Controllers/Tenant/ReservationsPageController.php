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
    public function __invoke(Request $request): Response
    {
        $property = Property::firstOrFail();

        $relations = [
            'room:id,number',
            'roomType:id,name',
            'ratePlan:id,name,type',
            'guest:id,first_name,last_name,phone,email',
        ];

        $reservationModels = Reservation::query()
            ->with($relations)
            ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed])
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->get();

        // Historial: lo que ya salió del flujo (en casa vive en "stays").
        // Sin esto, un no-show/cancelación "desaparece" de la UI.
        $historyModels = Reservation::query()
            ->with($relations)
            ->whereIn('status', [
                ReservationStatus::Completed,
                ReservationStatus::Cancelled,
                ReservationStatus::NoShow,
            ])
            ->latest('updated_at')
            ->limit(50)
            ->get();

        $reservationTimeline = Activity::query()
            ->where('subject_type', Reservation::class)
            ->whereIn('subject_id', [...$reservationModels->modelKeys(), ...$historyModels->modelKeys()])
            ->latest()
            ->get()
            ->groupBy('subject_id');

        $serialize = fn (Reservation $r) => $this->serializeReservation($r, $reservationTimeline->get($r->id, collect()));

        $reservations = $reservationModels->map($serialize);
        $history = $historyModels->map($serialize);

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
            'property' => $property->only(['id', 'name']),
            'reservations' => $reservations,
            'history' => $history,
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
            'prefill' => [
                'intent' => $prefillIntent,
                'room' => $prefillRoom ? [
                    'id' => $prefillRoom->id,
                    'number' => $prefillRoom->number,
                    'room_type' => $prefillRoom->roomType?->name,
                    'rate_plan_id' => $prefillRoom->roomType?->ratePlans->first()?->id,
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
            'total_amount' => $r->total_amount,
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
            'payment_due_at' => $r->payment_due_at?->format('d/m/Y H:i'),
            'payment_overdue' => $r->isPaymentOverdue(),
            'paid_total' => $r->paidTotal(),
            'pending_balance' => $r->pendingBalance(),
            'updated_at' => $r->updated_at?->format('d/m/Y H:i'),
            'timeline' => $this->timelineFor($timeline),
        ];
    }

    /**
     * @param  Collection<int, Activity>  $activities
     * @return array<int, array<string, string|null>>
     */
    protected function timelineFor(Collection $activities): array
    {
        return $activities
            ->take(8)
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

        return 'Reserva actualizada';
    }
}
