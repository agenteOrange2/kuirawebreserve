<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Property;
use App\Models\Room;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Carta pública del menú digital (módulo menu-digital): standalone y sin
 * login, patrón del wizard/encuestas. Solo ofrece productos activos que
 * el hotel curó para el menú (available_in_menu); el QR por habitación
 * llega con la habitación ya puesta.
 */
class MenuPageController extends Controller
{
    public function page(): Response
    {
        return $this->render(null);
    }

    public function room(Room $room): Response
    {
        return $this->render($room);
    }

    protected function render(?Room $room): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];
        $appearance = $property->wizardAppearance();

        $products = Product::query()
            ->where('active', true)
            ->where('available_in_menu', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'category' => $product->category ?: 'Otros',
                'price' => (float) $product->price,
                'photo_url' => ($media = $product->getFirstMedia('photo'))
                    ? '/fotos/productos/'.$media->id.'?v=thumb'
                    : null,
            ])->values();

        return Inertia::render('tenant/menu/Show', [
            'appearance' => $appearance,
            'property' => [
                'name' => $property->name,
                'logo_url' => $appearance['logo_url'],
                'phone' => $settings['phone'] ?? null,
                'currency' => $settings['currency'] ?? 'MXN',
            ],
            'products' => $products,
            // Del QR de la habitación: se precarga y no se pregunta.
            'room' => $room?->number,
            // Hotel: puede cargarse a la habitación y pagarse al final.
            // Motel: siempre se paga al recibir el pedido.
            'billingMode' => $settings['menu_billing_mode'] ?? 'hotel',
            'deliveryMethods' => \App\Models\MenuRequest::DELIVERY_METHODS,
            // Horario de cocina: cerrada, la carta se ve pero no se pide.
            'schedule' => \App\Services\Menu\MenuHours::status($settings),
            'etaMinutes' => $settings['menu_eta_minutes'] ?? null,
        ]);
    }
}
