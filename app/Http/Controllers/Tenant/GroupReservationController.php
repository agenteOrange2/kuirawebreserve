<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Reservations\CreateGroupReservation;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\TransitionReservation;
use App\Enums\ReservationStatus;
use App\Exceptions\NoAvailabilityException;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\RoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Reservas grupales desde el panel (módulo `grupos`): crear el grupo
 * (todo-o-nada) y cancelarlo completo. Las reservas individuales se
 * siguen operando una por una en /reservas, como siempre.
 */
class GroupReservationController extends Controller
{
    public function store(Request $request, CreateGroupReservation $action): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['night', 'block'])],
            'starts_at' => ['required', 'date', 'after_or_equal:now'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
            'confirmed' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:10'],
            'lines.*.room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'lines.*.rooms' => ['required', 'integer', 'min:1', 'max:30'],
            'lines.*.adults' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'lines.*.children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            // Experiencias como plus del grupo (módulo `experiencias`).
            'experiences' => ['sometimes', 'array', 'max:5'],
            'experiences.*.session_id' => ['required_with:experiences', 'integer', 'exists:experience_sessions,id'],
            'experiences.*.people' => ['required_with:experiences', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $group = $action->handle($data, $request->user());
        } catch (NoAvailabilityException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(self::serialize($group->fresh()->load('reservations.roomType', 'reservations.room', 'experienceBookings.session.experience')), 201);
    }

    /**
     * Edita los datos propios del grupo (responsable y notas). Las
     * habitaciones se siguen operando una por una en /reservas.
     */
    public function update(Request $request, ReservationGroup $group): JsonResponse
    {
        $data = $request->validate([
            'guest_name' => ['sometimes', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $group->update($data);

        return response()->json(self::serialize($group->fresh()->load('reservations.roomType', 'reservations.room', 'experienceBookings.session.experience')));
    }

    /**
     * Agrega habitaciones a un grupo existente ("se me olvidó una cabaña"):
     * mismas fechas y modalidad que el grupo, misma resolución de tarifa
     * que el alta, mismos locks anti-doble-venta. Todo-o-nada dentro de la
     * adición: si alguna no tiene disponibilidad, no se agrega ninguna.
     */
    public function addRooms(Request $request, ReservationGroup $group, CreateReservation $create): JsonResponse
    {
        $data = $request->validate([
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'rooms' => ['required', 'integer', 'min:1', 'max:10'],
            'adults' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:20'],
        ]);

        $reference = $this->referenceReservation($group);

        if (! $reference) {
            return response()->json(['message' => 'El grupo no tiene ninguna reserva de referencia para tomar fechas.'], 422);
        }

        $type = RoomType::query()->where('active', true)->findOrFail($data['room_type_id']);
        $mode = $reference->ratePlan?->type->value ?? 'night';

        $ratePlan = $type->ratePlans()
            ->where('active', true)
            ->where('type', $mode)
            ->orderBy('price')
            ->first();

        if (! $ratePlan) {
            return response()->json(['message' => "El tipo {$type->name} no tiene tarifa activa en la modalidad del grupo."], 422);
        }

        // Mismas fechas del grupo; por noche cada tipo puede tener su horario.
        $start = $reference->starts_at;
        $end = $reference->ends_at;
        if ($mode === 'night') {
            [[$inHour, $inMinute], [$outHour, $outMinute]] = $type->effectiveScheduleTimes();
            $start = $start->copy()->setTime($inHour, $inMinute);
            $end = $end?->copy()->setTime($outHour, $outMinute);
        }

        // Si el grupo ya está confirmado, lo agregado nace confirmado.
        $confirmed = $group->reservations()
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn])
            ->exists();

        try {
            DB::transaction(function () use ($data, $group, $create, $ratePlan, $start, $end, $confirmed) {
                for ($i = 0; $i < (int) $data['rooms']; $i++) {
                    $reservation = $create->handle([
                        'rate_plan_id' => $ratePlan->id,
                        'starts_at' => $start,
                        'ends_at' => $end,
                        'confirmed' => $confirmed,
                        'source_channel' => 'front_desk',
                        'guest_name' => $group->guest_name,
                        'guest_id' => $group->guest_id,
                        'adults' => $data['adults'] ?? 1,
                        'children' => $data['children'] ?? 0,
                        'notes' => "Agregada al grupo {$group->displayCode()}.",
                    ], request()->user());

                    $reservation->forceFill(['reservation_group_id' => $group->id])->saveQuietly();
                }
            });
        } catch (NoAvailabilityException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->voidPendingGroupCharges($group);

        return response()->json(self::serialize($group->fresh()->load('reservations.roomType', 'reservations.room', 'experienceBookings.session.experience')));
    }

    /**
     * Agrega un recorrido/experiencia al grupo — cuelga del GRP- y suma al
     * total consolidado; cupo duro bajo lock como siempre.
     */
    public function addExperience(Request $request, ReservationGroup $group, \App\Actions\Experiences\CreateExperienceBooking $action): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'exists:experience_sessions,id'],
            'people' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $confirmed = $group->reservations()
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn])
            ->exists();

        try {
            $action->handle([
                'experience_session_id' => $data['session_id'],
                'people' => $data['people'],
                'reservation_group_id' => $group->id,
                'guest_id' => $group->guest_id,
                'guest_name' => $group->guest_name,
                'confirmed' => $confirmed,
            ], $request->user());
        } catch (NoAvailabilityException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->voidPendingGroupCharges($group);

        return response()->json(self::serialize($group->fresh()->load('reservations.roomType', 'reservations.room', 'experienceBookings.session.experience')));
    }

    /**
     * Emite el cobro consolidado desde el panel (link de pasarela o
     * transferencia) — mismo IssueGroupPayment del wizard; el staff copia
     * el link y lo comparte por el canal que sea.
     */
    public function issuePayment(Request $request, ReservationGroup $group, \App\Actions\Payments\IssueGroupPayment $issuer): JsonResponse
    {
        $preferred = $request->string('method')->toString();
        $preferred = in_array($preferred, ['gateway', 'transfer'], true) ? $preferred : 'gateway';

        $gate = app(\App\Services\Payments\PaymentMethodGate::class);
        $enabled = $gate->methodsFor((string) tenant('id'));

        $link = null;
        if ($preferred === 'gateway') {
            $link = \App\Models\Central\PaymentGatewayLink::query()
                ->where('tenant_id', (string) tenant('id'))
                ->where('active', true)
                ->whereIn('provider', array_keys(array_filter([
                    'stripe' => $enabled['stripe'],
                    'mercadopago' => $enabled['mercadopago'],
                    'paypal' => $enabled['paypal'],
                ])))
                ->orderBy('id')
                ->first();

            if (! $link) {
                return response()->json(['message' => 'No hay pasarela activa; emite el cobro por transferencia o conecta una en Métodos de pago.'], 422);
            }
        }

        try {
            $paymentRequest = $issuer->handle(
                $group,
                $link ? \App\Models\PaymentRequest::METHOD_GATEWAY : \App\Models\PaymentRequest::METHOD_TRANSFER,
                $request->user(),
                $link,
            );
        } catch (InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'method' => $paymentRequest->method,
            'amount' => (float) $paymentRequest->amount,
            'amount_label' => $paymentRequest->amountLabel(),
            'checkout_url' => $paymentRequest->checkout_url,
            'expires_at' => $paymentRequest->expires_at?->toIso8601String(),
        ], 201);
    }

    /** Primera reserva viva del grupo (o la última que exista) como referencia de fechas. */
    protected function referenceReservation(ReservationGroup $group): ?Reservation
    {
        return $group->reservations()
            ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::CheckedIn])
            ->orderBy('starts_at')
            ->first()
            ?? $group->reservations()->latest('id')->first();
    }

    /**
     * El total del grupo cambió: un cobro pendiente con el monto viejo ya
     * no corresponde — se cancela y el siguiente "Generar cobro" emite el
     * correcto (spec-pagos §6.4).
     */
    protected function voidPendingGroupCharges(ReservationGroup $group): void
    {
        $group->paymentRequests()
            ->where('status', \App\Models\PaymentRequest::STATUS_PENDING)
            ->update(['status' => \App\Models\PaymentRequest::STATUS_CANCELED, 'updated_at' => now()]);
    }

    /**
     * Elimina un grupo MUERTO (limpieza): sin reservas vivas, sin tours
     * vivos y sin dinero cobrado. Las reservas canceladas se sueltan del
     * folio (FK nullOnDelete) y siguen visibles en /reservas — borrar el
     * grupo nunca borra historial.
     */
    public function destroy(ReservationGroup $group): JsonResponse
    {
        $hasLiveRooms = $group->reservations()
            ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::CheckedIn])
            ->exists();

        if ($hasLiveRooms || $group->liveExperienceBookings()->exists()) {
            return response()->json([
                'message' => 'El grupo tiene reservas vivas: cancélalo primero para liberar habitaciones y cupos.',
            ], 422);
        }

        if ($group->paymentRequests()->where('status', \App\Models\PaymentRequest::STATUS_PAID)->exists()) {
            return response()->json([
                'message' => 'El grupo tiene pagos registrados; se conserva por el rastro contable.',
            ], 422);
        }

        $group->paymentRequests()
            ->where('status', \App\Models\PaymentRequest::STATUS_PENDING)
            ->update(['status' => \App\Models\PaymentRequest::STATUS_CANCELED, 'updated_at' => now()]);

        $group->delete();

        return response()->json(status: 204);
    }

    /**
     * Cancela el grupo completo: cada reserva viva pasa por la transición
     * normal (libera habitación y deja rastro); las que ya avanzaron
     * (check-in) no se tocan.
     */
    public function cancel(Request $request, ReservationGroup $group, TransitionReservation $transition): JsonResponse
    {
        $cancelled = 0;

        foreach ($group->reservations as $reservation) {
            if (in_array($reservation->status, [ReservationStatus::Pending, ReservationStatus::Confirmed], true)) {
                $transition->cancel($reservation, $request->user(), reason: "Cancelación del grupo {$group->displayCode()}.");
                $cancelled++;
            }
        }

        // Las experiencias colgadas del grupo sueltan su cupo con él.
        $group->liveExperienceBookings()
            ->update(['status' => \App\Models\ExperienceBooking::STATUS_CANCELLED, 'updated_at' => now()]);

        return response()->json([
            ...self::serialize($group->fresh()->load('reservations.roomType', 'reservations.room', 'experienceBookings.session.experience')),
            'cancelled' => $cancelled,
        ]);
    }

    /** @return array<string, mixed> */
    public static function serialize(ReservationGroup $group): array
    {
        $reservations = $group->reservations;
        $experienceBookings = $group->relationLoaded('experienceBookings')
            ? $group->experienceBookings
            : $group->experienceBookings()->with('session.experience')->get();
        $liveExperiences = $experienceBookings->whereIn('status', [
            \App\Models\ExperienceBooking::STATUS_PENDING,
            \App\Models\ExperienceBooking::STATUS_CONFIRMED,
        ]);

        return [
            'id' => $group->id,
            'code' => $group->displayCode(),
            'guest_name' => $group->guest_name,
            'notes' => $group->notes,
            'rooms' => $reservations->count(),
            'experiences' => $experienceBookings->map(fn (\App\Models\ExperienceBooking $booking) => [
                'id' => $booking->id,
                'code' => $booking->displayCode(),
                'name' => $booking->session?->experience?->name,
                'starts_at' => $booking->session?->starts_at?->toIso8601String(),
                'people' => $booking->people,
                'total' => (float) $booking->total,
                'status' => $booking->status,
                'status_label' => $booking->statusLabel(),
            ])->values(),
            'total' => round((float) $reservations->sum('total_amount') + (float) $liveExperiences->sum('total'), 2),
            'starts_at' => $reservations->min('starts_at')?->toIso8601String(),
            'ends_at' => $reservations->max('ends_at')?->toIso8601String(),
            'created_at' => $group->created_at->toIso8601String(),
            'reservations' => $reservations->map(fn (Reservation $reservation) => [
                'id' => $reservation->id,
                'code' => $reservation->displayCode(),
                'room_type' => $reservation->roomType?->name,
                'room' => $reservation->room?->number,
                'adults' => (int) $reservation->adults,
                'children' => (int) $reservation->children,
                'total' => (float) $reservation->total_amount,
                'status' => $reservation->status->value,
                'status_label' => $reservation->status->label(),
            ])->values(),
        ];
    }
}
