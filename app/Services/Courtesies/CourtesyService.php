<?php

namespace App\Services\Courtesies;

use App\Enums\ReservationStatus;
use App\Exceptions\CourtesyNeedsConfirmation;
use App\Jobs\SendCourtesyNoticeMail;
use App\Models\Courtesy;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\StaffNotification;
use App\Models\Stay;
use App\Models\User;
use App\Services\CouponService;
use App\Services\ReservationPolicy;
use App\Services\StaffAccess;
use App\Services\StaffNotifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Único escritor de las cortesías (módulo cortesias).
 *
 * Una cortesía baja el TOTAL de la cuenta, igual que el cupón; nunca es un
 * Payment, porque CashLedger lo contaría como dinero que entró. Solo toca el
 * hospedaje (tarifa y persona extra): consumos, extras, cargos opcionales,
 * daños y fianza se cobran normal.
 *
 * El total se rearma siempre como bruto − cupón − cortesía, con el bruto =
 * total_amount + discount_amount + courtesy_amount. Así dar, cambiar y
 * retirar una cortesía no encoge la cuenta de a poquito.
 *
 * Cupón y cortesía no se acumulan: aprobar una cortesía retira el cupón (y
 * devuelve su uso); con una cortesía viva el cupón no se puede aplicar.
 */
class CourtesyService
{
    public function __construct(
        protected StaffNotifier $notifier,
        protected CouponService $coupons,
        protected ReservationPolicy $policy,
    ) {}

    public function isOwner(?User $user): bool
    {
        return $user !== null && $user->hasRole(StaffAccess::OWNER);
    }

    /**
     * Sobre qué se da la cortesía: la reserva si la hay (su total manda el
     * folio aunque el huésped ya esté adentro), si no la estancia de
     * mostrador.
     */
    public function targetFor(Reservation|Stay $model): Reservation|Stay
    {
        return $model instanceof Stay && $model->reservation_id
            ? $model->reservation
            : $model;
    }

    /**
     * ¿Se le puede dar (o decidir) una cortesía a esta cuenta? Mientras está
     * viva, y también cuando ya salió debiendo y sigue en Cuentas por cerrar:
     * "salir sin cobrar" deja la decisión al propietario, y para eso la
     * cuenta tiene que seguir abierta a la cortesía. Cerrada con motivo,
     * cancelada o no-show, ya no.
     */
    public function isLive(Reservation|Stay $target): bool
    {
        if ($target instanceof Reservation) {
            return in_array($target->status, [ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::CheckedIn], true)
                || ($target->status === ReservationStatus::Completed && $target->settlement_closed_at === null);
        }

        return $target->status === Stay::STATUS_ACTIVE
            || ($target->status === Stay::STATUS_COMPLETED && $target->settlement_closed_at === null);
    }

    public function liveFor(Reservation|Stay $target): ?Courtesy
    {
        return Courtesy::query()
            ->where($target instanceof Reservation ? 'reservation_id' : 'stay_id', $target->id)
            ->live()
            ->latest('id')
            ->first();
    }

    /**
     * Bruto de la cuenta sin cupón ni cortesía.
     */
    public function gross(Reservation|Stay $target): float
    {
        return $target instanceof Reservation
            ? round((float) $target->total_amount + (float) $target->discount_amount + (float) $target->courtesy_amount, 2)
            : round((float) $target->amount + (float) $target->courtesy_amount, 2);
    }

    /**
     * Lo que es hospedaje dentro de un bruto: se le quita todo lo que no es
     * tarifa ni persona extra (productos, extras y experiencias de la
     * reserva; cargos opcionales, daños y salida tarde).
     *
     * @param  array<int, array<string, mixed>>|null  $extraCharges  las líneas
     *                                                               nuevas cuando quien llama acaba de recalcularlas
     */
    public function lodgingBase(Reservation|Stay $target, ?float $gross = null, ?array $extraCharges = null): float
    {
        $gross ??= $this->gross($target);

        $nonLodging = collect($extraCharges ?? $target->extra_charges ?? [])
            ->reject(fn ($line) => ($line['kind'] ?? '') === 'extra_guests')
            ->sum(fn ($line) => (float) ($line['amount'] ?? 0));

        if ($target instanceof Reservation) {
            $nonLodging += array_sum(array_column($target->products ?? [], 'total'))
                + array_sum(array_column($target->extras ?? [], 'total'))
                + array_sum(array_column($target->experiences ?? [], 'total'));
        }

        return max(0, round($gross - $nonLodging, 2));
    }

