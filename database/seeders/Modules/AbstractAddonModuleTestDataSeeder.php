<?php

namespace Database\Seeders\Modules;

use Database\Seeders\Concerns\CallsModuleDummyData;
use Illuminate\Database\Seeder;

abstract class AbstractAddonModuleTestDataSeeder extends Seeder
{
    use CallsModuleDummyData;

    abstract protected function moduleName(): string;

    public function run(): void
    {
        $this->seedModuleDummyData($this->moduleName());
    }
}
