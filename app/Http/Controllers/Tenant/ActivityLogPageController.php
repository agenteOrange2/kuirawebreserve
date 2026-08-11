<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\RoomStatus;
use App\Models\Coupon;
use App\Models\Incident;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Bitácora global de acciones (/actividad): TODA la actividad del hotel en
 * una sola línea de tiempo filtrable por usuario, tipo y fecha — quién creó
 * o canceló reservas, registró pagos, hizo check-in/out, movió el semáforo,
 * bloqueó habitaciones o aplicó cupones. Los timelines por objeto (reserva,
 * habitación, incidencia) siguen viviendo en sus páginas; esta vista es la
 * transversal de auditoría para el dueño.
 *
 * Extiende ReservationsPageController solo para reutilizar timelineMessage
 * (mismo criterio que ReservationHistoryPageController).
 */
class ActivityLogPageController extends ReservationsPageController
{
    /** Grupos del filtro "tipo": log_name(s) que abarca cada uno. */
    protected const TYPES = [
        'reservation' => ['label' => 'Reservas y cupones', 'logs' => ['reservation', 'coupon']],
        'stay' => ['label' => 'Estancias (check-in/out)', 'logs' => ['stay']],
        'room' => ['label' => 'Habitaciones y semáforo', 'logs' => ['room']],
        'incident' => ['label' => 'Incidencias', 'logs' => ['incident']],
        'payment' => ['label' => 'Pagos y reembolsos', 'logs' => ['payment']],
    ];