    public function compute(string $kind, float $value, float $base): float
    {
        $amount = match ($kind) {
            Courtesy::KIND_FULL => $base,
            Courtesy::KIND_PERCENT => $base * min(100, max(0, $value)) / 100,
            default => min(max(0, $value), $base),
        };

        return round(max(0, min($amount, $base)), 2);
    }

    public function monthlyCap(): ?float
    {
        $cap = (Property::query()->first()?->settings ?? [])['courtesy_monthly_cap'] ?? null;

        return $cap === null || $cap === '' ? null : round((float) $cap, 2);
    }

    /** Lo regalado este mes (aprobado), sin contar la cortesía que se decide. */
    public function monthUsage(?Courtesy $except = null): float
    {
        return round((float) Courtesy::query()
            ->where('status', Courtesy::STATUS_APPROVED)
            ->where('decided_at', '>=', now()->startOfMonth())
            ->when($except?->id, fn ($q) => $q->whereKeyNot($except->id))
            ->sum('amount'), 2);
    }

    /**
     * Vista previa para el diálogo: cuánto baja y cómo queda el tope.
     *
     * @return array<string, mixed>
     */
    public function preview(Reservation|Stay $target, string $kind, float $value, ?Courtesy $except = null): array
    {
        $gross = $this->gross($target);
        $base = $this->lodgingBase($target, $gross);
        $amount = $this->compute($kind, $value, $base);
        // La cortesía retira el cupón: el total nuevo ya no lo trae.
        $total = round(max(0, $gross - $amount), 2);
        $paid = $this->paidLodging($target);
        $cap = $this->monthlyCap();
        $used = $this->monthUsage($except);

        return [
            'gross' => $gross,
            'lodging_base' => $base,
            'amount' => $amount,
            'coupon_code' => $target instanceof Reservation ? $target->coupon_code : null,
            'coupon_discount' => $target instanceof Reservation ? (float) $target->discount_amount : 0.0,
            'total_before' => $target instanceof Reservation ? (float) $target->total_amount : (float) $target->amount,
            'total_after' => $total,
            'paid' => $paid,
            'refund_due' => round(max(0, $paid - $total), 2),
            'cap' => $cap,
            'month_used' => $used,
            'over_cap' => $cap !== null && $used + $amount > $cap,
        ];
    }

