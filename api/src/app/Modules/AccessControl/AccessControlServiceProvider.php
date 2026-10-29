<?php

namespace App\Modules\AccessControl;

use App\Modules\AccessControl\Console\SyncPermissionsCommand;
use App\Modules\AccessControl\Permissions\AccessPermission;
use App\Modules\AccessControl\Services\VersionedPermissionRegistrar;
use App\Modules\Core\Support\ModuleServiceProvider;
use Spatie\Permission\PermissionRegistrar;

class AccessControlServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->registerPermissions(AccessPermission::groupDefinitions(), [AccessPermission::class]);

        // Versioned permission cache (M2). `extend` applies whenever the registrar is
        // resolved, regardless of whether Spatie's provider registers before or after
        // this one, so the versioned registrar replaces Spatie's everywhere.
        $this->app->extend(PermissionRegistrar::class, fn ($registrar, $app) => $registrar instanceof VersionedPermissionRegistrar
            ? $registrar
            : new VersionedPermissionRegistrar($app['cache']));
        $this->app->alias(PermissionRegistrar::class, VersionedPermissionRegistrar::class);
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
