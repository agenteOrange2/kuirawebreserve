<?php

namespace App\Http\Controllers\Tenant;

use App\Models\StaffNotification;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Historial COMPLETO de avisos (/bandeja/avisos): la campana solo enseña los
 * últimos 15; aquí vive todo con filtros, paginación y borrado. Extiende el
 * controlador de la campana solo para reutilizar la serialización.
 */
class StaffNotificationsPageController extends StaffNotificationController
{
    protected const TYPES = [
        StaffNotification::TYPE_MESSAGE,
        StaffNotification::TYPE_RESERVATION,
        StaffNotification::TYPE_PAYMENT,
    ];

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $search = trim($request->string('q')->toString());
        $type = $request->string('type')->toString();
        $unread = $request->boolean('unread');

        if (! in_array($type, self::TYPES, true)) {
            $type = '';
        }

        $paginator = StaffNotification::query()
            ->for($user)
            ->when($type !== '', fn ($q) => $q->where('type', $type))
            ->when($unread, fn ($q) => $q->unread())
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('title', 'like', "%{$search}%")
                ->orWhere('body', 'like', "%{$search}%")))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $paginator->through(fn (StaffNotification $n) => $this->serialize($n));

        return Inertia::render('tenant/inbox/Notifications', [
            'notifications' => $paginator,
            'unread' => StaffNotification::query()->for($user)->unread()->count(),
            'filters' => [
                'q' => $search,
                'type' => $type,
                'unread' => $unread,
            ],
        ]);
    }
}
