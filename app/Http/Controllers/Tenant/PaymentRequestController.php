<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Payments\RegisterGatewayPayment;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Services\Payments\PaymentGuestNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Cola de verificación de pagos (spec-pagos §7.4): el staff aprueba o
 * rechaza transferencias reportadas. Aprobar registra el pago y, si procede,
 * confirma la reserva; en ambos casos se avisa al huésped por su canal.
 */
class PaymentRequestController extends Controller
{
    /** Solicitudes de transferencia pendientes de verificar. */
    public function index(): JsonResponse
    {
        return response()->json(['requests' => $this->queue()]);
    }

    /**
     * Detalle completo de una solicitud: todo lo que el staff necesita para
     * verificar sin salir de /pagos — el sujeto (reserva/experiencia/grupo),
     * cuánto lleva pagado, las cuentas donde pudo caer el depósito y el
     * comprobante si ya se subió.
     */
    public function show(PaymentRequest $paymentRequest): JsonResponse
    {
        $paymentRequest->load([
            'reservation.guest:id,first_name,last_name,phone,email',
            'reservation.roomType:id,name',
            'experienceBooking.guest:id,first_name,last_name,phone,email',
            'experienceBooking.session.experience:id,name',
            'group.guest:id,first_name,last_name,phone,email',
            'group.reservations:id,reservation_group_id,status',
            'requestedBy:id,name',
        ]);

        $guest = $paymentRequest->reservation?->guest
            ?? $paymentRequest->experienceBooking?->guest
            ?? $paymentRequest->group?->guest;

        $details = [];

        if ($r = $paymentRequest->reservation) {
            $details = [
                ['label' => 'Habitación', 'value' => $r->roomType?->name ?? 'Por asignar'],
                ['label' => 'Llegada', 'value' => $r->starts_at->format('d/m/Y H:i')],
                ['label' => 'Salida', 'value' => $r->ends_at->format('d/m/Y H:i')],
                ['label' => 'Total de la reserva', 'value' => '$'.number_format((float) $r->total_amount, 2)],
                ['label' => 'Pagado hasta ahora', 'value' => '$'.number_format($r->paidTotal(), 2)],
                ['label' => 'Saldo pendiente', 'value' => '$'.number_format($r->pendingBalance(), 2)],
                ['label' => 'Estado', 'value' => $r->status->label()],
            ];
        } elseif ($booking = $paymentRequest->experienceBooking) {
            $details = array_values(array_filter([
                ['label' => 'Experiencia', 'value' => (string) $booking->session?->experience?->name],
                $booking->session?->starts_at ? ['label' => 'Fecha', 'value' => $booking->session->starts_at->format('d/m/Y H:i')] : null,
                ['label' => 'Personas', 'value' => (string) $booking->people],
            ]));
        } elseif ($group = $paymentRequest->group) {
            $details = [
                ['label' => 'Habitaciones del grupo', 'value' => (string) $group->reservations->count()],
            ];
        }

        // Las cuentas activas del hotel: contra cuál comparar el depósito.
        $accounts = collect(Property::firstOrFail()->settings['bank_accounts'] ?? [])
            ->filter(fn (array $a) => ! empty($a['active']))
            ->map(fn (array $a) => [
                'bank' => $a['bank'] ?? '',
                'holder' => $a['holder'] ?? '',
                'clabe' => $a['clabe'] ?? '',
            ])
            ->values();

        return response()->json([
            'id' => $paymentRequest->id,
            'status' => $paymentRequest->status,
            'status_label' => $paymentRequest->statusLabel(),
            'concept' => $paymentRequest->conceptLabel(),
            'amount_label' => $paymentRequest->amountLabel(),
            'method' => $paymentRequest->method,
            'provider' => $paymentRequest->provider,
            'requested_by' => $paymentRequest->requestedBy?->name ?? 'Asistente IA',
            'requested_at' => $paymentRequest->created_at->format('d/m/Y H:i'),
            'expires_at' => $paymentRequest->expires_at?->format('d/m/Y H:i'),
            'subject_code' => $paymentRequest->subjectCode(),
            'guest' => [
                'name' => $guest?->full_name ?? $paymentRequest->reservation?->guest_name ?? 'Huésped',
                'phone' => $guest?->phone,
                'email' => $guest?->email,
            ],
            'details' => $details,
            'bank_accounts' => $accounts,
            'receipt' => $paymentRequest->receiptPayload(),
            'conversation_id' => $paymentRequest->reservation_id ? Conversation::query()
                ->where('reservation_id', $paymentRequest->reservation_id)->latest('id')->value('id') : null,
        ]);
    }

