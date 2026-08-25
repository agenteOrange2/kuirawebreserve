<?php

namespace App\Actions\Inventory;

use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Venta POS: valida stock, descuenta (explotando la receta en compuestos),
 * congela el COGS por línea y opcionalmente carga a una estancia activa.
 */
class CreateOrder
{
    public function __construct(protected RecordStockMovement $recordMovement) {}

    /**
     * @param  array{stay_id?: int|null, charge_to_room?: bool|null, notes?: string|null, discount?: float|null, tip?: float|null, lines: array<int, array{product_id: int, qty: float}>}  $data
     *
     * @throws InsufficientStockException
     */
    public function handle(array $data, ?User $user = null): Order
    {
        return DB::transaction(function () use ($data, $user) {
            $stay = null;
            if (! empty($data['stay_id'])) {
                $stay = Stay::whereKey($data['stay_id'])->active()->first();
                if (! $stay) {
                    throw new InvalidArgumentException('La estancia ya no está activa; no se puede cargar a habitación.');
                }
            }

            // Lo que se carga a la habitación se cobra en el check-out, no
            // ahora; para el corte de caja no es efectivo en mano todavía.
            //
            // `charge_to_room` permite lo contrario: cobrar en el momento un
            // consumo de un cuarto en uso (la caseta del motel entrega y
            // cobra ahí mismo). La venta CONSERVA su stay_id —así sigue
            // contando en el historial del cuarto y del vehículo— pero se
            // guarda con el método real, así que el corte la cuenta y el
            // folio deja de verla (ese filtro es `payment_method = 'room'`
            // sin liquidar). Ojo: NO se sella `settled_at`; ese sello
            // significa "se liquidó en el check-out" y VoidOrder lo usa para
            // prohibir cancelar — una venta de mostrador cobrada al momento
            // tiene que poder cancelarse como cualquier otra.
            //
            // El default es `true` a propósito: sin la bandera, todo llamador
            // existente se comporta exactamente igual que antes. Quién la
            // manda lo decide la UI según el modo de operación.
            $chargeToRoom = $stay !== null && ($data['charge_to_room'] ?? true);
            $method = $chargeToRoom ? 'room' : ($data['payment_method'] ?? 'cash');

            $order = Order::create([
                'property_id' => $data['property_id'],
                'stay_id' => $stay?->id,
                // El turno abierto del encargado, para que su corte no
                // dependa de adivinar el periodo por fechas.
                'shift_id' => $this->openShiftIdFor($user, (int) $data['property_id']),
                'status' => Order::STATUS_COMPLETED,
                'payment_method' => $method,
                'payment_reference' => $data['payment_reference'] ?? null,
                'subtotal' => 0,
                'discount' => 0,
                'discount_reason' => $data['discount_reason'] ?? null,
                'tip' => 0,
                'total' => 0,
                'total_cost' => 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user?->id,
            ]);

            $total = 0.0;
            $totalCost = 0.0;

            foreach ($data['lines'] as $line) {
                $product = Product::with('recipeItems.ingredient')
                    ->whereKey($line['product_id'])
                    ->where('active', true)
                    ->firstOrFail();

                $qty = (float) $line['qty'];
                $unitCost = $product->currentUnitCost();

                if ($product->isComposite()) {
                    // Explota la receta: descuenta cada ingrediente.
                    foreach ($product->recipeItems as $item) {
                        $this->recordMovement->handle(
                            $item->ingredient,
                            'sale',
                            -($qty * (float) $item->quantity),
                            null,
                            $order,
                            "Venta {$product->name}",
                            $user,
                        );
                    }
                } elseif ($product->track_stock) {
                    $this->recordMovement->handle($product, 'sale', -$qty, null, $order, null, $user);
                }

                $lineTotal = round($qty * (float) $product->price, 2);

                $order->lines()->create([
                    'product_id' => $product->id,
                    'qty' => $qty,
                    'unit_price' => $product->price,
                    'unit_cost' => $unitCost,
                    'total' => $lineTotal,
                ]);

                $total += $lineTotal;
                $totalCost += round($qty * $unitCost, 2);
            }

            // El descuento nunca deja la venta en negativo; la propina se
            // suma aparte y no cuenta para el COGS.
            $discount = round(min(max((float) ($data['discount'] ?? 0), 0), $total), 2);
            $tip = round(max((float) ($data['tip'] ?? 0), 0), 2);

            $order->update([
                'subtotal' => $total,
                'discount' => $discount,
                'tip' => $tip,
                'total' => round($total - $discount + $tip, 2),
                'total_cost' => $totalCost,
            ]);

            return $order;
        });
    }

    /**
     * Turno abierto del encargado en la propiedad, si lo hay. No se exige:
     * un hotel puede vender sin llevar turnos y la venta no debe frenarse.
     */
    protected function openShiftIdFor(?User $user, int $propertyId): ?int
    {
        if ($user === null) {
            return null;
        }

        return Shift::query()
            ->open()
            ->where('user_id', $user->id)
            ->where('property_id', $propertyId)
            ->latest('started_at')
            ->value('id');
    }
}
