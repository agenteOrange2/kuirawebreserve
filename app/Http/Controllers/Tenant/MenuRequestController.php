<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\MenuRequest;
use App\Models\Product;
use App\Models\Property;
use App\Models\Room;
use App\Models\StaffNotification;
use App\Services\StaffNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Solicitudes del menú digital que llegan de la carta pública /menu
 * (stateless, sin sesión). Los precios se resuelven SIEMPRE del catálogo
 * en el servidor — lo que mande el navegador solo dice qué y cuánto.
 */
class MenuRequestController extends Controller
{
    public function store(Request $request, StaffNotifier $notifier): JsonResponse
    {
        $data = $request->validate([
            'guest_name' => ['required', 'string', 'max:120'],
            'room' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:500'],
            'payment_mode' => ['required', 'string', 'in:room_charge,on_delivery'],
            'payment_method' => ['nullable', 'string', 'in:cash,card'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:20'],
        ], [
            'guest_name.required' => 'Cuéntanos tu nombre para llevarte el pedido.',
            'items.required' => 'Tu pedido está vacío: agrega algo del menú.',
        ]);

        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];
        $billingMode = $settings['menu_billing_mode'] ?? 'hotel';

        // Fuera del horario de cocina no se aceptan pedidos (la carta ya lo
        // avisa; esto cubre pestañas viejas y llamadas directas).
        $schedule = \App\Services\Menu\MenuHours::status($settings);

        if (! $schedule['open']) {
            return response()->json([
                'message' => 'La cocina atiende de '.$schedule['from'].' a '.$schedule['to'].'; te esperamos en ese horario.',
            ], 422);
        }

        // El cargo a la habitación solo existe en modo hotel y con número
        // de habitación; en motel SIEMPRE se paga al recibir.
        if ($data['payment_mode'] === MenuRequest::PAYMENT_ROOM_CHARGE) {
            if ($billingMode !== 'hotel') {
                return response()->json([
                    'message' => 'Aquí el pedido se paga al recibirlo; elige efectivo o tarjeta.',
                ], 422);
            }
            if (trim((string) ($data['room'] ?? '')) === '') {
                return response()->json([
                    'message' => 'Para cargarlo a tu habitación necesitamos el número de habitación.',
                ], 422);
            }
        } elseif (! isset(MenuRequest::DELIVERY_METHODS[$data['payment_method'] ?? ''])) {
            return response()->json([
                'message' => 'Elige cómo vas a pagar al recibir tu pedido.',
            ], 422);
        }

        $products = Product::query()
            ->where('active', true)
            ->where('available_in_menu', true)
            ->whereIn('id', collect($data['items'])->pluck('product_id'))
            ->get()
            ->keyBy('id');

        // Cantidades por producto (dedupe) y solo lo que sigue en carta.
        $items = collect($data['items'])
            ->groupBy('product_id')
            ->map(fn ($rows, $productId) => [
                'product' => $products->get((int) $productId),
                'qty' => min(20, (int) $rows->sum('qty')),
            ])
            ->filter(fn (array $item) => $item['product'] !== null)
            ->map(fn (array $item) => [
                'product_id' => $item['product']->id,
                'name' => $item['product']->name,
                'qty' => $item['qty'],
                'price' => (float) $item['product']->price,
            ])
            ->values();

        if ($items->isEmpty()) {
            return response()->json([
                'message' => 'Esos productos ya no están en el menú; recarga la página e intenta de nuevo.',
            ], 422);
        }

        $roomLabel = trim((string) ($data['room'] ?? '')) ?: null;

        $menuRequest = MenuRequest::create([
            'property_id' => $property->id,
            'room_id' => $roomLabel ? Room::query()->where('number', $roomLabel)->value('id') : null,
            'room_label' => $roomLabel,
            'guest_name' => trim($data['guest_name']),
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'items' => $items->all(),
            'total' => $items->sum(fn (array $item) => $item['qty'] * $item['price']),
            'payment_mode' => $data['payment_mode'],
            'payment_method' => $data['payment_mode'] === MenuRequest::PAYMENT_ON_DELIVERY
                ? $data['payment_method']
                : null,
            'status' => MenuRequest::STATUS_PENDING,
        ]);

        $summary = $items
            ->map(fn (array $item) => "{$item['qty']}x {$item['name']}")
            ->join(', ');

        $notifier->notify(
            StaffNotification::TYPE_MENU,
            'Pedido del menú: '.$menuRequest->guest_name.($roomLabel ? " (hab. {$roomLabel})" : ''),
            $summary.' — $'.number_format((float) $menuRequest->total, 2).' · '.$menuRequest->paymentLabel(),
            '/menu-digital',
            $menuRequest,
        );

        return response()->json(['ok' => true], 201);
    }
}
