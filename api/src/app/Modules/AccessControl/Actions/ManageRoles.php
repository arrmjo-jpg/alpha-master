<?php

namespace App\Modules\AccessControl\Actions;

use App\Models\User;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\AccessControl\Models\Role;
use App\Modules\AccessControl\Services\PrivilegeGuard;
use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Audit\GuardedOperation;
use App\Modules\Core\Errors\DomainError;
use App\Modules\Core\Errors\ErrorCode;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/** Role use cases. Protected roles (system-owner) are never changed here. */
class ManageRoles
{
    public function __construct(
        private readonly GuardedOperation $operation,
        private readonly AuditLogger $audit,
        private readonly PrivilegeGuard $guard,
    ) {}

    /**
     * @param  array{name: string, display_name: array<string, string>, description?: array<string, string>|null}  $data
     */
    public function create(User $actor, array $data): Role
    {
        return $this->operation->run('role.created', null, ['name' => $data['name']], function () use ($data) {
            $role = Role::query()->create([
                'name' => $data['name'],
                'guard_name' => 'web',
                'display_name' => $data['display_name'],
                'description' => $data['description'] ?? null,
                'is_protected' => false,
            ]);
            $this->audit->record('role.created', $role, ['name' => $role->name]);

            return $role;
        });
    }

    /**
     * @param  array{display_name?: array<string, string>, description?: array<string, string>|null}  $data
     */
    public function update(User $actor, Role $role, array $data): Role
    {
        return $this->operation->run('role.updated', $role, [], function () use ($role, $data) {
            $this->assertNotProtected($role);
            $role->fill(array_intersect_key($data, array_flip(['display_name', 'description'])))->save();
            $this->audit->record('role.updated', $role, ['fields' => array_keys($data)]);

            return $role;
        });
    }

    public function delete(User $actor, Role $role): void
    {
        $this->operation->run('role.deleted', $role, ['name' => $role->name], function () use ($actor, $role) {
            $this->assertNotProtected($role);
            // Deleting a role removes its permissions from its holders: only within your own ceiling.
            $this->guard->assertHoldsAll($actor, $role->permissions->pluck('name'));
            $this->assertCanManageHolders($actor, $role);

            $holders = $role->users()->pluck('id')->all();
            $role->delete();
            $this->forgetPermissionCacheAfterCommit();
            $this->audit->record('role.deleted', $role, ['name' => $role->name, 'affected_user_ids' => $holders]);
        });
    }

    /**
     * Replaces the role's permissions. Added and removed permissions must both
     * be held by the actor (grant ceiling); deprecated permissions cannot be added.
     *
     * @param  list<string>  $permissionNames
     */
    public function syncPermissions(User $actor, Role $role, array $permissionNames): Role
    {
        return $this->operation->run('role.permissions_synced', $role, [], function () use ($actor, $role, $permissionNames) {
            $this->assertNotProtected($role);

            $current = $role->permissions->pluck('name');
            $requested = collect($permissionNames)->unique()->values();
            $added = $requested->diff($current)->values();
            $removed = $current->diff($requested)->values();

            $this->guard->assertHoldsAll($actor, $added->merge($removed));
            $this->assertCanManageHolders($actor, $role);

            $permissions = Permission::query()->whereIn('name', $requested)->where('is_deprecated', false)->get();
            if ($permissions->count() !== $requested->count()) {
                throw DomainError::because(ErrorCode::Conflict);
            }

            $role->syncPermissions($permissions);
            $this->forgetPermissionCacheAfterCommit();
            $this->audit->record('role.permissions_synced', $role, [
                'added' => $added->all(),
                'removed' => $removed->all(),
            ]);

            return $role->load('permissions');
        });
    }

    /**
     * Changing a role changes every holder's privileges, so the actor must be
     * able to manage each holder directly (same rule as acting on the account).
     */
    private function assertCanManageHolders(User $actor, Role $role): void
    {
        foreach ($role->users()->get() as $holder) {
            $this->guard->assertCanManage($actor, $holder);
        }
    }

    /**
     * Spatie clears its permission cache inside the transaction; a concurrent
     * request could rebuild it from the old committed rows before we commit.
     * Clearing again after commit guarantees the cache reflects the new state.
     */
    private function forgetPermissionCacheAfterCommit(): void
    {
        DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
    }

    private function assertNotProtected(Role $role): void
    {
        if ($role->is_protected) {
            throw DomainError::because(ErrorCode::ProtectedRole);
        }
    }
}
