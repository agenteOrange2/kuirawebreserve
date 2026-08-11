<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Menu\DispatchMenuRequest;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Models\MenuRequest;
use App\Models\Product;
use App\Models\Property;
use App\Models\Room;
use App\Services\Menu\MenuHours;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Panel del menú digital (módulo menu-digital): administración (carta,
 * liga/QR, modo de cobro, horario) y operación (solicitudes y vista de
 * cocina). Despachar genera la venta POS real vía DispatchMenuRequest.
 */
class MenuDigitalPageController extends Controller
{
    public function index(): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        $products = Product::query()
            ->where('active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'category' => $product->category ?: 'Otros',
                'price' => (float) $product->price,
                'available_in_menu' => $product->available_in_menu,
                'photo_url' => ($media = $product->getFirstMedia('photo'))
                    ? '/fotos/productos/'.$media->id.'?v=thumb'
                    : null,
            ])->values();

        $requests = MenuRequest::query()
            ->with(['preparingBy:id,name', 'attendedBy:id,name', 'order:id,total,payment_method'])
            ->latest()
            ->take(80)
            ->get()
            ->map(fn (MenuRequest $request) => $this->requestPayload($request))
            ->values();

        return Inertia::render('tenant/menu/Admin', [
            'products' => $products,
            'requests' => $requests,
            'stats' => $this->stats(),
            'topProducts' => $this->topProducts(),
            'billingMode' => $settings['menu_billing_mode'] ?? 'hotel',
            'hours' => [
                'from' => $settings['menu_hours_from'] ?? null,
                'to' => $settings['menu_hours_to'] ?? null,
            ],
            'etaMinutes' => $settings['menu_eta_minutes'] ?? null,
            'menuUrl' => route('tenant.menu'),
            'qrRooms' => Room::query()->orderBy('number')->get(['id', 'number'])
                ->map(fn (Room $room) => [
                    'id' => $room->id,
                    'label' => $room->number,
                    'url' => route('tenant.menu.room', $room->id),
                ])->values(),
        ]);
    }

    /** Tablero de cocina: preparar y despachar, nada de administración. */
    public function kitchen(): Response
    {
        return Inertia::render('tenant/menu/Kitchen', [
            'pending' => MenuRequest::query()
                ->where('status', MenuRequest::STATUS_PENDING)
                ->oldest()
                ->get()
                ->map(fn (MenuRequest $request) => $this->requestPayload($request))
                ->values(),
            'preparing' => MenuRequest::query()
                ->with('preparingBy:id,name')
                ->where('status', MenuRequest::STATUS_PREPARING)
                ->oldest('preparing_at')
                ->get()
                ->map(fn (MenuRequest $request) => $this->requestPayload($request))
                ->values(),
            'dispatched' => MenuRequest::query()
                ->with(['attendedBy:id,name', 'order:id,total,payment_method'])
                ->where('status', MenuRequest::STATUS_ATTENDED)
                ->whereDate('attended_at', now()->toDateString())
                ->latest('attended_at')
                ->take(15)
                ->get()
                ->map(fn (MenuRequest $request) => $this->requestPayload($request))
                ->values(),
            'stats' => $this->stats(),
        ]);
    }

    /**
     * Comanda imprimible de cocina (80 mm, patrón del ticket POS): la
     * habitación y los items en grande, sin precios de por medio.
     */
    public function comanda(MenuRequest $menuRequest): View
    {
        return view('menu.comanda', [
            'request' => $menuRequest->load('preparingBy:id,name'),
            'property' => Property::firstOrFail(),
        ]);
    }

    /**
     * Ajustes del menú: modo de cobro (hotel/motel), horario de cocina y
     * tiempo estimado de entrega. Solo el dueño.
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'billing_mode' => ['sometimes', Rule::in(['hotel', 'motel'])],
            'hours_from' => ['sometimes', 'nullable', 'date_format:H:i'],
            'hours_to' => ['sometimes', 'nullable', 'date_format:H:i'],
            'eta_minutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:240'],
        ]);

        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        foreach ([
            'billing_mode' => 'menu_billing_mode',
            'hours_from' => 'menu_hours_from',
            'hours_to' => 'menu_hours_to',
            'eta_minutes' => 'menu_eta_minutes',
        ] as $input => $key) {
            if (array_key_exists($input, $data)) {
                $settings[$key] = $data[$input];
            }
        }

        $property->update(['settings' => $settings]);

        return back();
    }

    /** Curación de la carta: qué productos del POS ve el huésped. */
    public function toggleProduct(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'available_in_menu' => ['required', 'boolean'],
        ]);

        $product->update($data);

        return back();
    }

    /**
     * Ciclo de vida de una solicitud. JSON (axios) porque el resultado
     * importa: al despachar se informa la venta generada o el aviso.
     *
     * - preparing: la cocina toma el pedido.
     * - attended: despachar — genera la venta POS (with_sale=false la omite).
     * - cancelled / pending: solo mientras no exista venta ligada.
     */
    public function updateRequest(Request $request, MenuRequest $menuRequest, DispatchMenuRequest $dispatch): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([
                MenuRequest::STATUS_PENDING,
                MenuRequest::STATUS_PREPARING,
                MenuRequest::STATUS_ATTENDED,
                MenuRequest::STATUS_CANCELLED,
            ])],
            'with_sale' => ['sometimes', 'boolean'],
        ]);

        if ($data['status'] === MenuRequest::STATUS_ATTENDED) {
            try {
                $result = $dispatch->handle(
                    $menuRequest,
                    $request->user(),
                    (bool) ($data['with_sale'] ?? true),
                );
            } catch (InvalidArgumentException|InsufficientStockException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            $order = $result['request']->order;

            return response()->json([
                'ok' => true,
                'warning' => $result['warning'],
                'order' => $order ? [
                    'id' => $order->id,
                    'total' => (float) $order->total,
                    'to_room' => $order->payment_method === 'room',
                ] : null,
            ]);
        }

        if ($menuRequest->order_id !== null) {
            return response()->json([
                'message' => 'Este pedido ya tiene una venta ligada; si hay que corregirlo, cancela la venta en el POS.',
            ], 422);
        }

        if ($data['status'] === MenuRequest::STATUS_PREPARING) {
            if ($menuRequest->status !== MenuRequest::STATUS_PENDING) {
                return response()->json(['message' => 'Solo un pedido nuevo puede pasar a preparación.'], 422);
            }

            $menuRequest->update([
                'status' => MenuRequest::STATUS_PREPARING,
                'preparing_by' => $request->user()->id,
                'preparing_at' => now(),
            ]);
        } elseif ($data['status'] === MenuRequest::STATUS_CANCELLED) {
            $menuRequest->update([
                'status' => MenuRequest::STATUS_CANCELLED,
                'attended_by' => $request->user()->id,
                'attended_at' => now(),
            ]);
        } else {
            // Reabrir: regresa a nuevos, limpio.
            $menuRequest->update([
                'status' => MenuRequest::STATUS_PENDING,
                'preparing_by' => null,
                'preparing_at' => null,
                'attended_by' => null,
                'attended_at' => null,
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /** Forma común de una solicitud para admin y cocina. */
    protected function requestPayload(MenuRequest $request): array
    {
        return [
            'id' => $request->id,
            'guest_name' => $request->guest_name,
            'room_label' => $request->room_label,
            'notes' => $request->notes,
            'items' => $request->items,
            'total' => (float) $request->total,
            'payment_mode' => $request->payment_mode,
            'payment_label' => $request->paymentLabel(),
            'status' => $request->status,
            'preparing_by' => $request->preparingBy?->name,
            'attended_by' => $request->attendedBy?->name,
            'order_id' => $request->order_id,
            'order_total' => $request->order ? (float) $request->order->total : null,
            'created_at' => $request->created_at->toIso8601String(),
            'created_time' => $request->created_at->format('H:i'),
            'created_date' => $request->created_at->format('d/m/Y H:i'),
            'preparing_time' => $request->preparing_at?->format('H:i'),
            'attended_time' => $request->attended_at?->format('H:i'),
        ];
    }

    /** KPIs compartidos por el panel y la cocina. */
    protected function stats(): array
    {
        $today = now()->startOfDay();

        $recentAttended = MenuRequest::query()
            ->where('status', MenuRequest::STATUS_ATTENDED)
            ->where('attended_at', '>=', now()->subDays(7))
            ->get(['created_at', 'attended_at']);

        return [
            'today_count' => MenuRequest::query()
                ->where('created_at', '>=', $today)
                ->where('status', '!=', MenuRequest::STATUS_CANCELLED)
                ->count(),
            'today_total' => (float) MenuRequest::query()
                ->where('status', MenuRequest::STATUS_ATTENDED)
                ->where('attended_at', '>=', $today)
                ->sum('total'),
            'waiting_now' => MenuRequest::query()
                ->whereIn('status', [MenuRequest::STATUS_PENDING, MenuRequest::STATUS_PREPARING])
                ->count(),
            // Promedio pedido→despacho de la última semana, en minutos.
            'avg_dispatch_minutes' => $recentAttended->isEmpty() ? null : (int) round(
                $recentAttended->avg(fn (MenuRequest $r) => $r->created_at->diffInMinutes($r->attended_at)),
            ),
        ];
    }

    /** Los productos más pedidos de la última semana (para curar la carta). */
    protected function topProducts(): array
    {
        return MenuRequest::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->where('status', '!=', MenuRequest::STATUS_CANCELLED)
            ->get(['items'])
            ->flatMap(fn (MenuRequest $request) => $request->items ?? [])
            ->groupBy('name')
            ->map(fn ($rows, $name) => [
                'name' => $name,
                'qty' => (int) collect($rows)->sum('qty'),
            ])
            ->sortByDesc('qty')
            ->take(5)
            ->values()
            ->all();
    }
}
