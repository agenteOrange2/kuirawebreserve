<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Property;
use Illuminate\Contracts\View\View;

/**
 * Ticket imprimible de una venta POS: HTML angosto (80 mm) que se manda a
 * la impresora térmica directo desde el navegador. No es un PDF a propósito
 * — el mostrador quiere el diálogo de impresión, no un archivo que guardar.
 */
class PosTicketController extends Controller
{
    public function __invoke(Order $order): View
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        $phone = collect($settings['phones'] ?? [])
            ->map(fn ($entry) => is_array($entry)
                ? trim('+'.($entry['code'] ?? '').' '.($entry['number'] ?? ''))
                : (string) $entry)
            ->filter()
            ->first() ?? ($settings['phone'] ?? null);

        return view('pos.ticket', [
            'order' => $order->load(['lines.product:id,name', 'stay.room:id,number', 'createdBy:id,name']),
            'property' => $property,
            'phone' => $phone,
        ]);
    }
}
