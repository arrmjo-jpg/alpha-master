<?php

namespace App\Modules\Users;

use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Users\Console\CreateOwnerCommand;
use App\Modules\Users\Console\GrantOwnerCommand;
use App\Modules\Users\Console\RevokeOwnerCommand;
use App\Modules\Users\Permissions\UserPermission;

class UsersServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->registerPermissions([UserPermission::groupDefinition()], [UserPermission::class]);
    }

    public function boot(): void
    {
        $this->loadModuleMigrations();
        $this->loadApiRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([CreateOwnerCommand::class, GrantOwnerCommand::class, RevokeOwnerCommand::class]);
        }
    }
}
