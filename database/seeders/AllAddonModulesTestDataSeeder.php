<?php

namespace Database\Seeders;

use Database\Seeders\Modules\AccountingModuleTestDataSeeder;
use Database\Seeders\Modules\AiAssistanceModuleTestDataSeeder;
use Database\Seeders\Modules\AssetManagementModuleTestDataSeeder;
use Database\Seeders\Modules\CmsModuleTestDataSeeder;
use Database\Seeders\Modules\ConnectorModuleTestDataSeeder;
use Database\Seeders\Modules\CrmModuleTestDataSeeder;
use Database\Seeders\Modules\CustomDashboardModuleTestDataSeeder;
use Database\Seeders\Modules\EcommerceModuleTestDataSeeder;
use Database\Seeders\Modules\EssentialsModuleTestDataSeeder;
use Database\Seeders\Modules\FieldForceModuleTestDataSeeder;
use Database\Seeders\Modules\HmsModuleTestDataSeeder;
use Database\Seeders\Modules\InboxReportModuleTestDataSeeder;
use Database\Seeders\Modules\ManufacturingModuleTestDataSeeder;
use Database\Seeders\Modules\ProductCatalogueModuleTestDataSeeder;
use Database\Seeders\Modules\ProjectModuleTestDataSeeder;
use Database\Seeders\Modules\RepairModuleTestDataSeeder;
use Database\Seeders\Modules\SpreadsheetModuleTestDataSeeder;
use Database\Seeders\Modules\SuperadminModuleTestDataSeeder;
use Illuminate\Database\Seeder;

/**
 * Runs optional dummy_data hooks for each nwidart module (see modules_statuses.json).
 * No-op when a module's DataController is missing or has no dummy_data method.
 */
class AllAddonModulesTestDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AccountingModuleTestDataSeeder::class,
            AiAssistanceModuleTestDataSeeder::class,
            AssetManagementModuleTestDataSeeder::class,
            CmsModuleTestDataSeeder::class,
            ConnectorModuleTestDataSeeder::class,
            CrmModuleTestDataSeeder::class,
            CustomDashboardModuleTestDataSeeder::class,
            EcommerceModuleTestDataSeeder::class,
            EssentialsModuleTestDataSeeder::class,
            FieldForceModuleTestDataSeeder::class,
            HmsModuleTestDataSeeder::class,
            InboxReportModuleTestDataSeeder::class,
            ManufacturingModuleTestDataSeeder::class,
            ProductCatalogueModuleTestDataSeeder::class,
            ProjectModuleTestDataSeeder::class,
            RepairModuleTestDataSeeder::class,
            SpreadsheetModuleTestDataSeeder::class,
            SuperadminModuleTestDataSeeder::class,
        ]);
    }
}
