<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\UserController as HotelUserController;
use App\Models\CashCut;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gestión de los usuarios (datos de acceso del personal) de un hotel desde
 * el panel de plataforma. Todo corre dentro de $tenant->run() para operar
 * sobre la BD del hotel; reutiliza los mismos resguardos que el CRUD interno
 * (último dueño, límite del plan, actividad registrada).
 */
class TenantUserController extends Controller
{
    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        return $tenant->run(function () use ($request, $tenant) {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8'],
                'role' => ['required', Rule::in(HotelUserController::assignableRoles())],
            ]);

            $maxUsers = $tenant->planLimit('max_users');
            if ($maxUsers !== null && User::count() >= $maxUsers) {
                return response()->json([
                    'message' => "El plan de este hotel permite hasta {$maxUsers} usuarios; cámbialo para agregar más.",
                ], 422);
            }

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            $user->assignRole($data['role']);

            return response()->json($this->serialize($user), 201);
        });
    }

    public function update(Request $request, Tenant $tenant, int $userId): JsonResponse
    {
        return $tenant->run(function () use ($request, $userId) {
            $user = User::findOrFail($userId);

            $data = $request->validate([
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
                'password' => ['nullable', 'string', 'min:8'],
                'role' => ['sometimes', 'required', Rule::in(HotelUserController::assignableRoles())],
            ]);

            // No dejar al hotel sin dueño.
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

            return response()->json($this->serialize($user->refresh()));
        });
    }

    public function destroy(Tenant $tenant, int $userId): JsonResponse
    {
        return $tenant->run(function () use ($userId) {
            $user = User::findOrFail($userId);

            if ($this->isLastOwner($user)) {
                return response()->json([
                    'message' => 'Es el único propietario; asigna otro dueño antes de eliminarlo.',
                ], 422);
            }

            // Con actividad (ventas, cobros, turnos, cortes) se conserva por
            // auditoría en vez de borrarse.
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
        });
    }

    protected function isLastOwner(User $user): bool
    {
        return $user->hasRole('owner') && User::role('owner')->count() <= 1;
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getRoleNames()->first(),
        ];
    }
}
