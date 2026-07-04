<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestión de usuarios del hotel: quiénes pueden entrar al sistema y con
 * qué rol. Las mutaciones van por /api/users.
 */
class UsersPageController extends Controller
{
    /** Etiquetas y descripciones de los roles del hotel. */
    public const ROLE_META = [
        'owner' => ['label' => 'Propietario', 'description' => 'Acceso total, incluida la gestión de usuarios y propiedades.'],
        'manager' => ['label' => 'Gerente', 'description' => 'Opera todo el hotel: reservas, habitaciones, inventario, reportes.'],
        'front-desk' => ['label' => 'Recepción', 'description' => 'Reservas, check-in/out, huéspedes (con INE) y ventas.'],
        'housekeeping' => ['label' => 'Limpieza', 'description' => 'Solo ve habitaciones y cambia su estado (limpieza).'],
        'kitchen' => ['label' => 'Cocina / Bar', 'description' => 'POS e inventario (productos e insumos).'],
        'agent' => ['label' => 'Bot / Agente IA', 'description' => 'Identidad de agentes automatizados (no se asigna manualmente).'],
    ];

    public function __invoke(Request $request): Response
    {
        $property = Property::firstOrFail();

        $openShifts = Shift::query()->open()->pluck('user_id');
        $maxUsers = tenant()->planLimit('max_users');

        $users = User::query()
            ->with('roles:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name')->values(),
                'role_labels' => $user->roles->map(fn ($r) => self::ROLE_META[$r->name]['label'] ?? $r->name)->values(),
                'is_self' => $user->id === $request->user()?->id,
                'on_shift' => $openShifts->contains($user->id),
                'created_at' => $user->created_at?->format('d/m/Y'),
            ]);

        return Inertia::render('tenant/users/Index', [
            'property' => $property->only(['id', 'name']),
            'users' => $users,
            'roles' => collect(UserController::assignableRoles())->map(fn (string $name) => [
                'name' => $name,
                'label' => self::ROLE_META[$name]['label'] ?? $name,
                'description' => self::ROLE_META[$name]['description'] ?? '',
            ])->values(),
            'maxUsers' => $maxUsers,
            'canManage' => $request->user()->can('users.manage'),
        ]);
    }
}
