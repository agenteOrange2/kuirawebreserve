<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página del módulo Cupones (/cupones): CRUD de códigos de descuento que
 * el wizard público acepta al reservar. El uso se cuenta al CONFIRMARSE
 * cada reserva (TransitionReservation), no en el hold.
 */
class CouponsPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('tenant/coupons/Index', [
            'coupons' => Coupon::query()
                ->latest('id')
                ->get()
                ->map(fn (Coupon $coupon) => CouponController::serialize($coupon)),
            'canManage' => $request->user()->can('properties.manage'),
        ]);
    }
}