    /**
     * Dar (propietario) o solicitar (cualquier otro) una cortesía.
     *
     * @param  array{kind: string, value?: float|int|string|null, category: string, reason: string}  $data
     *
     * @throws InvalidArgumentException
     * @throws CourtesyNeedsConfirmation
     */
    public function request(Reservation|Stay $target, array $data, User $user, bool $confirmOverCap = false): Courtesy
    {
        $target = $this->targetFor($target);
        $owner = $this->isOwner($user);

        $courtesy = DB::transaction(function () use ($target, $data, $user, $owner, $confirmOverCap) {
            $target = $this->lock($target);
            $this->assertLive($target);

            if ($live = $this->liveFor($target)) {
                throw new InvalidArgumentException($live->isPending()
                    ? 'Esta cuenta ya tiene una cortesía esperando al propietario.'
                    : 'Esta cuenta ya tiene una cortesía. Retírala primero para dar otra.');
            }

            [$kind, $value] = $this->normalize($data['kind'] ?? '', $data['value'] ?? null);
            $base = $this->lodgingBase($target);
            $amount = $this->compute($kind, $value, $base);

            if ($amount <= 0) {
                throw new InvalidArgumentException('Esta cuenta no tiene hospedaje que descontar.');
            }

            $courtesy = Courtesy::create([
                'reservation_id' => $target instanceof Reservation ? $target->id : null,
                'stay_id' => $target instanceof Stay ? $target->id : null,
                'guest_id' => $target->guest_id,
                'guest_name' => $target->guest_name ?: $target->guest?->full_name,
                'kind' => $kind,
                'value' => $value,
                'lodging_base' => $base,
                'amount' => $amount,
                'category' => $this->category($data['category'] ?? null),
                'reason' => trim((string) ($data['reason'] ?? '')),
                'status' => Courtesy::STATUS_PENDING,
                'requested_by' => $user->id,
            ]);

            $this->log($target, $user, $courtesy, sprintf(
                $owner ? 'Cortesía dada por %s: %s (%s) — %s' : 'Cortesía solicitada por %s: %s (%s) — %s',
                $user->name,
                $courtesy->kindLabel(),
                $this->money($amount),
                $courtesy->reason,
            ));

            if ($owner) {
                $this->approveLocked($courtesy, $target, $user, null, $confirmOverCap);
            }

            return $courtesy;
        });

        $courtesy->refresh();

        if ($owner) {
            $this->afterTotalChanged($courtesy, $user);
            $this->notifyOtherOwners($courtesy, $user);
        } else {
            $this->notifyOwners($courtesy, $user);
        }

        return $courtesy;
    }

    /**
     * El propietario aprueba; puede cambiar el tipo o el valor que se pidió.
     *
     * @param  array{kind?: string|null, value?: float|int|string|null, note?: string|null}  $changes
     *
     * @throws InvalidArgumentException
     * @throws CourtesyNeedsConfirmation
     */
    public function approve(Courtesy $courtesy, User $owner, array $changes = [], bool $confirmOverCap = false): Courtesy
    {
        $this->assertOwner($owner);

        DB::transaction(function () use ($courtesy, $owner, $changes, $confirmOverCap) {
            $courtesy = Courtesy::query()->whereKey($courtesy->id)->lockForUpdate()->firstOrFail();

            if (! $courtesy->isPending()) {
                throw new InvalidArgumentException('Esta solicitud ya se decidió ('.$courtesy->statusLabel().').');
            }

            $target = $this->lock($this->targetOf($courtesy));

            if (! $this->isLive($target)) {
                $courtesy->update([
                    'status' => Courtesy::STATUS_CANCELED,
                    'decision_note' => 'La cuenta se cerró antes de la respuesta.',
                    'decided_at' => now(),
                ]);

                throw new InvalidArgumentException('Esta cuenta ya se cerró: la solicitud quedó cancelada.');
            }

            if (! empty($changes['kind'])) {
                [$kind, $value] = $this->normalize($changes['kind'], $changes['value'] ?? null);
                $courtesy->forceFill(['kind' => $kind, 'value' => $value]);
            }

            $this->approveLocked($courtesy, $target, $owner, $changes['note'] ?? null, $confirmOverCap);
        });

        $courtesy->refresh();
        $this->afterTotalChanged($courtesy, $owner);

        $this->notifyRequester($courtesy, $owner, sprintf(
            '%s aprobó tu cortesía: %s (%s)',
            $owner->name,
            $courtesy->kindLabel(),
            $this->money((float) $courtesy->amount),
        ));
        $this->notifyOtherOwners($courtesy, $owner);

        return $courtesy;
    }

    public function reject(Courtesy $courtesy, User $owner, ?string $note = null): Courtesy
    {
        $this->assertOwner($owner);

        return $this->closePending($courtesy, $owner, Courtesy::STATUS_REJECTED, $note, function (Courtesy $c) use ($owner, $note) {
            $this->notifyRequester($c, $owner, sprintf(
                '%s rechazó la cortesía%s',
                $owner->name,
                $note ? ': '.$note : '',
            ));
        });
    }

