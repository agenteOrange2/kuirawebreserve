<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\StaffNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Campana del panel: los últimos avisos y su conteo sin leer.
 */
class StaffNotificationController extends Controller
{
    protected const RECENT = 15;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = StaffNotification::query()
            ->for($user)
            ->latest('id')
            ->take(self::RECENT)
            ->get()
            ->map(fn (StaffNotification $n) => $this->serialize($n));

        return response()->json([
            'notifications' => $notifications,
            'unread' => StaffNotification::query()->for($user)->unread()->count(),
        ]);
    }

    /** Marca una como leída (al picarle para ir a su pantalla). */
    public function read(Request $request, StaffNotification $notification): JsonResponse
    {
        // Un aviso dirigido a otra persona no se marca desde aquí.
        abort_unless(
            $notification->user_id === null || $notification->user_id === $request->user()?->id,
            404,
        );

        $notification->forceFill(['read_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $marked = StaffNotification::query()
            ->for($request->user())
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['marked' => $marked]);
    }

    /** @return array<string, mixed> */
    protected function serialize(StaffNotification $n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'url' => $n->url,
            'read' => $n->read_at !== null,
            'at' => $n->created_at?->diffForHumans(short: true),
        ];
    }
}
