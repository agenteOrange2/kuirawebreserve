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
            // Condiciones (documento base): estancia larga, tipo de
            // habitación, cliente frecuente y cumpleaños.
            'min_nights' => ['nullable', 'integer', 'min:1', 'max:365'],
            'min_visits' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'room_type_id' => ['nullable', 'integer', 'exists:room_types,id'],
            'birthday' => ['sometimes', 'boolean'],
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
        $conditions = collect([
            $coupon->min_nights !== null ? "{$coupon->min_nights}+ noches" : null,
            $coupon->room_type_id !== null ? ($coupon->roomType?->name ?? 'Tipo específico') : null,
            $coupon->min_visits !== null ? "Frecuente ({$coupon->min_visits}+ visitas)" : null,
            $coupon->birthday ? 'Cumpleaños' : null,
        ])->filter()->values();

        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'kind' => $coupon->kind,
            'value' => (float) $coupon->value,
            'label' => $coupon->kindLabel(),
            'min_nights' => $coupon->min_nights,
            'min_visits' => $coupon->min_visits,
            'room_type_id' => $coupon->room_type_id,
            'birthday' => (bool) $coupon->birthday,
            'conditions' => $conditions,
            'starts_at' => $coupon->starts_at?->format('Y-m-d'),
            'ends_at' => $coupon->ends_at?->format('Y-m-d'),
            'max_uses' => $coupon->max_uses,
            'used_count' => $coupon->used_count,
            'active' => $coupon->active,
            'redeemable' => $coupon->isRedeemable(),
        ];
    }
}
