<?php

namespace Database\Seeders\Concerns;

trait CallsModuleDummyData
{
    /**
     * Invoke a module's DataController::dummy_data() when the module is present.
     */
    protected function seedModuleDummyData(string $moduleName): void
    {
        $class = 'Modules\\'.$moduleName.'\\Http\\Controllers\\DataController';

        if (! class_exists($class)) {
            return;
        }

        $controller = new $class();

        if (method_exists($controller, 'dummy_data')) {
            $controller->dummy_data();
        }
    }
}
