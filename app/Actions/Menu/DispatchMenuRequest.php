<?php

namespace App\Actions\Menu;

use App\Actions\Inventory\CreateOrder;
use App\Models\MenuRequest;
use App\Models\Product;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Despachar un pedido del menú digital: marca la solicitud atendida Y
 * genera la venta POS real en el mismo movimiento — cargo a habitación va
 * al folio de la estancia (se cobra en el check-out) y efectivo/tarjeta
 * queda como venta del turno de quien despacha. Reutiliza CreateOrder, que
 * ya valida stock, explota recetas y congela costos.
 *
 * Idempotente: lockForUpdate + solo estados pending/preparing pueden
 * despacharse, así que un doble clic no vende (ni descuenta) dos veces.
 */
class DispatchMenuRequest
{
    public function __construct(protected CreateOrder $createOrder) {}

    /**
     * @param  bool  $withSale  false = solo marcar atendida (hotel sin
     *                          módulo pos, o el staff decide cobrar aparte).
     * @return array{request: MenuRequest, warning: string|null}
     *
     * @throws InvalidArgumentException
     * @throws \App\Exceptions\InsufficientStockException
     */
    public function handle(MenuRequest $menuRequest, User $user, bool $withSale = true): array
    {
        return DB::transaction(function () use ($menuRequest, $user, $withSale) {
            $menuRequest = MenuRequest::query()
                ->lockForUpdate()
                ->findOrFail($menuRequest->id);

            if (! in_array($menuRequest->status, [MenuRequest::STATUS_PENDING, MenuRequest::STATUS_PREPARING], true)) {
                throw new InvalidArgumentException('Este pedido ya fue despachado o cancelado.');
            }

            $warning = null;

            // Sin módulo pos no hay ventas que crear: se despacha "a la
            // antigüita" y el cobro se registra donde el hotel lleve caja.
            if ($withSale && ! (tenant()?->hasModule('pos') ?? false)) {
                $withSale = false;
                $warning = 'Tu plan no tiene el punto de venta: el pedido quedó despachado sin generar venta.';
            }

            $order = null;

            if ($withSale) {
                $order = $this->createSale($menuRequest, $user);
            } elseif ($warning === null) {
                $warning = 'Despachado sin venta: registra el cobro a mano donde corresponda.';
            }

            $menuRequest->update([
                'status' => MenuRequest::STATUS_ATTENDED,
                'attended_by' => $user->id,
                'attended_at' => now(),
                'order_id' => $order?->id,
            ]);

            return ['request' => $menuRequest->fresh(), 'warning' => $warning];
        });
    }

    protected function createSale(MenuRequest $menuRequest, User $user): \App\Models\Order
    {
        // Prevalidar el catálogo: CreateOrder truena con 404 si un producto
        // se desactivó entre el pedido y el despacho — mejor un 422 legible.
        $items = collect($menuRequest->items ?? []);
        $activeIds = Product::query()
            ->whereIn('id', $items->pluck('product_id'))
            ->where('active', true)
            ->pluck('id');

        $missing = $items->reject(fn (array $item) => $activeIds->contains((int) $item['product_id']));

        if ($missing->isNotEmpty()) {
            throw new InvalidArgumentException(
                'Estos productos ya no están activos en el catálogo: '
                .$missing->pluck('name')->join(', ')
                .'. Despacha sin venta y captura el cobro a mano, o reactívalos.',
            );
        }

        // Cargo a habitación exige estancia activa en esa habitación; sin
        // ella no hay folio al cual cargar.
        $stay = null;

        if ($menuRequest->payment_mode === MenuRequest::PAYMENT_ROOM_CHARGE) {
            $stay = $menuRequest->room_id
                ? Stay::query()
                    ->where('room_id', $menuRequest->room_id)
                    ->active()
                    ->latest('check_in_at')
                    ->first()
                : null;

            if ($stay === null) {
                throw new InvalidArgumentException(
                    'La habitación '.($menuRequest->room_label ?: '—').' no tiene una estancia activa para cargarle el pedido. '
                    .'Despacha sin venta y cóbralo al recibir, o corrige la habitación.',
                );
            }
        }

        return $this->createOrder->handle([
            'property_id' => $menuRequest->property_id,
            // En pago al recibir JAMÁS va stay_id: con él la venta quedaría
            // como cargo a habitación y descuadraría corte y folio.
            'stay_id' => $stay?->id,
            'payment_method' => $stay ? null : ($menuRequest->payment_method ?? 'cash'),
            'notes' => 'Menú digital: pedido de '.$menuRequest->guest_name
                .($menuRequest->room_label ? ' (hab. '.$menuRequest->room_label.')' : ''),
            'lines' => $items
                ->map(fn (array $item) => [
                    'product_id' => (int) $item['product_id'],
                    'qty' => (float) $item['qty'],
                ])->all(),
        ], $user);
    }
}
