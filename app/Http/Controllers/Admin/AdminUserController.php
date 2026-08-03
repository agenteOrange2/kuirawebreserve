<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD de los usuarios del panel de plataforma (BD central). El acceso es
 * el rol platform-admin: con él se entra a /admin y, desde ahí, a cualquier
 * hotel con "Entrar como". Resguardos: nadie se quita el acceso a sí mismo
 * ni se elimina, y siempre queda al menos un administrador.
 */
class AdminUserController extends Controller
{
    public const ROLE = 'platform-admin';

    public function index(): Response
    {
        return Inertia::render('admin/users/Index', [
            'users' => User::with('roles:id,name')
                ->orderBy('name')->get()
                ->map(fn (User $u) => $this->serialize($u))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8'],
            'is_admin' => ['required', 'boolean'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
        ]);

        if ($data['is_admin']) {
            $user->assignRole(self::ROLE);
        }

        return response()->json($this->serialize($user), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', 'string', 'min:8'],
            'is_admin' => ['sometimes', 'required', 'boolean'],
        ]);

        if (array_key_exists('is_admin', $data) && ! $data['is_admin'] && $user->hasRole(self::ROLE)) {
            if ($user->id === $request->user()->id) {
                return response()->json([
                    'message' => 'No puedes quitarte el acceso a ti mismo; pide a otro administrador que lo haga.',
                ], 422);
            }
            if ($this->isLastAdmin($user)) {
                return response()->json([
                    'message' => 'Es el único administrador de la plataforma; da acceso a otro antes de quitárselo.',
                ], 422);
            }
        }

        $user->fill(collect($data)->only(['name', 'email', 'phone'])->all());
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        if (array_key_exists('is_admin', $data)) {
            $data['is_admin']
                ? $user->assignRole(self::ROLE)
                : $user->removeRole(self::ROLE);
        }

        return response()->json($this->serialize($user->refresh()));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'No puedes eliminar tu propia cuenta desde aquí; hazlo desde Configuración o pide a otro administrador.',
            ], 422);
        }

        if ($this->isLastAdmin($user)) {
            return response()->json([
                'message' => 'Es el único administrador de la plataforma; da acceso a otro antes de eliminarlo.',
            ], 422);
        }

        $user->delete();

        return response()->json(status: 204);
    }

    protected function isLastAdmin(User $user): bool
    {
        return $user->hasRole(self::ROLE) && User::role(self::ROLE)->count() <= 1;
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
            'phone' => $user->phone,
            'is_admin' => $user->hasRole(self::ROLE),
            'two_factor' => $user->two_factor_confirmed_at !== null,
            'created_at' => $user->created_at?->format('d/m/Y'),
        ];
    }
}