    public function __invoke(Request $request): Response
    {
        $property = Property::firstOrFail();
        $staff = User::query()->orderBy('name')->get(['id', 'name']);

        $userFilter = $request->string('user')->toString();
        $type = $request->string('type')->toString();
        if (! array_key_exists($type, self::TYPES)) {
            $type = '';
        }

        $from = $request->date('from');
        $to = $request->date('to');

        $paginator = Activity::query()
            ->with(['causer', 'subject'])
            ->when($userFilter === 'system', fn ($q) => $q->whereNull('causer_id'))
            ->when(ctype_digit($userFilter) && $userFilter !== '', fn ($q) => $q
                ->where('causer_type', User::class)
                ->where('causer_id', (int) $userFilter))
            ->when($type !== '', fn ($q) => $q->whereIn('log_name', self::TYPES[$type]['logs']))
            ->when($from, fn ($q) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($to, fn ($q) => $q->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->latest()
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        $paginator->through(fn (Activity $a) => $this->serializeActivity($a));

        return Inertia::render('tenant/activity/Index', [
            'property' => $property->only(['id', 'name']),
            'staff' => $staff,
            'filters' => [
                'user' => $userFilter,
                'type' => $type,
                'from' => $from?->format('Y-m-d') ?? '',
                'to' => $to?->format('Y-m-d') ?? '',
            ],
            'types' => collect(self::TYPES)
                ->map(fn (array $t, string $key) => ['value' => $key, 'label' => $t['label']])
                ->values(),
            'activities' => $paginator,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeActivity(Activity $activity): array
    {
        $old = $activity->properties['old'] ?? [];
        $attributes = $activity->properties['attributes'] ?? [];

        return [
            'id' => (string) $activity->id,
            'at' => $activity->created_at?->format('d/m/Y H:i'),
            'by' => $activity->causer?->name ?? 'Sistema',
            'type' => $this->typeKeyFor($activity),
            'type_label' => $this->typeLabelFor($activity),
            'subject' => $this->subjectLabelFor($activity),
            'message' => $this->activityMessage($activity, $old, $attributes),
        ];
    }

    protected function typeKeyFor(Activity $activity): string
    {
        foreach (self::TYPES as $key => $type) {
            if (in_array($activity->log_name, $type['logs'], true)) {
                return $key;
            }
        }

        return 'other';
    }

    protected function typeLabelFor(Activity $activity): string
    {
        return match ($activity->log_name) {
            'reservation' => 'Reserva',
            'coupon' => 'Cupón',
            'stay' => 'Estancia',
            'room' => 'Habitación',
            'incident' => 'Incidencia',
            'payment' => 'Pago',
            default => ucfirst((string) $activity->log_name),
        };
    }

    /** Nombre humano del objeto tocado, aunque ya no exista. */
    protected function subjectLabelFor(Activity $activity): string
    {
        $subject = $activity->subject;

        return match (true) {
            $subject instanceof Reservation => $subject->displayCode().($subject->guest_name ? ' · '.$subject->guest_name : ''),
            $subject instanceof Stay => 'Estancia #'.$subject->id.($subject->guest_name ? ' · '.$subject->guest_name : ''),
            $subject instanceof Room => 'Habitación '.$subject->number,
            $subject instanceof Incident => 'Incidencia #'.$subject->id.' · '.$subject->title,
            $subject instanceof Payment => 'Pago #'.$subject->id,
            $subject instanceof Refund => 'Reembolso #'.$subject->id,
            $subject instanceof Coupon => 'Cupón '.$subject->code,
            default => match ($activity->subject_type) {
                Reservation::class => 'Reserva #'.$activity->subject_id.' (eliminada)',
                Room::class => 'Habitación (eliminada)',
                default => class_basename((string) $activity->subject_type).' #'.$activity->subject_id,
            },
        };
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $attributes
     */
    protected function activityMessage(Activity $activity, array $old, array $attributes): string
    {
        // Mensajes escritos a mano (cupón aplicado, estancia extendida...).
        if (! in_array($activity->description, ['created', 'updated', 'deleted'], true)) {
            return $activity->description;
        }

        return match ($activity->log_name) {
            'reservation' => $this->timelineMessage($activity, $old, $attributes),
            'stay' => $this->stayMessage($activity, $old, $attributes),
            'room' => $this->roomMessage($activity, $old, $attributes),
            'incident' => $this->incidentMessage($activity, $old, $attributes),
            'payment' => $this->paymentMessage($activity, $attributes),
            'coupon' => match ($activity->description) {
                'created' => 'Cupón creado',
                'deleted' => 'Cupón eliminado',
                default => array_key_exists('active', $attributes)
                    ? ($attributes['active'] ? 'Cupón activado' : 'Cupón desactivado')
                    : 'Cupón actualizado',
            },
            default => ucfirst($activity->description),
        };
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $attributes
     */
    protected function stayMessage(Activity $activity, array $old, array $attributes): string
    {
        if ($activity->event === 'created') {
            return 'Check-in registrado (estancia abierta)';
        }

        if (($attributes['check_out_at'] ?? null) && ! ($old['check_out_at'] ?? null)) {
            return 'Check-out registrado';
        }

        if (($old['room_id'] ?? null) !== ($attributes['room_id'] ?? null) && ($attributes['room_id'] ?? null)) {
            return 'Se cambió la habitación de la estancia';
        }

        if (($old['status'] ?? null) !== ($attributes['status'] ?? null) && ($attributes['status'] ?? null)) {
            return 'Estado de la estancia: '.$old['status'].' → '.$attributes['status'];
        }

        return 'Estancia actualizada';
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $attributes
     */
    protected function roomMessage(Activity $activity, array $old, array $attributes): string
    {
        if ($activity->event === 'created') {
            return 'Habitación creada';
        }

        if ($activity->event === 'deleted') {
            return 'Habitación eliminada';
        }

        if (($old['status'] ?? null) !== ($attributes['status'] ?? null) && ($attributes['status'] ?? null)) {
            $fromLabel = RoomStatus::tryFrom((string) ($old['status'] ?? ''))?->label() ?? (string) ($old['status'] ?? '—');
            $toLabel = RoomStatus::tryFrom((string) $attributes['status'])?->label() ?? (string) $attributes['status'];

            return "Semáforo: {$fromLabel} → {$toLabel}";
        }

        return 'Habitación actualizada';
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $attributes
     */
    protected function incidentMessage(Activity $activity, array $old, array $attributes): string
    {
        if ($activity->event === 'created') {
            return 'Incidencia reportada';
        }

        if (($old['status'] ?? null) !== ($attributes['status'] ?? null) && ($attributes['status'] ?? null)) {
            $labels = [
                Incident::STATUS_OPEN => 'Abierta',
                Incident::STATUS_IN_PROGRESS => 'En proceso',
                Incident::STATUS_RESOLVED => 'Resuelta',
            ];

            return 'Incidencia: '.($labels[$old['status'] ?? ''] ?? '—').' → '.($labels[$attributes['status']] ?? $attributes['status']);
        }

        if (($attributes['assigned_to'] ?? null) && ($old['assigned_to'] ?? null) !== $attributes['assigned_to']) {
            return 'Se asignó un responsable a la incidencia';
        }

        return 'Incidencia actualizada';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function paymentMessage(Activity $activity, array $attributes): string
    {
        $amount = number_format((float) ($attributes['amount'] ?? 0), 2);

        if ($activity->subject_type === Refund::class) {
            return "Reembolso registrado: \${$amount}";
        }

        $method = Payment::methodLabel((string) ($attributes['method'] ?? ''));
        $kind = match ($attributes['kind'] ?? null) {
            Payment::KIND_GUARANTEE => ' (fianza en garantía)',
            Payment::KIND_LODGING => ' (hospedaje en folio)',
            Payment::KIND_CONSUMPTION => ' (consumos del folio)',
            default => '',
        };

        return "Pago registrado: \${$amount} · {$method}{$kind}";
    }
}