    /** Comprobante subido al aprobar (privado: solo staff con permiso). */
    public function receipt(PaymentRequest $paymentRequest): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $media = $paymentRequest->getFirstMedia('receipt');

        abort_unless($media !== null, 404);

        return response()->file($media->getPath());
    }

    /**
     * Cancela un cobro vivo desde el centro de pagos — aplica a cualquier
     * sujeto (reserva, grupo o experiencia); el link deja de aceptar pagos.
     */
    public function cancel(PaymentRequest $paymentRequest): JsonResponse
    {
        if ($paymentRequest->status === PaymentRequest::STATUS_PENDING) {
            $paymentRequest->update(['status' => PaymentRequest::STATUS_CANCELED]);
        }

        return response()->json(['status' => $paymentRequest->status]);
    }

    public function approve(Request $request, PaymentRequest $paymentRequest, RegisterGatewayPayment $action): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
            // Foto o PDF del comprobante que mandó el huésped: queda
            // adjunto a la solicitud como evidencia de la verificación.
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        try {
            $action->handle($paymentRequest, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($request->hasFile('receipt')) {
            $paymentRequest->addMedia($request->file('receipt'))->toMediaCollection('receipt');
        }

        $paymentRequest->refresh();
        app(PaymentGuestNotifier::class)->paymentReceived($paymentRequest);

        return response()->json([
            'ok' => true,
            'reservation_status' => $paymentRequest->reservation()->value('status'),
            'requires_attention' => (bool) ($paymentRequest->meta['requires_attention'] ?? false),
        ]);
    }

    /**
     * Reemite un cobro rechazado/vencido/cancelado: la solicitud vieja
     * queda como historial y nace una nueva PENDIENTE (vía
     * IssuePaymentRequest, con montos y vigencia recalculados). Si el
     * huésped ya mandó el comprobante bueno por el chat, se rescata y se
     * adjunta a la nueva — el ciclo rechazo → corrección cierra sin
     * descargar nada a mano.
     */
    public function reissue(PaymentRequest $paymentRequest, \App\Actions\Payments\IssuePaymentRequest $issue): JsonResponse
    {
        $reissuable = [PaymentRequest::STATUS_REJECTED, PaymentRequest::STATUS_EXPIRED, PaymentRequest::STATUS_CANCELED];

        if (! in_array($paymentRequest->status, $reissuable, true)) {
            return response()->json(['message' => 'Solo se reemiten cobros rechazados, vencidos o cancelados.'], 422);
        }

        if (! $paymentRequest->reservation_id) {
            return response()->json(['message' => 'Este cobro no pertenece a una reserva de habitación.'], 422);
        }

        try {
            $fresh = $issue->handle($paymentRequest->reservation()->firstOrFail());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $rescued = app(\App\Services\Channels\InboundMediaService::class)->rescueLatestAttachment($fresh);

        return response()->json([
            'ok' => true,
            'request_id' => $fresh->id,
            'rescued_receipt' => $rescued,
        ]);
    }

    public function reject(Request $request, PaymentRequest $paymentRequest): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:300'],
        ]);

        if ($paymentRequest->status !== PaymentRequest::STATUS_PENDING) {
            return response()->json(['message' => 'La solicitud ya no está pendiente.'], 422);
        }

        $paymentRequest->update([
            'status' => PaymentRequest::STATUS_REJECTED,
            'meta' => array_merge($paymentRequest->meta ?? [], [
                'rejected_reason' => $data['reason'],
                'rejected_by' => $request->user()?->id,
            ]),
        ]);

        app(PaymentGuestNotifier::class)->paymentRejected($paymentRequest, $data['reason']);

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function queue(): array
    {
        return PaymentRequest::query()
            ->with(['reservation:id,code,guest_name,status,created_at', 'experienceBooking:id,guest_name,code,created_at', 'requestedBy:id,name'])
            ->where('method', PaymentRequest::METHOD_TRANSFER)
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->orderBy('created_at')
            ->get()
            ->map(fn (PaymentRequest $r) => [
                'id' => $r->id,
                'reservation_id' => $r->reservation_id,
                'reservation_code' => $r->subjectCode(),
                'guest_name' => $r->reservation?->guest_name ?? $r->experienceBooking?->guest_name ?? 'Huésped',
                'concept' => $r->conceptLabel(),
                'amount_label' => $r->amountLabel(),
                'requested_at' => $r->created_at->diffForHumans(short: true),
                'expires_at' => $r->expires_at?->diffForHumans(short: true),
                'requested_by' => $r->requestedBy?->name ?? 'Asistente IA',
                'conversation_id' => $r->reservation_id ? Conversation::query()
                    ->where('reservation_id', $r->reservation_id)->latest('id')->value('id') : null,
            ])
            ->values()
            ->all();
    }
}
