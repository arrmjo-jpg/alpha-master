<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\AccessControl\Actions\ManageRoles;
use App\Modules\AccessControl\Services\VersionedPermissionRegistrar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\ApiTestCase;

/** M2: versioned permission cache — single-process behavior (removal, rollback, nesting). */
class PermissionCacheVersionTest extends ApiTestCase
{
    private function version(): string
    {
        return (string) Cache::get(VersionedPermissionRegistrar::VERSION_KEY);
    }

    public function test_registrar_is_the_versioned_implementation(): void
    {
        $registrar = app(PermissionRegistrar::class);

        $this->assertInstanceOf(VersionedPermissionRegistrar::class, $registrar);
        $this->assertSame($registrar, app(VersionedPermissionRegistrar::class));
        $this->assertSame(config('permission.cache.key').'.'.$this->version(), $registrar->cacheKey);
    }

    public function test_removed_permission_is_not_resurrected_by_a_late_stale_write(): void
    {
        $role = $this->roleWith('users.view');
        $member = User::factory()->admin()->create();
        $member->assignRole($role);
        $manager = $this->adminWith('users.view', 'roles.assign-permissions');

        $registrar = app(PermissionRegistrar::class);
        $this->assertTrue($member->fresh()->hasPermissionTo('users.view'));
        $oldKey = $registrar->cacheKey;
        $staleMap = Cache::get($oldKey);
        $this->assertNotNull($staleMap);

        app(ManageRoles::class)->syncPermissions($manager, $role, []);

        // A slow request finishes late and writes the pre-change map to the key it started with.
        Cache::forever($oldKey, $staleMap);

        $this->app->forgetInstance(PermissionRegistrar::class);
        $this->assertNotSame($oldKey, app(PermissionRegistrar::class)->cacheKey);
        $this->assertFalse($member->fresh()->hasPermissionTo('users.view'));
    }

    public function test_rolled_back_change_does_not_switch_the_version(): void
    {
        $role = $this->roleWith('users.view');
        $manager = $this->adminWith('users.view', 'roles.assign-permissions');
        $before = $this->version();

        DB::beginTransaction();
        app(ManageRoles::class)->syncPermissions($manager, $role, []);
        DB::rollBack();

        $this->assertSame($before, $this->version());
        $this->assertSame(['users.view'], $role->fresh('permissions')->permissions->pluck('name')->all());
    }

    public function test_nested_transaction_switches_the_version_only_after_the_outer_commit(): void
    {
        $role = $this->roleWith('users.view');
        $manager = $this->adminWith('users.view', 'roles.assign-permissions');
        $before = $this->version();

        DB::transaction(function () use ($manager, $role, $before) {
            app(ManageRoles::class)->syncPermissions($manager, $role, []);
            $this->assertSame($before, $this->version(), 'Must not switch while the outer transaction is open.');
        });

        $this->assertNotSame($before, $this->version());
    }
}
