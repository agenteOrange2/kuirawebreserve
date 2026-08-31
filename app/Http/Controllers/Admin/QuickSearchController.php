<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Búsqueda rápida del panel de plataforma (⌘K). Lo que el super-admin
 * busca a diario: un hotel por nombre, id o dominio, y una persona por
 * nombre o correo. Las páginas del menú las resuelve el front.
 */
class QuickSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());

        if (mb_strlen($term) < 2) {
            return response()->json(['groups' => []]);
        }

        $groups = [
            [
                'label' => 'Hoteles',
                'icon' => 'Building2',
                'items' => $this->tenants($term),
            ],
            [
                'label' => 'Usuarios',
                'icon' => 'UserCog',
                'items' => $this->users($term),
            ],
        ];

        return response()->json([
            'groups' => array_values(array_filter($groups, fn (array $group) => $group['items'] !== [])),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    protected function tenants(string $term): array
    {
        return Tenant::query()
            ->with('domains')
            ->where(fn ($query) => $query
                ->where('id', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")
                ->orWhereHas('domains', fn ($q) => $q->where('domain', 'like', "%{$term}%")))
            ->orderBy('name')
            ->take(6)
            ->get()
            ->map(fn (Tenant $tenant) => [
                'title' => $tenant->name ?: $tenant->id,
                'subtitle' => $tenant->domains->pluck('domain')->implode(', ') ?: $tenant->id,
                'url' => route('admin.tenants.show', $tenant->id, false),
                'badge' => $tenant->isSuspended() ? 'Suspendido' : ucfirst((string) $tenant->plan),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    protected function users(string $term): array
    {
        return User::query()
            ->where(fn ($query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%"))
            ->orderBy('name')
            ->take(6)
            ->get()
            ->map(fn (User $user) => [
                'title' => $user->name,
                'subtitle' => $user->email,
                'url' => route('admin.users', [], false),
                'badge' => null,
            ])
            ->all();
    }
}
