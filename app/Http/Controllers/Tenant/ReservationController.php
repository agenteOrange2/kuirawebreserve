<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\RegisterReservationPayment;
use App\Actions\Reservations\TransitionReservation;
use App\Actions\Reservations\UpdateReservation;
use App\Enums\ReservationStatus;
use App\Exceptions\NoAvailabilityException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateReservationRequest;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ReservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $reservations = Reservation::query()
            ->with(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type'])
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->when($request->date('from'), fn ($q, $from) => $q->where('ends_at', '>=', $from))
            ->when($request->date('to'), fn ($q, $to) => $q->where('starts_at', '<=', $to))
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Reservation $r) => $this->serialize($r));

        return response()->json($reservations);
    }

    public function store(Request $request, CreateReservation $action): JsonResponse
    {
        $data = $request->validate([
            'rate_plan_id' => ['required', 'exists:rate_plans,id'],
            'room_id' => ['nullable', 'exists:rooms,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'guest_id' => ['nullable', 'exists:guests,id'],
            'guest_name' => ['nullable', 'string', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'num_people' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'adults' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'vehicle_plate' => ['nullable', 'string', 'max:20'],
            'vehicle_desc' => ['nullable', 'string', 'max:100'],
            'eta' => ['nullable', 'date_format:H:i'],
            'confirmed' => ['sometimes', 'boolean'],
            'source_channel' => ['sometimes', Rule::in(['front_desk', 'phone', 'web', 'whatsapp', 'walk_in'])],
            'deposit_amount' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'guest_notes' => ['nullable', 'string'],
        ]);

        try {
            $reservation = $action->handle($data, $request->user());
        } catch (NoAvailabilityException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize($reservation->load(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type'])), 201);
    }

    public function update(UpdateReservationRequest $request, Reservation $reservation, UpdateReservation $action): JsonResponse
    {
        try {
            $reservation = $action->handle($reservation, $request->validated(), $request->user());
        } catch (NoAvailabilityException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize($reservation->load(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type'])));
    }

    public function confirm(Request $request, Reservation $reservation, TransitionReservation $action): JsonResponse
    {
        return $this->transition(fn () => $action->confirm($reservation, $request->user()), $reservation);
    }

    public function cancel(Request $request, Reservation $reservation, TransitionReservation $action): JsonResponse
    {
        $data = $request->validate([
            'no_show' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $to = $request->boolean('no_show') ? ReservationStatus::NoShow : ReservationStatus::Cancelled;

        return $this->transition(
            fn () => $action->cancel($reservation, $request->user(), $to, $data['reason'] ?? null),
            $reservation,
        );
    }

    public function checkIn(Request $request, Reservation $reservation, TransitionReservation $action): JsonResponse
    {
        return $this->transition(fn () => $action->checkIn($reservation, $request->user()), $reservation);
    }

    /**
     * Registra un abono (anticipo o liquidación) — spec §7.5.
     */
    public function registerPayment(Request $request, Reservation $reservation, RegisterReservationPayment $action): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(Payment::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $action->handle($reservation, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize(
            $reservation->refresh()->load(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type']),
        ));
    }

    protected function transition(callable $fn, Reservation $reservation): JsonResponse
    {
        try {
            $fn();
        } catch (NoAvailabilityException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize(
            $reservation->refresh()->load(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type']),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize(Reservation $r): array
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
            'guest_notes' => $r->guest_notes,
            'cancellation_reason' => $r->cancellation_reason,
            'room' => $r->room?->number,
            'room_id' => $r->room_id,
            'room_type' => $r->roomType?->name,
            'rate_plan' => $r->ratePlan?->name,
            'starts_at' => $r->starts_at->format('d/m/Y H:i'),
            'ends_at' => $r->ends_at->format('d/m/Y H:i'),
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'hold_expires_at' => $r->hold_expires_at?->format('d/m/Y H:i'),
            'source_channel' => $r->source_channel,
            'total_amount' => $r->total_amount,
            'deposit_amount' => $r->deposit_amount,
            'payment_status' => $r->payment_status->value,
            'payment_status_label' => $r->payment_status->label(),
            'payment_due_at' => $r->payment_due_at?->format('d/m/Y H:i'),
            'payment_overdue' => $r->isPaymentOverdue(),
            'paid_total' => $r->paidTotal(),
            'pending_balance' => $r->pendingBalance(),
            'payments' => $r->payments()->latest('paid_at')->get()->map(fn (Payment $p) => [
                'id' => $p->id,
                'amount' => $p->amount,
                'method' => Payment::methodLabel($p->method),
                'reference' => $p->reference,
                'paid_at' => $p->paid_at->format('d/m/Y H:i'),
                'received_by' => $p->receivedBy?->name,
            ]),
            'stay_id' => $r->stay?->id,
        ];
    }
}
