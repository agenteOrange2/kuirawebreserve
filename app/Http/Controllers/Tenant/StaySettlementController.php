<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Reservations\SettleStay;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Stay;
use App\Services\ReservationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cuentas por cerrar (/reservas/cuentas).
 *
 * La salida manual exige cobrar el saldo o forzarla a propósito. La
 * automática (stays:auto-checkout) no puede preguntarle a nadie, así que
 * cerraba en silencio: la estancia quedaba completada con su dinero sin
 * registrar, los cargos se rechazaban por "estancia no activa" y no había
 * pantalla para cobrar tarde. El saldo simplemente desaparecía del panel.
 *
 * Aquí viven esas cuentas hasta que alguien las resuelve: cobrando,
 * agregando lo que faltó capturar, corrigiendo la hora real de salida o
 * cerrándolas con un motivo escrito. Cerrar con motivo NO finge un cobro:
 * deja de aparecer, pero el dinero nunca entra al corte.
 */
class StaySettlementController extends Controller
{
    /** Cuántas caben antes de paginar: es una bandeja de trabajo, no un reporte. */
    protected const PER_PAGE = 20;

    public function index(Request $request): Response
    {
        $property = Property::firstOrFail();
        $search = trim($request->string('q')->toString());
        $showClosed = $request->boolean('cerradas');

        $query = $showClosed
            ? Stay::query()
                ->where('status', Stay::STATUS_COMPLETED)
                ->whereNotNull('settlement_closed_at')
            : Stay::query()->pendingSettlement();

        $paginator = $query
            ->with(['room:id,number', 'reservation:id,code,created_at', 'guest:id,first_name,last_name,phone'])
            ->when($search !== '', fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('stays.guest_name', 'like', "%{$search}%")
                    ->orWhereHas('room', fn ($r) => $r->where('number', 'like', "%{$search}%"));
            }))
            // Primero la más vieja: es la que más se va a tardar en cobrar.
            ->orderBy('stays.check_out_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $paginator->through(fn (Stay $stay) => $this->serialize($stay));

        return Inertia::render('tenant/reservations/Settlements', [
            'property' => $property->only(['id', 'name']),
            'stays' => $paginator,
            'filters' => ['q' => $search, 'cerradas' => $showClosed],
            // El total no depende del filtro: es el trabajo que queda.
            'pendingCount' => Stay::query()->pendingSettlement()->count(),
            'canManage' => $request->user()->can('reservations.manage'),
            'counterMethods' => app(ReservationPolicy::class)->counterMethods(),
        ]);
    }

    /**
     * Cobra lo que quedó pendiente. Entra al corte de QUIEN COBRA HOY, no al
     * del día de la estancia: el dinero se recibe ahora y ese es el turno que
     * tiene que cuadrar.
     */
    public function pay(Request $request, Stay $stay, SettleStay $settle): JsonResponse
    {
        if ($error = $this->assertOpen($stay)) {
            return $error;
        }

        $data = $request->validate([
            'method' => ['required', Rule::in(app(ReservationPolicy::class)->counterMethods())],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $folio = $settle->handle($stay, [
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
        ], $request->user());

        return response()->json([
            'pending' => $folio['grand_pending'],
            'message' => $folio['grand_pending'] > 0
                ? 'Se registró el cobro; la cuenta todavía tiene saldo.'
                : 'Cuenta liquidada.',
        ]);
    }

    /** Lo que faltó capturar: consumos, daños, cargos extra. */
    public function charge(Request $request, Stay $stay): JsonResponse
    {
        if ($error = $this->assertOpen($stay)) {
            return $error;
        }

        $data = $request->validate([
            'concept' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
        ]);

        $line = [
            'concept' => trim($data['concept']),
            'amount' => round((float) $data['amount'], 2),
            'kind' => 'late',
        ];

        // El monto de la estancia sube, pero con reserva el hospedaje lo manda
        // ella: ahí el cargo va al total de la reserva o el saldo no cambiaría.
        if ($stay->reservation) {
            $stay->reservation->update([
                'total_amount' => round((float) $stay->reservation->total_amount + $line['amount'], 2),
                'extra_charges' => [...($stay->reservation->extra_charges ?? []), $line],
            ]);
            $stay->reservation->syncPaymentStatus();
        }

        $stay->extra_charges = [...($stay->extra_charges ?? []), $line];
        $stay->amount = round((float) $stay->amount + $line['amount'], 2);
        $stay->save();

        return response()->json(['pending' => $stay->fresh()->folio()['grand_pending']]);
    }

    /**
     * La hora real de salida. El reloj la pone a los 15 minutos de la salida
     * prevista, y si el huésped se fue a otra hora el registro miente.
     */
    public function checkedOutAt(Request $request, Stay $stay): JsonResponse
    {
        if ($error = $this->assertOpen($stay)) {
            return $error;
        }

        $data = $request->validate([
            'check_out_at' => [
                'required',
                'date',
                'after:'.$stay->check_in_at->toDateTimeString(),
                'before_or_equal:'.now()->toDateTimeString(),
            ],
        ], [
            'check_out_at.after' => 'La salida no puede ser anterior a la llegada.',
            'check_out_at.before_or_equal' => 'La salida no puede estar en el futuro.',
        ]);

        $stay->update(['check_out_at' => $data['check_out_at']]);

        return response()->json(['check_out_at' => $stay->check_out_at->format('d/m/Y H:i')]);
    }

    /**
     * Resolver sin cobrar (cortesía, incobrable, error de captura). El motivo
     * es obligatorio: una cuenta que desaparece sin explicación es justo lo
     * que nadie puede auditar tres meses después.
     */
    public function close(Request $request, Stay $stay): JsonResponse
    {
        if ($error = $this->assertOpen($stay)) {
            return $error;
        }

        $data = $request->validate([
            'note' => ['required', 'string', 'min:4', 'max:255'],
        ], [
            'note.required' => 'Escribe por qué esta cuenta se cierra sin cobrarse.',
        ]);

        $stay->update([
            'settlement_closed_at' => now(),
            'settlement_note' => trim($data['note']),
        ]);

        return response()->json(['closed' => true]);
    }

    /** Reabrir: alguien la cerró con motivo y resultó que sí se va a cobrar. */
    public function reopen(Stay $stay): JsonResponse
    {
        $stay->update(['settlement_closed_at' => null, 'settlement_note' => null]);

        return response()->json(['closed' => false]);
    }

    /** Solo se toca la cuenta de una estancia cerrada y sin resolver. */
    protected function assertOpen(Stay $stay): ?JsonResponse
    {
        if ($stay->status !== Stay::STATUS_COMPLETED) {
            return response()->json([
                'message' => 'Esa estancia sigue activa: su cuenta se trabaja desde el plano o al registrar la salida.',
            ], 422);
        }

        if ($stay->settlement_closed_at !== null) {
            return response()->json([
                'message' => 'Esa cuenta ya se cerró con un motivo; reábrela si vas a cobrarla.',
            ], 422);
        }

        return null;
    }

    /** @return array<string, mixed> */
    protected function serialize(Stay $stay): array
    {
        return [
            'id' => $stay->id,
            'room' => $stay->room?->number,
            'guest_name' => $stay->guest?->full_name ?? $stay->guest_name ?? 'Anónimo',
            'guest_phone' => $stay->guest?->phone,
            'reservation_id' => $stay->reservation_id,
            'reservation_code' => $stay->reservation?->displayCode(),
            'check_in_at' => $stay->check_in_at->format('d/m/Y H:i'),
            'check_out_at' => $stay->check_out_at?->format('d/m/Y H:i'),
            'check_out_at_input' => $stay->check_out_at?->format('Y-m-d\TH:i'),
            'planned_end_at' => $stay->planned_end_at->format('d/m/Y H:i'),
            'amount' => (float) $stay->amount,
            'pending' => $stay->pendingSettlementAmount(),
            // La cerró el reloj: nadie estuvo en el mostrador para cobrar, y
            // eso cambia a quién se le pregunta qué pasó esa noche.
            'auto_closed' => $stay->auto_closed_at !== null,
            'settlement_closed_at' => $stay->settlement_closed_at?->format('d/m/Y H:i'),
            'settlement_note' => $stay->settlement_note,
            'extra_charges' => $stay->extra_charges ?? [],
        ];
    }
}
