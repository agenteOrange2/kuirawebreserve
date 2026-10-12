<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Shift;
use App\Models\User;
use App\Services\StaffAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestión de usuarios del hotel: quiénes pueden entrar al sistema y con
 * qué rol. Las mutaciones van por /api/users.
 */
class UsersPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $property = Property::firstOrFail();

        $openShifts = Shift::query()->open()->pluck('user_id');
        $maxUsers = tenant()->planLimit('max_users');

        $roleMeta = StaffAccess::roleMeta();

        $users = User::query()
            ->with('roles:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'roles' => $user->roles->pluck('name')->values(),
                'role_labels' => $user->roles->map(fn ($r) => $roleMeta[$r->name]['label'] ?? $r->name)->values(),
                'is_self' => $user->id === $request->user()?->id,
                'on_shift' => $openShifts->contains($user->id),
                'created_at' => $user->created_at?->format('d/m/Y'),
            ]);

        return Inertia::render('tenant/users/Index', [
            'property' => $property->only(['id', 'name']),
            'users' => $users,
            'roles' => collect(StaffAccess::assignableRoles())->map(fn (string $name) => [
                'name' => $name,
                'label' => $roleMeta[$name]['label'] ?? $name,
                'description' => $roleMeta[$name]['description'] ?? '',
            ])->values(),
            'maxUsers' => $maxUsers,
            'canManage' => $request->user()->can('users.manage'),
            // Roles y accesos (/usuarios/roles) es solo del propietario.
            'canEditRoles' => $request->user()->hasRole(StaffAccess::OWNER),
        ]);
    }
}
