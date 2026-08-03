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
        ]);

        $coupon = Coupon::query()
            ->where('code', strtoupper(trim($data['code'])))
            ->first();

        if (! $coupon || ! $coupon->isRedeemable()) {
            return response()->json([
                'message' => 'Ese código no es válido o ya no está disponible.',
            ], 422);
        }

        return response()->json([
            'code' => $coupon->code,
            'kind' => $coupon->kind,
            'label' => $coupon->kindLabel(),
            'discount' => $coupon->discountFor((float) $data['subtotal']),
        ]);
    }
}
