<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\StaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

/**
 * Crear, editar y eliminar roles del hotel desde /usuarios/roles. La lógica
 * (resguardos del propietario y del bot, roles con gente) vive en
 * StaffAccess y la comparte el editor del admin.
 */
class RoleController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        StaffAccess::save(null, $request->validate(StaffAccess::rules()));

        return response()->json(StaffAccess::editorPayload()['roles'], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        StaffAccess::save($role, $request->validate(StaffAccess::rules($role)));

        return response()->json(StaffAccess::editorPayload()['roles']);
    }

    public function destroy(Role $role): JsonResponse
    {
        StaffAccess::delete($role);

        return response()->json(StaffAccess::editorPayload()['roles']);
    }
}
