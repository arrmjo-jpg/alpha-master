<?php

namespace App\Modules\Users\Actions;

use App\Models\User;
use App\Modules\AccessControl\Models\Role;
use App\Modules\AccessControl\Services\PrivilegeGuard;
use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Audit\GuardedOperation;
use App\Modules\Core\Errors\DomainError;
use App\Modules\Core\Errors\ErrorCode;
use App\Modules\Users\Support\AccountGuard;

/**
 * Replaces an admin account's roles. Protected roles (system-owner) are never
 * assignable here, and every added or removed role must sit within the actor's
 * own permissions (grant ceiling).
 */
class SyncUserRoles
{
    public function __construct(
        private readonly GuardedOperation $operation,
        private readonly AuditLogger $audit,
        private readonly AccountGuard $guard,
        private readonly PrivilegeGuard $privileges,
    ) {}

    /**
     * @param  list<int>  $roleIds
     */
    public function __invoke(User $actor, User $target, array $roleIds): User
    {
        return $this->operation->run('user.roles_synced', $target, ['role_ids' => $roleIds], function () use ($actor, $target, $roleIds) {
            $this->guard->assertCanAdminister($actor, $target);

            if (! $target->isAdmin()) {
                throw DomainError::because(ErrorCode::RolesRequireAdminAccount);
            }

            $requested = Role::query()->with('permissions')->whereIn('id', $roleIds)->get();
            if ($requested->contains(fn (Role $role) => $role->is_protected)) {
                throw DomainError::because(ErrorCode::ProtectedRole);
            }

            $current = $target->roles()->with('permissions')->get();
            $added = $requested->whereNotIn('id', $current->pluck('id'));
            $removed = $current->whereNotIn('id', $requested->pluck('id'));

            if ($removed->contains(fn (Role $role) => $role->is_protected)) {
                throw DomainError::because(ErrorCode::ProtectedRole);
            }

            $this->privileges->assertHoldsAll($actor, $this->privileges->permissionsOfRoles($added->merge($removed)));

            $target->syncRoles($requested);
            $this->audit->record('user.roles_synced', $target, [
                'added' => $added->pluck('name')->values()->all(),
                'removed' => $removed->pluck('name')->values()->all(),
            ]);

            return $target->load('roles');
        });
    }
}
