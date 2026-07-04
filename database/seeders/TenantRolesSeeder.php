<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles de staff dentro de un hotel (spec §12). Corre en la DB de cada tenant.
 */
class TenantRolesSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'properties.manage',
            'users.manage',
            'rooms.view',
            'rooms.manage',
            'rooms.update-status',
            'reservations.view',
            'reservations.manage',
            'inventory.manage',
            'orders.manage',
            'shifts.manage',
            'guests.view',
            'guests.manage',
            'guests.view-documents',
            'reports.view',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $roles = [
            'owner' => $permissions,
            'manager' => [
                'rooms.view',
                'rooms.manage',
                'rooms.update-status',
                'reservations.view',
                'reservations.manage',
                'inventory.manage',
                'orders.manage',
                'shifts.manage',
                'guests.view',
                'guests.manage',
                'guests.view-documents',
                'reports.view',
            ],
            // Front-desk hace el check-in: captura y verifica el INE.
            'front-desk' => [
                'rooms.view',
                'rooms.update-status',
                'reservations.view',
                'reservations.manage',
                'orders.manage',
                'guests.view',
                'guests.manage',
                'guests.view-documents',
            ],
            'housekeeping' => [
                'rooms.view',
                'rooms.update-status',
            ],
            'kitchen' => [
                'inventory.manage',
                'orders.manage',
            ],
            // Identidad de los bots (agentes IA) para auditoría.
            'agent' => [
                'rooms.view',
                'reservations.view',
                'reservations.manage',
            ],
        ];

        foreach ($roles as $role => $rolePermissions) {
            Role::findOrCreate($role)->syncPermissions($rolePermissions);
        }
    }
}
