<?php

namespace Tests\Feature\Concurrency;

use App\Models\User;
use App\Modules\AccessControl\Actions\ManageRoles;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;

/**
 * M2, reproduced for real: another process reads the permission map from MySQL
 * BEFORE a permission removal commits and writes it to the shared (database)
 * cache AFTER the post-commit invalidation. The stale write must not be used.
 */
class PermissionCacheRaceTest extends ConcurrencyTestCase
{
    public function test_stale_cache_write_after_invalidation_is_never_served(): void
    {
        config(['cache.default' => 'database']);
        $this->app->forgetInstance(PermissionRegistrar::class);
        $oldKey = app(PermissionRegistrar::class)->cacheKey;

        $role = $this->roleWith('users.view');
        $member = User::factory()->admin()->create();
        $member->assignRole($role);
        $manager = $this->adminWith('users.view', 'roles.assign-permissions');
        Cache::store('database')->forget($oldKey); // force a cache miss in the slow reader

        // The slow reader loads the OLD map from MySQL, then waits before writing it.
        $resume = $this->tempPath('resume');
        $reader = $this->startPausedAction([
            'scenario' => 'load_permission_cache',
            'args' => [],
            'pause_on' => 'role_has_permissions',
            'marker' => $this->tempPath('read'),
            'resume' => $resume,
        ], ['CACHE_STORE' => 'database']);

        // Meanwhile the permission is removed and committed (version bumped after commit).
        app(ManageRoles::class)->syncPermissions($manager->fresh(), $role->fresh(), []);
        touch($resume);
        $result = $this->finish($reader);

        // The race really happened: the stale map was written after the invalidation…
        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame($oldKey, $result['cache_key']);
        $this->assertNotNull(Cache::store('database')->get($oldKey), 'The slow reader should have written its stale map.');

        // …but a fresh request (new registrar, as in another instance) never reads it.
        $this->app->forgetInstance(PermissionRegistrar::class);
        $this->assertNotSame($oldKey, app(PermissionRegistrar::class)->cacheKey);
        $this->assertFalse(User::query()->find($member->id)->hasPermissionTo('users.view'), 'The removed permission must not come back from a stale cache entry.');
    }
}
