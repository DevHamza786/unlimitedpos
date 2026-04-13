<?php

namespace Database\Seeders;

use App\Business;
use App\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class TestingAdminAccessSeeder extends Seeder
{
    /**
     * Core POS feature flags stored in business.enabled_modules (not the same as nwidart addon modules).
     */
    public const ALL_CORE_ENABLED_MODULES = [
        'purchases',
        'add_sale',
        'pos_sale',
        'stock_transfers',
        'stock_adjustment',
        'expenses',
        'account',
        'subscription',
        'tables',
        'service_staff',
        'booking',
        'kitchen',
        'modifiers',
        'types_of_service',
    ];

    public const TEST_ADMIN_USERNAME = 'admin';

    public const TEST_ADMIN_PASSWORD = '123456';

    public function run(): void
    {
        $user = User::where('username', self::TEST_ADMIN_USERNAME)->first();

        if (empty($user)) {
            $this->command?->warn(
                'No user with username "'.self::TEST_ADMIN_USERNAME.'". Seed a business first (e.g. DummyBusinessSeeder) or register via the installer.'
            );

            return;
        }

        $user->password = Hash::make(self::TEST_ADMIN_PASSWORD);
        $user->save();

        $businessId = (int) $user->business_id;
        $roleName = 'Admin#'.$businessId;

        $adminRole = Role::firstOrCreate(
            [
                'name' => $roleName,
                'business_id' => $businessId,
                'guard_name' => 'web',
            ],
            ['is_default' => 1]
        );

        $user->syncRoles([$roleName]);

        if (Permission::query()->exists()) {
            $adminRole->syncPermissions(Permission::all());
        }

        $business = Business::find($businessId);
        if ($business) {
            $business->enabled_modules = array_values(array_unique(array_merge(
                $business->enabled_modules ?? [],
                self::ALL_CORE_ENABLED_MODULES
            )));
            $business->save();
        }

        $this->command?->info(
            'Test admin ready: username "'.self::TEST_ADMIN_USERNAME.'" / password "'.self::TEST_ADMIN_PASSWORD.'". '.
            'For Superadmin abilities (manage_modules, backup, etc.) set ADMINISTRATOR_USERNAMES='.self::TEST_ADMIN_USERNAME.' in .env.'
        );
    }
}
