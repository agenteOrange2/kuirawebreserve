<?php

namespace App\Http\Controllers\Tenant;

use App\Exceptions\CourtesyNeedsConfirmation;
use App\Http\Controllers\Controller;
use App\Models\Courtesy;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Stay;
use App\Services\Courtesies\CourtesyService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Cortesías desde la ficha de la reserva y desde el plano (módulo
 * cortesias). Dar o solicitar exige `courtesies.request`; aprobar, rechazar
 * y retirar son del propietario (role:owner en la ruta y otra vez en el
 * servicio). Toda la regla de dinero vive en CourtesyService.
 */
class CourtesyController extends Controller
{
    public function __construct(protected CourtesyService $courtesies) {}

    /**
     * Reglas de la cortesía que viaja dentro de un alta (reserva, walk-in,
     * registro exprés): mismo contrato que dar una cortesía suelta.
     *
     * @return array<string, mixed>
     */
    public static function nestedRules(Request $request): array
    {
        return [
            'courtesy' => ['nullable', 'array'],
            'courtesy.kind' => ['required_with:courtesy', Rule::in(Courtesy::KINDS)],
            'courtesy.value' => [
                'nullable',
                Rule::requiredIf(fn () => $request->filled('courtesy.kind') && $request->input('courtesy.kind') !== Courtesy::KIND_FULL),
                'numeric', 'min:0.01', 'max:999999',
                fn (string $attribute, mixed $value, Closure $fail) => $request->input('courtesy.kind') === Courtesy::KIND_PERCENT && (float) $value > 100
                    ? $fail('El porcentaje de la cortesía va de 1 a 100.')
                    : null,
            ],
            'courtesy.category' => ['required_with:courtesy', Rule::in(array_keys(config('courtesies.categories')))],
            'courtesy.reason' => ['required_with:courtesy', 'string', 'min:4', 'max:255'],
            'courtesy.confirm_over_cap' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public static function nestedMessages(): array
    {
        return [
            'courtesy.reason.required_with' => 'Escribe el motivo de la cortesía.',
            'courtesy.reason.min' => 'Escribe el motivo de la cortesía.',
            'courtesy.value.required' => 'Escribe cuánto se descuenta.',
        ];
    }

    /** La cortesía de un alta exige el módulo y el permiso de pedirla. */
    public static function authorizeNested(Request $request): void
    {
        if ($request->filled('courtesy')) {
            abort_unless(
                tenant()?->hasModule('cortesias') && $request->user()?->can('courtesies.request'),
                403,
                'Tu usuario no puede dar ni solicitar cortesías.',
            );
        }
    }

    /** Vista previa del diálogo: hospedaje, descuento, total y tope. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reservation_id' => ['required_without:stay_id', 'nullable', 'integer', 'exists:reservations,id'],
            'stay_id' => ['required_without:reservation_id', 'nullable', 'integer', 'exists:stays,id'],
            'kind' => ['required', Rule::in(Courtesy::KINDS)],
            'value' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'courtesy_id' => ['nullable', 'integer'],
        ]);

        $target = $this->courtesies->targetFor(! empty($data['reservation_id'])
            ? Reservation::findOrFail($data['reservation_id'])
            : Stay::findOrFail($data['stay_id']));

        $except = ! empty($data['courtesy_id']) ? Courtesy::find($data['courtesy_id']) : null;

        return response()->json([
            ...$this->courtesies->preview($target, $data['kind'], (float) ($data['value'] ?? 0), $except),
            'is_owner' => $this->courtesies->isOwner($request->user()),
        ]);
    }

    public function storeForReservation(Request $request, Reservation $reservation): JsonResponse
    {
        return $this->store($request, $reservation);
    }

    public function storeForStay(Request $request, Stay $stay): JsonResponse
    {
        return $this->store($request, $stay);
    }

    public function approve(Request $request, Courtesy $courtesy): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', Rule::in(Courtesy::KINDS)],
            'value' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'note' => ['nullable', 'string', 'max:255'],
            'confirm_over_cap' => ['sometimes', 'boolean'],
        ]);

        return $this->respond(fn () => $this->courtesies->approve(
            $courtesy,
            $request->user(),
            $data,
            (bool) ($data['confirm_over_cap'] ?? false),
        ));
    }

    public function reject(Request $request, Courtesy $courtesy): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        return $this->respond(fn () => $this->courtesies->reject($courtesy, $request->user(), $data['note'] ?? null));
    }

    public function revoke(Request $request, Courtesy $courtesy): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        return $this->respond(fn () => $this->courtesies->revoke($courtesy, $request->user(), $data['note'] ?? null));
    }

    public function destroy(Request $request, Courtesy $courtesy): JsonResponse
    {
        return $this->respond(fn () => $this->courtesies->cancel($courtesy, $request->user()));
    }

    /** Tope mensual (/ajustes/cortesias): solo el propietario. */
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'courtesy_monthly_cap' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);

        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];
        $cap = $data['courtesy_monthly_cap'] ?? null;
        $settings['courtesy_monthly_cap'] = $cap === null || $cap === '' ? null : round((float) $cap, 2);
        $property->update(['settings' => $settings]);

        activity('courtesy')
            ->performedOn($property)
            ->causedBy($request->user())
            ->withProperties(['cap' => $settings['courtesy_monthly_cap']])
            ->log($settings['courtesy_monthly_cap'] === null
                ? 'Tope mensual de cortesías retirado'
                : 'Tope mensual de cortesías: $'.number_format($settings['courtesy_monthly_cap'], 2));

        return response()->json(['courtesy_monthly_cap' => $settings['courtesy_monthly_cap']]);
    }

    protected function store(Request $request, Reservation|Stay $target): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(Courtesy::KINDS)],
            'value' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'category' => ['required', Rule::in(array_keys(config('courtesies.categories')))],
            'reason' => ['required', 'string', 'min:4', 'max:255'],
            'confirm_over_cap' => ['sometimes', 'boolean'],
        ], [
            'reason.required' => 'Escribe el motivo de la cortesía.',
            'reason.min' => 'Escribe el motivo de la cortesía.',
            'category.required' => 'Elige de qué tipo es la cortesía.',
        ]);

        return $this->respond(fn () => $this->courtesies->request(
            $target,
            $data,
            $request->user(),
            (bool) ($data['confirm_over_cap'] ?? false),
        ), 201);
    }

    /** @param  Closure(): Courtesy  $action */
    protected function respond(Closure $action, int $status = 200): JsonResponse
    {
        try {
            $courtesy = $action();
        } catch (CourtesyNeedsConfirmation $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'needs_confirmation' => 'courtesy_cap',
            ], 409);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $courtesy->load(['requester:id,name', 'decider:id,name']);
        $target = $courtesy->reservation_id ? Reservation::find($courtesy->reservation_id) : Stay::find($courtesy->stay_id);

        return response()->json([
            'courtesy' => $courtesy->toPayload(),
            'total' => $target instanceof Reservation ? (float) $target->total_amount : (float) $target?->amount,
            'refund_due' => $target ? round(max(0, $this->courtesies->paidLodging($target) - ($target instanceof Reservation ? (float) $target->total_amount : (float) $target->amount)), 2) : 0,
        ], $status);
    }
}
