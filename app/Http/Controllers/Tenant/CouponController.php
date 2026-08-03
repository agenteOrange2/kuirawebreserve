<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD de cupones (módulo cupones). Los códigos se guardan en MAYÚSCULAS
 * (el wizard normaliza igual al aplicar). Las reservas que ya usaron un
 * cupón conservan su descuento congelado aunque el cupón cambie o se
 * elimine después.
 */
class CouponController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Coupon::query()->latest('id')->get()->map(fn (Coupon $coupon) => self::serialize($coupon)),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $coupon = Coupon::create($data);

        return response()->json(self::serialize($coupon), 201);
    }

    public function update(Request $request, Coupon $coupon): JsonResponse
    {
        $coupon->update($this->validated($request, $coupon));

        return response()->json(self::serialize($coupon->fresh()));
    }

    public function destroy(Coupon $coupon): JsonResponse
    {
        $coupon->delete();

        return response()->json(status: 204);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Coupon $coupon = null): array
    {
        $presence = $coupon ? 'sometimes' : 'required';

        // Normalizar ANTES de validar: así el unique atrapa también el
        // mismo código escrito en minúsculas.
        if ($request->has('code')) {
            $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        }

        $data = $request->validate([
            'code' => [
                $presence, 'string', 'max:40',
                Rule::unique('coupons', 'code')->ignore($coupon?->id),
            ],
            'kind' => [$presence, Rule::in([Coupon::KIND_PERCENT, Coupon::KIND_AMOUNT])],
            'value' => [$presence, 'numeric', 'gt:0', 'max:999999'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'active' => ['sometimes', 'boolean'],
        ]);

        // Un porcentaje mayor a 100 no descuenta más, solo confunde.
        $kind = $data['kind'] ?? $coupon?->kind;
        if ($kind === Coupon::KIND_PERCENT && isset($data['value']) && (float) $data['value'] > 100) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'value' => ['Un porcentaje no puede ser mayor a 100.'],
            ]);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function serialize(Coupon $coupon): array
    {
        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'kind' => $coupon->kind,
            'value' => (float) $coupon->value,
            'label' => $coupon->kindLabel(),
            'starts_at' => $coupon->starts_at?->format('Y-m-d'),
            'ends_at' => $coupon->ends_at?->format('Y-m-d'),
            'max_uses' => $coupon->max_uses,
            'used_count' => $coupon->used_count,
            'active' => $coupon->active,
            'redeemable' => $coupon->isRedeemable(),
        ];
    }
}
