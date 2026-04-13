<?php

namespace Database\Seeders;

use App\Utils\ModuleUtil;
use Illuminate\Database\Seeder;

/**
 * One-shot demo dataset after a fresh migration (does not run migrations).
 *
 * Order: base reference data → DummyBusinessSeeder (large demo DB) → each installed
 * module's DataController::dummy_data() → test admin (admin / 123456) + all permissions + module seed hooks.
 *
 * Usage (typical):
 *   php artisan migrate:fresh --force
 *   php artisan db:seed --class=FullDummyWithTestAdminSeeder
 */
class FullDummyWithTestAdminSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BarcodesTableSeeder::class,
            PermissionsTableSeeder::class,
            CurrenciesTableSeeder::class,
        ]);

        $this->call(DummyBusinessSeeder::class);

        (new ModuleUtil())->getModuleData('dummy_data');

        $this->call(TestingAdminAndModulesSeeder::class);
    }
}
