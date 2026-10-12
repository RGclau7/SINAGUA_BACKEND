<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Datos base del sistema. El superadministrador se crea con `php artisan sinagua:superadmin`.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
    }
}
