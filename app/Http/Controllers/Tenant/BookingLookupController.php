<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Consulta pública de reserva (/reserva): el huésped busca con su código
 * y su teléfono, y ve el resumen — estado, fechas, cuánto ha pagado y qué
 * falta, con el link de pago vigente si hay uno. El teléfono es la llave
 * anti-curiosos: el código solo no basta (viaja en mensajes y capturas),
 * y la respuesta de "no encontrado" es la misma exista o no el código.
 */
class BookingLookupController extends Controller
{
    public function page(): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        // Misma apariencia que el wizard de habitaciones (/reservas/ajustes):
        // una sola configuración para todas las páginas públicas.
        $appearance = $property->wizardAppearance();

        return Inertia::render('tenant/reservar/Lookup', [
            'appearance' => $appearance,
            // Redes, mapa y sitio del hotel para el pie de la página.
            'contact' => $property->publicContact(),
            'property' => [
                'name' => $property->name,
                'logo_url' => $appearance['logo_url'],
                'phone' => $settings['phone'] ?? null,
                'email' => $settings['email'] ?? null,
                'currency' => $settings['currency'] ?? 'MXN',
                // Las horas se leen en la zona del hotel: un huésped que
                // consulta desde otra zona veía la llegada corrida.
                'timezone' => $property->timezone ?: config('app.timezone'),
            ],
        ]);
    }

    public function find(Request $request): JsonResponse
    {
        // Folio de grupo (GRP-): el huésped que apartó varias habitaciones
        // de un jalón conoce ese folio, no el RES- de cada una. Antes la
        // consulta le respondía "no encontramos tu reserva".
        if ($this->isGroupCode($request)) {
            $group = $this->resolveGroup($request);

            return $group ? $this->groupSummary($group) : $this->notFound();
        }

        $reservation = $this->resolve($request);

        if ($reservation === null) {
            return $this->notFound();
        }

        return $this->summary($reservation);
    }

    /**
     * Cancelación autoservicio: solo cuando no hay dinero en riesgo — nada
     * pagado, o dentro de la ventana sin costo de la política de la tarifa.
     * Con dinero de por medio fuera de ventana, la decisión (retenciones,
     * reembolsos) es del hotel, no de un botón público.
     */
    public function cancel(Request $request): JsonResponse
    {
        $reservation = $this->resolve($request);

        if ($reservation === null) {
            return $this->notFound();
        }

        [$allowed, $reason] = $this->selfCancelState($reservation);

        if (! $allowed) {
            return response()->json(['message' => $reason], 422);
        }

        app(\App\Actions\Reservations\TransitionReservation::class)->cancel(
            $reservation,
            null,
            reason: 'Cancelada por el huésped desde la consulta pública.',
        );

        return $this->summary($reservation->refresh()->load(['roomType', 'ratePlan', 'guest']));
    }

    /**
     * Pre-registro en línea: el huésped completa sus datos antes de llegar
     * (nombre, correo, vehículo, hora estimada y notas) con las mismas
     * llaves código + teléfono. Solo tiene sentido antes del check-in
     * (Pendiente/Confirmada). El correo y el nombre se agregan a la ficha
     * del Guest únicamente si el CRM no los tenía — mismo criterio
     * fillMissing de CreateReservation: lo capturado por staff no se pisa.
     */
    public function preRegister(Request $request): JsonResponse
    {
        $reservation = $this->resolve($request);

        if ($reservation === null) {
            return $this->notFound();
        }

        if (! in_array($reservation->status, [\App\Enums\ReservationStatus::Pending, \App\Enums\ReservationStatus::Confirmed], true)) {
            return response()->json([
                'message' => 'Esta reserva ya no admite pre-registro; si necesitas actualizar tus datos, contacta al hotel.',
            ], 422);
        }

        $data = $request->validate([
            'guest_name' => ['nullable', 'string', 'max:120'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'vehicle_plate' => ['nullable', 'string', 'max:20'],
            'vehicle_desc' => ['nullable', 'string', 'max:120'],
            'eta' => ['nullable', 'date_format:H:i'],
            'guest_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // Vehículo, hora estimada y notas son del huésped: lo que mande es
        // la verdad (vacío = lo quitó). El nombre nunca se blanquea.
        $updates = [];
        foreach (['vehicle_plate', 'vehicle_desc', 'eta', 'guest_notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = trim((string) ($data[$field] ?? ''));
                $updates[$field] = $value === '' ? null : $value;
            }
        }

        $name = trim((string) ($data['guest_name'] ?? ''));
        if ($name !== '') {
            $updates['guest_name'] = $name;
        }

        $reservation->update($updates);

        if ($guest = $reservation->guest) {
            $fill = [];
            $email = trim((string) ($data['guest_email'] ?? ''));

            if ($email !== '' && ! $guest->email) {
                $fill['email'] = $email;
            }
            if ($name !== '' && blank($guest->first_name) && blank($guest->last_name)) {
                $fill['first_name'] = $name;
            }
            if ($fill !== []) {
                $guest->update($fill);
            }
        }

        return $this->summary($reservation->refresh()->load(['roomType', 'ratePlan', 'guest']));
    }

    /**
     * El huésped sube aquí su comprobante de transferencia. Las reservas
     * del wizard web no tienen chat: la página le decía "envía tu
     * comprobante al hotel" sin decirle a dónde. Queda pegado al cobro por
     * transferencia (Pagos lo enseña primero), sostiene el apartado
     * mientras el hotel verifica y suena la campana. No da nada por pagado.
     */
    public function proof(Request $request): JsonResponse
    {
        $group = $this->isGroupCode($request) ? $this->resolveGroup($request) : null;

        // Del grupo basta una habitación: el sostén y el cobro consolidado
        // se resuelven por su GRP-.
        $reservation = $group
            ? $this->proofMemberOf($group)
            : ($this->isGroupCode($request) ? null : $this->resolve($request));

        if ($reservation === null) {
            return $group
                ? response()->json(['message' => 'Este grupo ya no tiene un pago por transferencia pendiente; si necesitas algo, contacta al hotel.'], 422)
                : $this->notFound();
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ], [
            'file.required' => 'Elige la foto o el PDF de tu comprobante.',
            'file.mimes' => 'El comprobante debe ser una foto (JPG, PNG, WEBP) o un PDF.',
            'file.max' => 'El archivo pesa más de 8 MB; manda una captura de pantalla.',
        ]);

        if (! ($group ? $this->canSendGroupProof($group) : $this->canSendProof($reservation))) {
            return response()->json([
                'message' => 'Esta reserva ya no tiene un pago por transferencia pendiente; si necesitas algo, contacta al hotel.',
            ], 422);
        }

        $paymentRequest = app(\App\Services\Payments\PaymentProofHoldExtender::class)
            ->holdForUploadedProof($reservation, $request->file('file'));

        if ($paymentRequest === null) {
            return response()->json([
                'message' => 'Tu apartado ya venció y la habitación no se pudo sostener. Contacta al hotel con tu código para revisar tu caso.',
            ], 422);
        }

        try {
            app(\App\Services\StaffNotifier::class)->notify(
                type: \App\Models\StaffNotification::TYPE_PAYMENT,
                title: 'Comprobante recibido en línea',
                body: 'El huésped de '.($group?->displayCode() ?? $reservation->displayCode()).' subió su comprobante por '
                    .$paymentRequest->amountLabel().' desde la consulta de reserva. Verifícalo en Pagos.',
                url: '/pagos/verificar',
                subject: $reservation,
            );
        } catch (\Throwable $e) {
            report($e);
        }

        activity('reservation')
            ->performedOn($reservation)
            ->log('El huésped subió su comprobante de transferencia desde la consulta en línea');

        if ($group) {
            return $this->groupSummary($group->refresh());
        }

        return $this->summary($reservation->refresh()->load(['roomType', 'ratePlan', 'guest']));
    }

    /**
     * ¿Tiene sentido pedirle comprobante? Solo con saldo, con transferencia
     * aceptada por el hotel, y con la reserva viva (o un apartado que acaba
     * de vencer: el comprobante tardío lo puede reabrir).
     */
    protected function canSendProof(Reservation $reservation): bool
    {
        if ($reservation->pendingBalance() <= 0) {
            return false;
        }

        if (app(\App\Services\ReservationPolicy::class)->guestAccounts()->isEmpty()) {
            return false;
        }

        if (in_array($reservation->status, [\App\Enums\ReservationStatus::Pending, \App\Enums\ReservationStatus::Confirmed], true)) {
            return true;
        }

        return $reservation->isExpiredHold()
            && $reservation->hold_expires_at->gte(now()->subMinutes(
                app(\App\Services\ReservationPolicy::class)->proofReviewMinutes(),
            ));
    }

    /** Fecha del comprobante que ya espera verificación, si hay uno. */
    protected function proofReceivedAt(Reservation $reservation): ?string
    {
        $query = $reservation->reservation_group_id
            ? PaymentRequest::query()->where('reservation_group_id', $reservation->reservation_group_id)
            : $reservation->paymentRequests();

        $media = $query
            ->where('method', PaymentRequest::METHOD_TRANSFER)
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->whereHas('media', fn ($q) => $q->where('collection_name', 'receipt'))
            ->latest('id')
            ->first()
            ?->getFirstMedia('receipt');

        return $media?->created_at?->toIso8601String();
    }

    protected function isGroupCode(Request $request): bool
    {
        return str_starts_with(strtoupper(trim((string) $request->input('code'))), 'GRP-');
    }

    protected function resolveGroup(Request $request): ?ReservationGroup
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $group = ReservationGroup::query()
            ->with('guest')
            ->where('code', strtoupper(trim($data['code'])))
            ->first();

        if (! $group || ! $this->phoneMatchesAny($this->groupPhoneCandidates($group), $data['phone'])) {
            return null;
        }

        return $group;
    }

    /**
     * Teléfonos que abren el grupo: el de su huésped, los de las fichas de
     * sus habitaciones y los WhatsApp desde los que se apartaron.
     *
     * @return list<string>
     */
    protected function groupPhoneCandidates(ReservationGroup $group): array
    {
        $members = $group->reservations()->with('guest')->get();

        $candidates = [(string) $group->guest?->phone];

        foreach ($members as $member) {
            $candidates[] = (string) $member->guest?->phone;
        }

        return array_merge($candidates, $this->conversationPhones($members->pluck('id')->all()));
    }

    /** La habitación del grupo a la que se le cuelga el comprobante. */
    protected function proofMemberOf(ReservationGroup $group): ?Reservation
    {
        $members = $group->reservations()->latest('id')->get();

        return $members->first(fn (Reservation $r) => in_array($r->status, [\App\Enums\ReservationStatus::Pending, \App\Enums\ReservationStatus::Confirmed], true))
            ?? $members->first(fn (Reservation $r) => $this->canSendProof($r));
    }

    protected function canSendGroupProof(ReservationGroup $group): bool
    {
        $member = $this->proofMemberOf($group);

        if (! $member || app(\App\Services\ReservationPolicy::class)->guestAccounts()->isEmpty()) {
            return false;
        }

        if ($this->groupTotals($group)['pending'] <= 0) {
            return false;
        }

        return in_array($member->status, [\App\Enums\ReservationStatus::Pending, \App\Enums\ReservationStatus::Confirmed], true)
            || $this->canSendProof($member);
    }

    /**
     * Cuenta del grupo como la ve el huésped: habitaciones vivas (o con
     * dinero aunque se hayan caído) y experiencias vivas colgadas del GRP-.
     *
     * @return array{members: \Illuminate\Support\Collection<int, Reservation>, experiences: \Illuminate\Support\Collection<int, \App\Models\ExperienceBooking>, total: float, paid: float, pending: float}
     */
    protected function groupTotals(ReservationGroup $group): array
    {
        $members = $group->reservations()
            ->with('roomType')
            ->withSum('payments', 'amount')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $counted = $members->filter(fn (Reservation $r) => ! in_array($r->status, [\App\Enums\ReservationStatus::Cancelled, \App\Enums\ReservationStatus::NoShow], true)
            || $r->paidTotal() > 0);

        $experiences = $group->liveExperienceBookings()
            ->with('session.experience')
            ->withSum('payments', 'amount')
            ->get();

        $total = round($counted->sum(fn (Reservation $r) => (float) $r->total_amount)
            + $experiences->sum(fn ($b) => (float) $b->total), 2);
        $paid = round($counted->sum(fn (Reservation $r) => $r->paidTotal())
            + $experiences->sum(fn ($b) => (float) ($b->payments_sum_amount ?? 0)), 2);

        return [
            'members' => $members,
            'experiences' => $experiences,
            'total' => $total,
            'paid' => $paid,
            'pending' => max(0, round($total - $paid, 2)),
        ];
    }

    protected function groupSummary(ReservationGroup $group): JsonResponse
    {
        $totals = $this->groupTotals($group);
        $members = $totals['members'];
        $live = $members->filter(fn (Reservation $r) => in_array($r->status, [
            \App\Enums\ReservationStatus::Pending,
            \App\Enums\ReservationStatus::Confirmed,
            \App\Enums\ReservationStatus::CheckedIn,
        ], true));
        $shown = $live->isNotEmpty() ? $live : $members;

        $statuses = $members->map(fn (Reservation $r) => $r->status);
        [$status, $label] = match (true) {
            $statuses->every(fn ($st) => in_array($st, [\App\Enums\ReservationStatus::Cancelled, \App\Enums\ReservationStatus::NoShow], true)) => ['cancelled', 'Cancelado'],
            $statuses->contains(\App\Enums\ReservationStatus::CheckedIn) => ['checked_in', 'Hospedados'],
            $statuses->contains(\App\Enums\ReservationStatus::Pending) => ['pending', 'Pendiente'],
            $statuses->contains(\App\Enums\ReservationStatus::Confirmed) => ['confirmed', 'Confirmado'],
            default => ['completed', 'Concluido'],
        };

        $pending = $group->paymentRequests()->active()->latest('id')->first();
        $accounts = $pending?->method === PaymentRequest::METHOD_TRANSFER
            ? app(\App\Services\ReservationPolicy::class)->guestAccounts()
            : collect();

        $payments = \App\Models\Payment::query()
            ->where(fn ($q) => $q
                ->whereIn('reservation_id', $members->pluck('id'))
                ->orWhereIn('experience_booking_id', $totals['experiences']->pluck('id')))
            ->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '<>', \App\Models\Payment::KIND_GUARANTEE))
            ->where('amount', '>', 0)
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get(['amount', 'method', 'paid_at', 'created_at']);

        $holdMember = $members
            ->filter(fn (Reservation $r) => $r->status === \App\Enums\ReservationStatus::Pending && $r->hold_expires_at)
            ->sortBy('hold_expires_at')
            ->first();

        return response()->json([
            'kind' => 'group',
            'code' => $group->displayCode(),
            'guest_name' => $group->guest_name ?? $group->guest?->full_name,
            'status' => $status,
            'status_label' => $label,
            'starts_at' => $shown->min('starts_at')?->toIso8601String(),
            'ends_at' => $shown->max('ends_at')?->toIso8601String(),
            'adults' => (int) $shown->sum('adults'),
            'children' => (int) $shown->sum('children'),
            'total' => $totals['total'],
            'paid' => $totals['paid'],
            'pending_balance' => $totals['pending'],
            'payment_status_label' => match (true) {
                $totals['pending'] <= 0 && $totals['total'] > 0 => 'Pagado',
                $totals['paid'] > 0 => 'Pago parcial',
                $status === 'cancelled' => 'Sin cargo',
                default => 'Sin pago',
            },
            'payment_due_at' => $live->pluck('payment_due_at')->filter()->min()?->toIso8601String(),
            'hold_expires_at' => $holdMember?->hold_expires_at?->toIso8601String(),
            'payments' => $payments->map(fn (\App\Models\Payment $payment) => [
                'amount' => (float) $payment->amount,
                'method_label' => \App\Models\Payment::methodLabel((string) $payment->method),
                'paid_at' => ($payment->paid_at ?? $payment->created_at)?->toIso8601String(),
            ])->values(),
            'proof' => [
                'can_send' => $this->canSendGroupProof($group),
                'received_at' => ($member = $members->first()) ? $this->proofReceivedAt($member) : null,
            ],
            'pending_request' => $pending ? [
                'method' => $pending->method,
                'amount' => (float) $pending->amount,
                'amount_label' => $pending->amountLabel(),
                'checkout_url' => $pending->checkout_url,
                'expires_at' => $pending->expires_at?->toIso8601String(),
                'bank_accounts' => $accounts,
                'covers_group' => true,
            ] : null,
            // Cada habitación abre su propia ficha (pre-registro,
            // cancelación) con el mismo teléfono.
            'rooms' => $members->map(fn (Reservation $r) => [
                'code' => $r->displayCode(),
                'room_type' => $r->roomType?->name,
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'starts_at' => $r->starts_at->toIso8601String(),
                'ends_at' => $r->ends_at->toIso8601String(),
                'adults' => (int) $r->adults,
                'children' => (int) $r->children,
                'total' => (float) $r->total_amount,
                'paid' => $r->paidTotal(),
            ])->values(),
            'experiences' => $totals['experiences']->map(fn ($b) => [
                'code' => $b->code,
                'name' => $b->session?->experience?->name ?? 'Experiencia',
                'starts_at' => $b->session?->starts_at?->toIso8601String(),
                'people' => (int) $b->people,
                'status_label' => \App\Models\ExperienceBooking::STATUS_LABELS[$b->status] ?? $b->status,
                'total' => (float) $b->total,
            ])->values(),
        ]);
    }

    protected function resolve(Request $request): ?Reservation
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        // Se tolera el código sin el prefijo RES- (el huésped a veces solo
        // copia los números).
        $code = strtoupper(trim($data['code']));

        $reservation = Reservation::query()
            ->with(['roomType', 'ratePlan', 'guest'])
            ->whereIn('code', array_unique([$code, 'RES-'.ltrim($code, 'RES-')]))
            ->first();

        if (! $reservation || ! $this->phoneMatches($reservation, $data['phone'])) {
            return null;
        }

        return $reservation;
    }

    protected function notFound(): JsonResponse
    {
        return response()->json([
            'message' => 'No encontramos una reserva con ese código y teléfono. Revisa ambos datos e intenta de nuevo.',
        ], 404);
    }

    protected function summary(Reservation $reservation): JsonResponse
    {
        // Una habitación de grupo se cobra con el cobro consolidado del GRP-
        // (no lleva reservation_id): sin esto, quien consultaba su RES- no
        // veía ni la liga de pago ni las cuentas.
        $groupRequest = $reservation->reservation_group_id
            ? PaymentRequest::query()
                ->where('reservation_group_id', $reservation->reservation_group_id)
                ->active()
                ->latest('id')
                ->first()
            : null;
        $pending = $groupRequest ?? $reservation->paymentRequests()->active()->latest('id')->first();

        $settings = Property::firstOrFail()->settings ?? [];
        // Mismo mapeo que el wizard (ReservationPolicy): duplicarlo aquí a
        // mano era la vía segura de que esta pantalla se quedara atrás.
        $accounts = $pending?->method === PaymentRequest::METHOD_TRANSFER
            ? app(\App\Services\ReservationPolicy::class)->guestAccounts()
            : collect();

        // Cancelada, al huésped ya no se le cobra nada: la consulta decía
        // "Por pagar $590" en un apartado que venció sin pago. Lo pagado
        // sigue a la vista (el reembolso lo resuelve el hotel).
        $cancelled = $reservation->status === \App\Enums\ReservationStatus::Cancelled;
        $paid = $reservation->paidTotal();

        return response()->json([
            'kind' => 'reservation',
            'code' => $reservation->displayCode(),
            'status' => $reservation->status->value,
            'status_label' => $reservation->status->label(),
            'room_type' => $reservation->roomType?->name,
            // Parte de un grupo GRP-: el huésped reconoce ese folio, no el
            // de cada habitación.
            'group_code' => $reservation->group?->code,
            'starts_at' => $reservation->starts_at->toIso8601String(),
            'ends_at' => $reservation->ends_at->toIso8601String(),
            'adults' => (int) $reservation->adults,
            'children' => (int) $reservation->children,
            'total' => (float) $reservation->total_amount,
            'paid' => $paid,
            'pending_balance' => $cancelled ? 0.0 : $reservation->pendingBalance(),
            'payment_status_label' => $cancelled
                ? ($paid > 0 ? 'Cancelada con pagos registrados' : 'Sin cargo')
                : $reservation->payment_status->label(),
            'payment_due_at' => $reservation->payment_due_at?->toIso8601String(),
            'hold_expires_at' => $reservation->hold_expires_at?->toIso8601String(),
            // Abonos que ya cuentan (la fianza no es parte de la cuenta).
            'payments' => $reservation->payments()
                ->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '<>', \App\Models\Payment::KIND_GUARANTEE))
                ->where('amount', '>', 0)
                ->orderBy('paid_at')
                ->orderBy('id')
                ->get(['amount', 'method', 'paid_at', 'created_at'])
                ->map(fn (\App\Models\Payment $payment) => [
                    'amount' => (float) $payment->amount,
                    'method_label' => \App\Models\Payment::methodLabel((string) $payment->method),
                    'paid_at' => ($payment->paid_at ?? $payment->created_at)?->toIso8601String(),
                ])
                ->values(),
            'proof' => [
                'can_send' => $this->canSendProof($reservation),
                'received_at' => $this->proofReceivedAt($reservation),
            ],
            // Cobro vigente: con pasarela el huésped puede pagar desde aquí
            // mismo; con transferencia se le repiten las cuentas.
            'pending_request' => $pending ? [
                'method' => $pending->method,
                'amount' => (float) $pending->amount,
                'amount_label' => $pending->amountLabel(),
                'checkout_url' => $pending->checkout_url,
                'expires_at' => $pending->expires_at?->toIso8601String(),
                'bank_accounts' => $accounts,
                'covers_group' => $groupRequest !== null,
            ] : null,
            // Pre-registro: lo que ya sabemos, para precargar el formulario
            // público (solo editable en Pendiente/Confirmada).
            'pre_registration' => [
                'guest_name' => $reservation->guest_name ?? $reservation->guest?->full_name,
                'guest_email' => $reservation->guest?->email,
                'has_email' => (bool) $reservation->guest?->email,
                'vehicle_plate' => $reservation->vehicle_plate,
                'vehicle_desc' => $reservation->vehicle_desc,
                'eta' => $reservation->eta ? substr((string) $reservation->eta, 0, 5) : null,
                'guest_notes' => $reservation->guest_notes,
            ],
            'can_cancel' => $this->selfCancelState($reservation)[0],
            'cancellation_policy' => app(\App\Services\ReservationPolicy::class)
                ->cancellationPolicyLabel($reservation->ratePlan),
            // Una reserva de último momento tiene sus propias condiciones
            // (cabañas: sin reembolso, reagendar o fecha pendiente).
            'cancellation_policy_text' => ((float) $reservation->deposit_amount > 0
                && app(\App\Services\ReservationPolicy::class)->isShortNotice($reservation->starts_at, $reservation->created_at)
                    ? app(\App\Services\ReservationPolicy::class)->shortNoticePolicyText()
                    : null)
                ?? app(\App\Services\ReservationPolicy::class)->cancellationPolicyText(),
            // Estimación honesta para el huésped: "si cancelas ahora, según
            // la política te corresponden $X". El reembolso en sí siempre lo
            // ejecuta el hotel, nunca este botón.
            'cancel_refund_estimate' => in_array($reservation->status, [\App\Enums\ReservationStatus::Pending, \App\Enums\ReservationStatus::Confirmed], true)
                ? $reservation->suggestedRefund()
                : null,
            // Fianza que le van a cobrar al llegar: la consulta pública es
            // justo donde el huésped repasa su reserva antes de salir de
            // casa, así que es el mejor momento para recordárselo. Con el
            // escalón de su grupo, no el monto base.
            'guarantee' => app(\App\Services\ReservationPolicy::class)->guaranteeEnabled()
                ? [
                    'amount' => app(\App\Services\ReservationPolicy::class)
                        ->guaranteeAmountForReservation($reservation),
                ]
                : null,
        ]);
    }

    /**
     * ¿Puede cancelar el propio huésped? y si no, por qué.
     *
     * @return array{0: bool, 1: string}
     */
    protected function selfCancelState(Reservation $reservation): array
    {
        if (! in_array($reservation->status, [\App\Enums\ReservationStatus::Pending, \App\Enums\ReservationStatus::Confirmed], true)) {
            return [false, 'Esta reserva ya no se puede cancelar desde aquí; contacta al hotel.'];
        }

        // Sin dinero pagado no hay nada en riesgo: se cancela directo.
        if ($reservation->paidTotal() <= 0) {
            return [true, ''];
        }

        // Ventana efectiva: la de la tarifa o la default del hotel.
        $deadline = app(\App\Services\ReservationPolicy::class)
            ->cancelFreeDeadlineFor($reservation->ratePlan, $reservation->starts_at);

        if ($deadline !== null && now()->lte($deadline)) {
            return [true, ''];
        }

        return [false, 'Tu reserva tiene pagos registrados y ya no está en la ventana de cancelación sin costo; contacta al hotel para revisar tu caso.'];
    }

    /**
     * El teléfono capturado debe coincidir con el de la reserva: se
     * comparan los últimos 8 dígitos (tolera lada de país presente o
     * ausente en cualquiera de los dos lados).
     */
    protected function phoneMatches(Reservation $reservation, string $input): bool
    {
        // El contacto vive en el Guest ligado, no en la reserva. Si la ficha
        // no tiene número, vale el WhatsApp desde el que se hizo la reserva:
        // es exactamente "el teléfono con el que reservaste" que le pide el
        // aviso. Caso real cabañas 2026-09-13 (RES-2026-1724): reservas del
        // bot con la ficha sin teléfono que la consulta nunca encontraba.
        $candidates = [(string) $reservation->guest?->phone];

        $ids = [$reservation->id];

        // Una habitación de grupo abre también con el teléfono del grupo:
        // es el que abrió el GRP- desde el que el huésped llegó aquí.
        if ($group = $reservation->group) {
            $candidates[] = (string) $group->guest?->phone;
            $ids = $group->reservations()->pluck('id')->all();
        }

        return $this->phoneMatchesAny(array_merge($candidates, $this->conversationPhones($ids)), $input);
    }

    /**
     * WhatsApp desde los que se apartaron estas reservas (solo canales donde
     * el contacto ES un teléfono).
     *
     * @param  list<int>  $reservationIds
     * @return list<string>
     */
    protected function conversationPhones(array $reservationIds): array
    {
        return \App\Models\Conversation::query()
            ->whereIn('reservation_id', $reservationIds)
            ->with('channel')
            ->get()
            ->filter(fn (\App\Models\Conversation $conversation) => $conversation->phoneIsIdentity())
            ->map(fn (\App\Models\Conversation $conversation) => (string) $conversation->contact_phone)
            ->values()
            ->all();
    }

    /** @param  list<string>  $candidates */
    protected function phoneMatchesAny(array $candidates, string $input): bool
    {
        $given = preg_replace('/\D+/', '', $input);

        if (strlen($given) < 4) {
            return false;
        }

        foreach (array_filter(array_map(fn ($phone) => preg_replace('/\D+/', '', $phone), $candidates)) as $stored) {
            // Se pide el número completo (últimos 8): con los últimos 4 y un
            // folio consecutivo, cualquiera abría —y cancelaba— una reserva
            // ajena probando 10 mil combinaciones. Solo un número guardado
            // más corto que 8 se compara entero.
            $length = min(8, strlen($stored));

            if (strlen($given) < $length) {
                continue;
            }

            if (substr($stored, -$length) === substr($given, -$length)) {
                return true;
            }
        }

        return false;
    }
}
