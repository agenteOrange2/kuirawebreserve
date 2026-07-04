<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeder raíz de cada tenant: lo ejecuta el pipeline de creación de tenants
 * (TenancyServiceProvider) y `php artisan tenants:seed`.
 */
class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(TenantRolesSeeder::class);
    }
}
