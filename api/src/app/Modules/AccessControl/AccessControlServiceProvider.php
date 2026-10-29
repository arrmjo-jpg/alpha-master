<?php

namespace App\Modules\AccessControl;

use App\Modules\AccessControl\Console\SyncPermissionsCommand;
use App\Modules\AccessControl\Permissions\AccessPermission;
use App\Modules\Core\Support\ModuleServiceProvider;

class AccessControlServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->registerPermissions(AccessPermission::groupDefinitions(), [AccessPermission::class]);
    }

    public function boot(): void
    {
        $this->loadModuleMigrations();
        $this->loadApiRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([SyncPermissionsCommand::class]);
        }
    }
}