    /** Quien la pidió se arrepiente (o un propietario la limpia). */
    public function cancel(Courtesy $courtesy, User $user): Courtesy
    {
        if ($courtesy->requested_by !== $user->id && ! $this->isOwner($user)) {
            throw new InvalidArgumentException('Solo quien la pidió puede cancelar esta solicitud.');
        }

        return $this->closePending($courtesy, $user, Courtesy::STATUS_CANCELED, 'Cancelada por '.$user->name);
    }

    /** Retirar una cortesía ya dada: la cuenta vuelve a su precio. */
    public function revoke(Courtesy $courtesy, User $owner, ?string $note = null): Courtesy
    {
        $this->assertOwner($owner);

        DB::transaction(function () use ($courtesy, $owner, $note) {
            $courtesy = Courtesy::query()->whereKey($courtesy->id)->lockForUpdate()->firstOrFail();

            if (! $courtesy->isApproved()) {
                throw new InvalidArgumentException('Solo se retira una cortesía aprobada.');
            }

            $target = $this->lock($this->targetOf($courtesy));
            $this->assertLive($target);

            $this->setCourtesyAmount($target, 0.0);

            $courtesy->update([
                'status' => Courtesy::STATUS_REVOKED,
                'decision_note' => $note ?: 'Retirada por '.$owner->name,
            ]);

            $this->log($target, $owner, $courtesy, sprintf(
                'Cortesía retirada por %s: la cuenta vuelve a %s',
                $owner->name,
                $this->money($target instanceof Reservation ? (float) $target->total_amount : (float) $target->amount),
            ));
        });

        return $courtesy->refresh();
    }

    /**
     * La cuenta se cerró (cancelada, no llegó, salida) con una solicitud
     * esperando: ya no hay qué descontar.
     */
    public function cancelPendingFor(Reservation|Stay $target, string $note): void
    {
        try {
            Courtesy::query()
                ->where($target instanceof Reservation ? 'reservation_id' : 'stay_id', $target->id)
                ->pending()
                ->update([
                    'status' => Courtesy::STATUS_CANCELED,
                    'decision_note' => $note,
                    'decided_at' => now(),
                    'updated_at' => now(),
                ]);
        } catch (Throwable $e) {
            // Sin la tabla (hotel sin migrar) la transición sigue.
            report($e);
        }
    }

    /**
     * Quien recalcula la cuenta desde cero (editar la reserva, extender o
     * mover una estancia de mostrador) pregunta aquí cuánto vale ahora la
     * cortesía aprobada: Gratis y porcentaje siguen al hospedaje nuevo; un
     * monto fijo se topa a él. Devuelve lo que hay que restar.
     *
     * @param  array<int, array<string, mixed>>|null  $extraCharges
     */
    public function recalculate(Reservation|Stay $target, float $gross, ?array $extraCharges = null): float
    {
        $courtesy = Courtesy::query()
            ->where($target instanceof Reservation ? 'reservation_id' : 'stay_id', $target->id)
            ->where('status', Courtesy::STATUS_APPROVED)
            ->latest('id')
            ->first();

        if ($courtesy === null) {
            return 0.0;
        }

        $base = $this->lodgingBase($target, $gross, $extraCharges);
        $amount = $this->compute($courtesy->kind, (float) $courtesy->value, $base);

        if (abs($amount - (float) $courtesy->amount) >= 0.01) {
            activity('courtesy')
                ->performedOn($target)
                ->withProperties(['courtesy_id' => $courtesy->id, 'from' => (float) $courtesy->amount, 'to' => $amount])
                ->log(sprintf(
                    'Cortesía recalculada al cambiar la cuenta: %s → %s',
                    $this->money((float) $courtesy->amount),
                    $this->money($amount),
                ));
        }

        $courtesy->update(['lodging_base' => $base, 'amount' => $amount]);

        return $amount;
    }

    /** @return Collection<int, User> */
    public function owners(): Collection
    {
        return User::role(StaffAccess::OWNER)->get();
    }

    /** Dinero de hospedaje ya pagado en esta cuenta. */
    public function paidLodging(Reservation|Stay $target): float
    {
        return $target instanceof Reservation
            ? $target->paidTotal()
            : round((float) $target->payments()->where('kind', Payment::KIND_LODGING)->sum('amount'), 2);
    }

