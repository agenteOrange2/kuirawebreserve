<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\StaffAccess;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Roles y accesos del hotel (/usuarios/roles): qué áreas del panel ve cada
 * rol y qué puede hacer dentro de ellas. Solo el propietario entra; las
 * mutaciones van por /api/roles (RoleController).
 */
class RolesPageController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('tenant/users/Roles', StaffAccess::editorPayload());
    }
}
