<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->call([BarcodesTableSeeder::class,
            PermissionsTableSeeder::class,
            CurrenciesTableSeeder::class,
        ]);

        if (filter_var(env('SEED_TEST_ADMIN_AND_MODULES', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->call(TestingAdminAndModulesSeeder::class);
        }
    }
}
