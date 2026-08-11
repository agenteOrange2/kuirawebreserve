<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Validación pública de cupones para el wizard (módulo cupones, ruta
 * detrás de module:cupones + throttle). SOLO es vitrina: devuelve el
 * descuento calculado para que el huésped lo vea antes de apartar — la
 * verdad se revalida y congela en CreateReservation al crear el hold.
 */
class BookingCouponController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            // Subtotal mostrado en pantalla, solo para previsualizar el
            // monto; el hold recalcula todo server-side.
            'subtotal' => ['required', 'numeric', 'min:0'],
            // Contexto opcional para validar condiciones (noches mínimas,
            // tipo de habitación, frecuente, cumpleaños) ANTES del hold —
            // el hold igual revalida todo.
            'room_type_id' => ['nullable', 'integer'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
        ]);

        $coupon = Coupon::query()
            ->where('code', strtoupper(trim($data['code'])))
            ->first();

        if (! $coupon || ! $coupon->isRedeemable()) {
            return response()->json([
                'message' => 'Ese código no es válido o ya no está disponible.',
            ], 422);
        }

        $start = isset($data['starts_at']) && $data['starts_at'] ? \Illuminate\Support\Carbon::parse($data['starts_at']) : null;
        $end = isset($data['ends_at']) && $data['ends_at'] ? \Illuminate\Support\Carbon::parse($data['ends_at']) : null;
        $nights = $start !== null && $end !== null
            ? max(1, (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()))
            : null;

        $guest = filled($data['guest_phone'] ?? null)
            ? \App\Models\Guest::query()->where('phone', trim($data['guest_phone']))->first()
            : null;

        $reason = $coupon->rejectionReason($guest, $start, $nights, $request->integer('room_type_id') ?: null);

        if ($reason !== null) {
            return response()->json(['message' => $reason], 422);
        }

        return response()->json([
            'code' => $coupon->code,
            'kind' => $coupon->kind,
            'label' => $coupon->kindLabel(),
            'discount' => $coupon->discountFor((float) $data['subtotal']),
        ]);
    }
}
