<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\StaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

/**
 * Roles de un hotel editados desde la plataforma
 * (/admin/tenants/{id}/roles). Mismo StaffAccess que usa el propietario
 * en /usuarios/roles, corriendo dentro de la base del hotel.
 */
class TenantRoleController extends Controller
{
    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        return $this->inTenant($tenant, function () use ($request) {
            StaffAccess::save(null, $request->validate(StaffAccess::rules()));

            return response()->json(StaffAccess::editorPayload()['roles'], 201);
        });
    }

    public function update(Request $request, Tenant $tenant, int $roleId): JsonResponse
    {
        return $this->inTenant($tenant, function () use ($request, $roleId) {
            $role = Role::findOrFail($roleId);
            StaffAccess::save($role, $request->validate(StaffAccess::rules($role)));

            return response()->json(StaffAccess::editorPayload()['roles']);
        });
    }

    public function destroy(Tenant $tenant, int $roleId): JsonResponse
    {
        return $this->inTenant($tenant, function () use ($roleId) {
            StaffAccess::delete(Role::findOrFail($roleId));

            return response()->json(StaffAccess::editorPayload()['roles']);
        });
    }

    /**
     * Igual que TenantUserController: $tenant->run() no regresa a la base
     * central si el callback lanza (una validación fallida).
     */
    protected function inTenant(Tenant $tenant, callable $callback): JsonResponse
    {
        try {
            return $tenant->run($callback);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }
}