    // ------------------------------------------------------------------

    /**
     * @throws CourtesyNeedsConfirmation
     */
    protected function approveLocked(Courtesy $courtesy, Reservation|Stay $target, User $owner, ?string $note, bool $confirmOverCap): void
    {
        // El cupón se va antes de medir: la cortesía se calcula sobre el
        // bruto y los dos juntos no se acumulan.
        if ($target instanceof Reservation && $target->coupon_code) {
            $code = $target->coupon_code;
            $this->coupons->release($target);
            // El descuento del cupón vuelve al total: el bruto no cambia.
            $target->forceFill([
                'coupon_code' => null,
                'discount_amount' => 0,
                'total_amount' => round((float) $target->total_amount + (float) $target->discount_amount, 2),
            ])->save();

            activity('coupon')
                ->performedOn($target)
                ->causedBy($owner)
                ->withProperties(['code' => $code])
                ->log(sprintf('Cupón %s retirado: la reserva recibió una cortesía', $code));
        }

        $base = $this->lodgingBase($target);
        $amount = $this->compute($courtesy->kind, (float) $courtesy->value, $base);

        if ($amount <= 0) {
            throw new InvalidArgumentException('Esta cuenta no tiene hospedaje que descontar.');
        }

        $cap = $this->monthlyCap();
        $used = $this->monthUsage($courtesy);
        $overCap = $cap !== null && $used + $amount > $cap;

        if ($overCap && ! $confirmOverCap) {
            throw new CourtesyNeedsConfirmation(sprintf(
                'Con esta cortesía el mes llega a %s y el tope es de %s.',
                $this->money($used + $amount),
                $this->money($cap),
            ));
        }

        $this->setCourtesyAmount($target, $amount);

        $courtesy->forceFill([
            'lodging_base' => $base,
            'amount' => $amount,
            'status' => Courtesy::STATUS_APPROVED,
            'decided_by' => $owner->id,
            'decided_at' => now(),
            'decision_note' => $note ?: $courtesy->decision_note,
            'over_cap' => $overCap,
        ])->save();

        if ($courtesy->requested_by !== $owner->id) {
            $this->log($target, $owner, $courtesy, sprintf(
                'Cortesía aprobada por %s: %s (%s)%s',
                $owner->name,
                $courtesy->kindLabel(),
                $this->money($amount),
                $overCap ? ' — pasó el tope del mes' : '',
            ));
        } elseif ($overCap) {
            $this->log($target, $owner, $courtesy, 'La cortesía pasó el tope del mes y se confirmó.');
        }
    }

    /**
     * Escribe la cortesía en la cuenta y rearma su total (bruto − cupón −
     * cortesía), el anticipo, el estado de pago y la estancia en curso.
     */
    protected function setCourtesyAmount(Reservation|Stay $target, float $courtesyAmount): void
    {
        $gross = $this->gross($target);

        if ($target instanceof Stay) {
            $target->forceFill([
                'courtesy_amount' => $courtesyAmount,
                'amount' => round(max(0, $gross - $courtesyAmount), 2),
            ])->save();

            return;
        }

        $before = (float) $target->total_amount;
        $total = round(max(0, $gross - (float) $target->discount_amount - $courtesyAmount), 2);

        $target->forceFill([
            'courtesy_amount' => $courtesyAmount,
            'total_amount' => $total,
            // Igual que el cupón: el anticipo % sale de lo que de verdad se
            // va a cobrar; el monto fijo se topa al total.
            'deposit_amount' => ($target->ratePlan
                ? $this->policy->depositFor($target->ratePlan, $total, $target->starts_at, $target->created_at)
                : null)
                ?? round(min((float) $target->deposit_amount, $total), 2),
            // Nada que cobrar = nada que se venza.
            'payment_due_at' => $total <= 0 ? null : $target->payment_due_at,
        ])->save();

        $target->syncPaymentStatus();

        // Un cobro vivo pedía un monto que ya no corresponde (spec-pagos §6.4).
        $target->paymentRequests()
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->update(['status' => PaymentRequest::STATUS_CANCELED, 'updated_at' => now()]);

        // La estancia copia el total de su reserva (como los daños en
        // StayController::applyCharge).
        // También la ya cerrada: los reportes de habitaciones leen su monto.
        $stay = $target->stay()->first();
        $delta = round($total - $before, 2);

        if ($stay !== null && abs($delta) >= 0.01) {
            $stay->update(['amount' => max(0, round((float) $stay->amount + $delta, 2))]);
        }
    }

