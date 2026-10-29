<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Permissions\DefinesPermissions;
use App\Modules\Core\Permissions\PermissionGroupDefinition;
use App\Modules\Core\Permissions\PermissionRegistry;
use BackedEnum;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Base provider for app/Modules/* (ADR-0001). Each module calls only the
 * helpers it needs from boot().
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    protected function modulePath(string $path = ''): string
    {
        $dir = dirname((new ReflectionClass(static::class))->getFileName());

        return $path === '' ? $dir : $dir.DIRECTORY_SEPARATOR.$path;
    }

    protected function loadModuleMigrations(): void
    {
        $this->loadMigrationsFrom($this->modulePath('Database/Migrations'));
    }

    protected function loadModuleTranslations(string $namespace): void
    {
        $this->loadTranslationsFrom($this->modulePath('Lang'), $namespace);
    }

    /** Registers the module's routes under /api/v1 with the `api` middleware group. */
    protected function loadApiRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware('api')
            ->prefix('api/v1')
            ->group($this->modulePath('Routes/api.php'));
    }

    /**
     * @param  list<PermissionGroupDefinition>  $groups
     * @param  list<class-string<BackedEnum&DefinesPermissions>>  $enums
     */
    protected function registerPermissions(array $groups, array $enums): void
    {
        $this->callAfterResolving(PermissionRegistry::class, function (PermissionRegistry $registry) use ($groups, $enums) {
            foreach ($groups as $group) {
                $registry->addGroup($group);
            }
            foreach ($enums as $enum) {
                $registry->addPermissions($enum);
            }
        });
    }
}
