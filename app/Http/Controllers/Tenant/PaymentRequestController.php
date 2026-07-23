<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Payments\RegisterGatewayPayment;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\PaymentRequest;
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
        ]);

        try {
            $action->handle($paymentRequest, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $paymentRequest->refresh();
        app(PaymentGuestNotifier::class)->paymentReceived($paymentRequest);

        return response()->json([
            'ok' => true,
            'reservation_status' => $paymentRequest->reservation()->value('status'),
            'requires_attention' => (bool) ($paymentRequest->meta['requires_attention'] ?? false),
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
