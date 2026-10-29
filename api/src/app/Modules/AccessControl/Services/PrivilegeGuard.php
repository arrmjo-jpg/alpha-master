<?php

namespace App\Modules\AccessControl\Services;

use App\Models\User;
use App\Modules\AccessControl\Models\Role;
use App\Modules\Core\Errors\DomainError;
use App\Modules\Core\Errors\ErrorCode;
use Illuminate\Support\Collection;

/**
 * Anti-escalation rules (ADR-0004 §5):
 *  - grant ceiling: you can only grant or remove permissions you hold yourself;
 *  - manageability: you can only act on accounts whose permissions are a subset of yours.
 */
class PrivilegeGuard
{
    /** @return Collection<int, string> */
    public function permissionNames(User $user): Collection
    {
        return $user->getAllPermissions()->pluck('name')->unique()->values();
    }

    public function isOwner(User $user): bool
    {
        return $user->hasRole(Role::SYSTEM_OWNER);
    }

    /**
     * @param  iterable<string>  $permissionNames
     */
    public function assertHoldsAll(User $actor, iterable $permissionNames): void
    {
        $missing = collect($permissionNames)->diff($this->permissionNames($actor));

        if ($missing->isNotEmpty()) {
            throw DomainError::because(ErrorCode::GrantCeilingExceeded);
        }
    }

    public function canManage(User $actor, User $target): bool
    {
        return $this->permissionNames($target)->diff($this->permissionNames($actor))->isEmpty();
    }

    public function assertCanManage(User $actor, User $target): void
    {
        if (! $this->canManage($actor, $target)) {
            throw DomainError::because(ErrorCode::TargetNotManageable);
        }
    }

    /**
     * @param  iterable<Role>  $roles
     * @return Collection<int, string>
     */
    public function permissionsOfRoles(iterable $roles): Collection
    {
        return collect($roles)->flatMap(fn (Role $role) => $role->permissions->pluck('name'))->unique()->values();
    }
}
