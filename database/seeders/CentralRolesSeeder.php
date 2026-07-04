<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles de la plataforma (DB central): solo el super-admin que administra
 * tenants y billing. Los roles de staff hotelero viven en cada tenant.
 */
class CentralRolesSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate('platform-admin');
    }
}
