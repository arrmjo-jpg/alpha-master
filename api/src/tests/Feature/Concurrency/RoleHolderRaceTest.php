<?php

namespace Tests\Feature\Concurrency;

use App\Models\User;
use App\Modules\AccessControl\Actions\ManageRoles;
use App\Modules\AccessControl\Models\Role;
use App\Modules\Core\Errors\DomainError;
use App\Modules\Core\Errors\ErrorCode;

/**
 * M1: role mutations must see holders added or strengthened by a concurrent,
 * real SyncUserRoles / ManageRoles action in another process.
 */
class RoleHolderRaceTest extends ConcurrencyTestCase
{
    private User $owner;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->owner();
        // The actor may edit roles, but must not weaken anyone holding users.ban.
        $this->actor = $this->adminWith('users.view', 'users.unban', 'roles.assign-permissions');
    }

    /** @return array{0: ?string, 1: float} error code (null = success) and seconds spent */
    private function stripRole(Role $role): array
    {
        $started = microtime(true);
        try {
            app(ManageRoles::class)->syncPermissions($this->actor->fresh(), $role->fresh(), []);

            return [null, microtime(true) - $started];
        } catch (DomainError $e) {
            return [$e->errorCode->value, microtime(true) - $started];
        }
    }

    public function test_holder_added_concurrently_is_checked_before_a_role_is_weakened(): void
    {
        $shared = $this->roleWith('users.unban');
        $strong = $this->roleWith('users.view', 'users.ban', 'roles.assign-permissions');
        $stronger = User::factory()->admin()->create();
        $stronger->assignRole($strong);

        // Process B (owner) is assigning the shared role to the stronger admin; it holds its lock.
        $assign = $this->startPausedAction([
            'scenario' => 'sync_user_roles',
            'args' => ['actor' => $this->owner->id, 'target' => $stronger->id, 'roles' => [$shared->id, $strong->id]],
            'pause_on' => 'for update',
            'marker' => $this->tempPath('assign'),
            'hold_seconds' => 2,
        ]);

        // Process A (this one) tries to strip the shared role at the same moment.
        [$error] = $this->stripRole($shared);

        $this->assertTrue($this->finish($assign)['ok'], 'The concurrent role assignment must succeed.');
        $this->assertSame(ErrorCode::TargetNotManageable->value, $error, 'The new holder must be seen and protected.');
        $this->assertSame(['users.unban'], $shared->fresh('permissions')->permissions->pluck('name')->all());
        $this->assertTrue($stronger->fresh()->hasRole($shared));
    }

    public function test_holder_strengthened_concurrently_through_another_role_is_seen(): void
    {
        $shared = $this->roleWith('users.unban');
        $other = $this->roleWith('users.view');
        $holder = User::factory()->admin()->create();
        $holder->assignRole($shared, $other);

        // Process B (owner) is giving the holder's OTHER role users.ban; it holds its lock.
        $strengthen = $this->startPausedAction([
            'scenario' => 'sync_role_permissions',
            'args' => ['actor' => $this->owner->id, 'role' => $other->id, 'permissions' => ['users.view', 'users.ban']],
            'pause_on' => 'for update',
            'marker' => $this->tempPath('strengthen'),
            'hold_seconds' => 2,
        ]);

        [$error] = $this->stripRole($shared);

        $this->assertTrue($this->finish($strengthen)['ok']);
        $this->assertSame(ErrorCode::TargetNotManageable->value, $error, 'The strengthened holder must no longer be manageable.');
        $this->assertSame(['users.unban'], $shared->fresh('permissions')->permissions->pluck('name')->all());
    }

    public function test_unrelated_concurrent_changes_serialize_and_both_succeed_without_deadlock(): void
    {
        $roleForB = $this->roleWith('users.view');
        $roleForA = $this->roleWith('users.unban');
        $holder = User::factory()->admin()->create();
        $holder->assignRole($roleForA);
        $target = User::factory()->admin()->create();

        $assign = $this->startPausedAction([
            'scenario' => 'sync_user_roles',
            'args' => ['actor' => $this->owner->id, 'target' => $target->id, 'roles' => [$roleForB->id]],
            'pause_on' => 'for update',
            'marker' => $this->tempPath('unrelated'),
            'hold_seconds' => 1.5,
        ]);

        [$error, $seconds] = $this->stripRole($roleForA);

        $this->assertTrue($this->finish($assign)['ok']);
        $this->assertNull($error, 'A legitimate role change must still succeed.');
        $this->assertGreaterThan(0.5, $seconds, 'Role changes must wait for the concurrent one (serialized).');
        $this->assertSame([], $roleForA->fresh('permissions')->permissions->pluck('name')->all());
        $this->assertTrue($target->fresh()->hasRole($roleForB));
    }
}
