<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Test setup: reset admin password, grant full app permissions, enable core modules,
 * then invoke each addon module's dummy_data (if implemented).
 *
 * Prerequisite: a user with username "admin" must exist (e.g. after DummyBusinessSeeder).
 *
 * For a full demo DB in one go (after migrate:fresh): {@see FullDummyWithTestAdminSeeder}.
 * The pos:dummyBusiness command also runs this at the end.
 *
 * Usage: php artisan db:seed --class=TestingAdminAndModulesSeeder
 */
class TestingAdminAndModulesSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(TestingAdminAccessSeeder::class);
        $this->call(AllAddonModulesTestDataSeeder::class);
    }
}