    /**
     * Ya aplicada: una reserva apartada que quedó en $0 se confirma (no hay
     * nada que pagar y el barrido de apartados la cancelaría).
     */
    protected function afterTotalChanged(Courtesy $courtesy, User $user): void
    {
        if (! $courtesy->isApproved() || ! $courtesy->reservation_id) {
            return;
        }

        $reservation = Reservation::find($courtesy->reservation_id);

        if ($reservation?->status === ReservationStatus::Pending && (float) $reservation->total_amount <= 0) {
            try {
                app(\App\Actions\Reservations\TransitionReservation::class)->confirm($reservation, $user);
            } catch (Throwable $e) {
                // Sin disponibilidad (el apartado ya había vencido): la
                // cortesía queda y la confirmación se hace a mano.
                report($e);
            }
        }
    }

    protected function closePending(Courtesy $courtesy, User $user, string $status, ?string $note, ?callable $after = null): Courtesy
    {
        DB::transaction(function () use ($courtesy, $user, $status, $note) {
            $courtesy = Courtesy::query()->whereKey($courtesy->id)->lockForUpdate()->firstOrFail();

            if (! $courtesy->isPending()) {
                throw new InvalidArgumentException('Esta solicitud ya se decidió ('.$courtesy->statusLabel().').');
            }

            $courtesy->update([
                'status' => $status,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            $this->log($this->targetOf($courtesy), $user, $courtesy, sprintf(
                'Cortesía %s por %s%s',
                $status === Courtesy::STATUS_REJECTED ? 'rechazada' : 'cancelada',
                $user->name,
                $note && $status === Courtesy::STATUS_REJECTED ? ': '.$note : '',
            ));
        });

        $courtesy->refresh();

        if ($after) {
            $after($courtesy);
        }

        return $courtesy;
    }

    protected function targetOf(Courtesy $courtesy): Reservation|Stay
    {
        return $courtesy->reservation_id
            ? Reservation::query()->findOrFail($courtesy->reservation_id)
            : Stay::query()->findOrFail($courtesy->stay_id);
    }

    protected function lock(Reservation|Stay $target): Reservation|Stay
    {
        return $target::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
    }

    protected function assertLive(Reservation|Stay $target): void
    {
        if ($this->isLive($target)) {
            return;
        }

        throw new InvalidArgumentException($target instanceof Reservation
            ? 'Esta reserva ya está cerrada ('.$target->status->label().'): su precio no se puede cambiar.'
            : 'Esta cuenta ya se cerró: su precio no se puede cambiar.');
    }

    protected function assertOwner(User $user): void
    {
        if (! $this->isOwner($user)) {
            throw new InvalidArgumentException('Solo el propietario decide las cortesías.');
        }
    }

    /**
     * @return array{0: string, 1: float}
     */
    protected function normalize(string $kind, mixed $value): array
    {
        if (! in_array($kind, Courtesy::KINDS, true)) {
            throw new InvalidArgumentException('Elige si es gratis, un porcentaje o un monto.');
        }

        if ($kind === Courtesy::KIND_FULL) {
            return [$kind, 100.0];
        }

        $value = round((float) $value, 2);

        if ($kind === Courtesy::KIND_PERCENT && ($value <= 0 || $value > 100)) {
            throw new InvalidArgumentException('El porcentaje va de 1 a 100.');
        }

        if ($kind === Courtesy::KIND_AMOUNT && $value <= 0) {
            throw new InvalidArgumentException('Escribe cuánto se descuenta.');
        }

        return [$kind, $value];
    }

    protected function category(?string $category): string
    {
        return array_key_exists((string) $category, config('courtesies.categories', [])) ? $category : 'otro';
    }

    protected function log(Reservation|Stay $target, User $user, Courtesy $courtesy, string $text): void
    {
        activity('courtesy')
            ->performedOn($target)
            ->causedBy($user)
            ->withProperties([
                'courtesy_id' => $courtesy->id,
                'kind' => $courtesy->kind,
                'value' => (float) $courtesy->value,
                'amount' => (float) $courtesy->amount,
                'status' => $courtesy->status,
            ])
            ->log($text);
    }

    protected function notifyOwners(Courtesy $courtesy, User $requester): void
    {
        $title = 'Cortesía por aprobar: '.$this->describeTarget($courtesy);
        $body = sprintf(
            '%s pide %s (%s) para %s. Motivo: %s',
            $requester->name,
            mb_strtolower($courtesy->kindLabel()),
            $this->money((float) $courtesy->amount),
            $courtesy->guest_name ?: 'el huésped',
            $courtesy->reason,
        );

        $this->notifyUsers($this->owners()->reject(fn (User $u) => $u->id === $requester->id), $courtesy, $title, $body);
        $this->mailOwners($courtesy, $title, $body);
    }

    protected function notifyOtherOwners(Courtesy $courtesy, User $owner): void
    {
        $title = 'Cortesía dada: '.$this->describeTarget($courtesy);
        $body = sprintf(
            '%s %s %s (%s) a %s. Motivo: %s',
            $owner->name,
            $courtesy->requested_by === $owner->id ? 'dio' : 'aprobó',
            mb_strtolower($courtesy->kindLabel()),
            $this->money((float) $courtesy->amount),
            $courtesy->guest_name ?: 'el huésped',
            $courtesy->reason,
        );

        $this->notifyUsers($this->owners()->reject(fn (User $u) => $u->id === $owner->id), $courtesy, $title, $body);
    }

    protected function notifyRequester(Courtesy $courtesy, User $decider, string $body): void
    {
        if (! $courtesy->requested_by || $courtesy->requested_by === $decider->id) {
            return;
        }

        $requester = User::find($courtesy->requested_by);

        if ($requester !== null) {
            $this->notifyUsers(collect([$requester]), $courtesy, 'Cortesía '.mb_strtolower($courtesy->statusLabel()).': '.$this->describeTarget($courtesy), $body);
        }
    }

    /** @param  Collection<int, User>  $users */
    protected function notifyUsers(Collection $users, Courtesy $courtesy, string $title, string $body): void
    {
        foreach ($users as $user) {
            try {
                $this->notifier->notify(
                    StaffNotification::TYPE_COURTESY,
                    $title,
                    $body,
                    $this->urlFor($courtesy),
                    $courtesy,
                    $user->id,
                );
            } catch (Throwable $e) {
                // Avisar es cortesía: nunca tumba la decisión ya guardada.
                report($e);
            }
        }
    }

    protected function mailOwners(Courtesy $courtesy, string $title, string $body): void
    {
        $emails = $this->owners()->pluck('email')->filter()->unique()->values()->all();

        if ($emails === []) {
            return;
        }

        try {
            SendCourtesyNoticeMail::dispatch($courtesy->id, $emails, $title, $body);
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function urlFor(Courtesy $courtesy): string
    {
        return '/reservas/cortesias?ver='.$courtesy->id;
    }

    protected function describeTarget(Courtesy $courtesy): string
    {
        if ($courtesy->reservation_id) {
            $reservation = Reservation::find($courtesy->reservation_id);

            return $reservation?->displayCode() ?? 'reserva';
        }

        $stay = Stay::with('room:id,number')->find($courtesy->stay_id);

        return 'habitación '.($stay?->room?->number ?? '');
    }

    protected function money(float $amount): string
    {
        return '$'.number_format($amount, 2);
    }
}
