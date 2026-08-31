<?php

namespace App\Http\Controllers\Tenant;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Ficha de un usuario del sistema (/usuarios/{user}): quién es, qué puede
 * hacer, cuándo entró por última vez, sus turnos y TODO lo que ha hecho.
 *
 * La bitácora global (/actividad) responde "qué pasó en el hotel"; esta
 * responde "qué ha hecho esta persona", que es lo que se pregunta cuando
 * algo se movió y hay que rastrear a quién preguntarle.
 *
 * Extiende ActivityLogPageController para reutilizar la traducción de las
 * entradas del activity log a español (mismo criterio con el que esa clase
 * extiende ReservationsPageController).
 */
class UserShowController extends ActivityLogPageController
{
    /** No es __invoke porque la clase padre ya lo declara con otra firma. */
    public function show(Request $request, User $user): Response
    {
        $user->load('roles:id,name');

        $type = $request->string('type')->toString();
        if (! array_key_exists($type, self::TYPES)) {
            $type = '';
        }

        $activities = Activity::query()
            ->with(['causer', 'subject'])
            ->where('causer_type', User::class)
            ->where('causer_id', $user->id)
            ->when($type !== '', fn ($q) => $q->whereIn('log_name', self::TYPES[$type]['logs']))
            ->latest()
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $activities->through(fn (Activity $activity) => $this->serializeActivity($activity));

        return Inertia::render('tenant/users/Show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'roles' => $user->roles->pluck('name')->values(),
                'role_labels' => $user->roles
                    ->map(fn ($role) => UsersPageController::ROLE_META[$role->name]['label'] ?? $role->name)
                    ->values(),
                'role_descriptions' => $user->roles
                    ->map(fn ($role) => UsersPageController::ROLE_META[$role->name]['description'] ?? '')
                    ->values(),
                'is_self' => $user->id === $request->user()?->id,
                'created_at' => $user->created_at?->format('d/m/Y'),
                'email_verified' => $user->email_verified_at !== null,
            ],
            'stats' => $this->stats($user),
            // Turnos de caja: solo con el módulo encendido (igual que /turnos).
            'shifts' => (tenant()?->hasModule('corte-caja') ?? true)
                ? $this->shifts($user)
                : [],
            'activities' => $activities,
            'filters' => ['type' => $type],
            'types' => collect(self::TYPES)
                ->map(fn (array $meta, string $key) => ['value' => $key, 'label' => $meta['label']])
                ->values(),
            'canManage' => $request->user()->can('users.manage'),
        ]);
    }

    /**
     * Cifras de la persona: cuánto ha movido y cuándo se le vio por última
     * vez (la sesión sale de la tabla `sessions`, que es el único rastro de
     * acceso que guarda la app).
     *
     * @return array<string, mixed>
     */
    protected function stats(User $user): array
    {
        $base = fn () => Activity::query()
            ->where('causer_type', User::class)
            ->where('causer_id', $user->id);

        $lastActivityAt = $base()->max('created_at');
        $lastSessionAt = DB::table('sessions')
            ->where('user_id', $user->id)
            ->max('last_activity');

        $openShift = Shift::query()
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();

        return [
            'actions_total' => $base()->count(),
            'actions_30d' => $base()->where('created_at', '>=', now()->subDays(30))->count(),
            'last_activity_at' => $lastActivityAt
                ? Carbon::parse($lastActivityAt)->format('d/m/Y H:i')
                : null,
            'last_session_at' => $lastSessionAt
                ? Carbon::createFromTimestamp((int) $lastSessionAt)->format('d/m/Y H:i')
                : null,
            'on_shift' => $openShift !== null,
            'shift_since' => $openShift?->started_at?->format('d/m/Y H:i'),
            // Qué tanto toca cada área: es lo que dice si alguien está
            // trabajando donde debería.
            'by_type' => collect(self::TYPES)
                ->map(fn (array $meta, string $key) => [
                    'key' => $key,
                    'label' => $meta['label'],
                    'count' => $base()->whereIn('log_name', $meta['logs'])->count(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Últimos turnos de caja de la persona, con su duración.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function shifts(User $user): array
    {
        return Shift::query()
            ->where('user_id', $user->id)
            ->latest('started_at')
            ->limit(5)
            ->get()
            ->map(fn (Shift $shift) => [
                'id' => $shift->id,
                'started_at' => $shift->started_at?->format('d/m/Y H:i'),
                'ended_at' => $shift->ended_at?->format('d/m/Y H:i'),
                'open' => $shift->ended_at === null,
                'minutes' => $shift->started_at
                    ? (int) $shift->started_at->diffInMinutes($shift->ended_at ?? now())
                    : null,
                'opening_cash' => round((float) $shift->opening_cash, 2),
            ])
            ->all();
    }
}
