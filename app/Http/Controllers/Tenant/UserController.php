<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\CashCut;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /** Roles asignables desde el panel (agent es identidad de bots). */
    public static function assignableRoles(): array
    {
        return Role::query()
            ->where('name', '!=', 'agent')
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(self::assignableRoles())],
        ]);

        // Límite de usuarios del plan del hotel.
        $maxUsers = tenant()->planLimit('max_users');
        if ($maxUsers !== null && User::count() >= $maxUsers) {
            return response()->json([
                'message' => "Tu plan permite hasta {$maxUsers} usuarios; mejora el plan para agregar más.",
            ], 422);
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        $user->assignRole($data['role']);

        return response()->json($user->only(['id', 'name', 'email']), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['sometimes', 'required', Rule::in(self::assignableRoles())],
        ]);

        // No dejar al hotel sin dueño: el último owner no puede perder el rol.
        if (isset($data['role']) && $data['role'] !== 'owner' && $this->isLastOwner($user)) {
            return response()->json([
                'message' => 'Es el único propietario; asigna otro dueño antes de cambiarle el rol.',
            ], 422);
        }

        $user->fill(collect($data)->only(['name', 'email'])->all());
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        return response()->json($user->only(['id', 'name', 'email']));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()?->id) {
            return response()->json(['message' => 'No puedes eliminar tu propia cuenta.'], 422);
        }

        if ($this->isLastOwner($user)) {
            return response()->json([
                'message' => 'Es el único propietario; asigna otro dueño antes de eliminarlo.',
            ], 422);
        }

        // Con actividad registrada (ventas, cobros, turnos, cortes) se
        // conserva por auditoría; no se elimina.
        $hasActivity = Order::query()->where('created_by', $user->id)->exists()
            || Payment::query()->where('received_by', $user->id)->exists()
            || Shift::query()->where('user_id', $user->id)->exists()
            || CashCut::query()->where('user_id', $user->id)->exists();

        if ($hasActivity) {
            return response()->json([
                'message' => 'Este usuario tiene ventas, turnos o cortes registrados; no se puede eliminar (se conserva por auditoría).',
            ], 409);
        }

        $user->delete();

        return response()->json(status: 204);
    }

    protected function isLastOwner(User $user): bool
    {
        return $user->hasRole('owner')
            && User::role('owner')->count() <= 1;
    }
}
