<?php

namespace App\Modules\Users\Support;

use App\Models\User;
use App\Modules\AccessControl\Services\PrivilegeGuard;
use App\Modules\Core\Errors\DomainError;
use App\Modules\Core\Errors\ErrorCode;
use App\Modules\Users\Enums\AccountType;

/** Shared preconditions for admin actions on another account (ADR-0002). */
class AccountGuard
{
    public function __construct(private readonly PrivilegeGuard $privileges) {}

    /**
     * Not yourself, not a system owner (owners are CLI-managed), and only
     * accounts whose permissions are a subset of yours.
     */
    public function assertCanAdminister(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw DomainError::because(ErrorCode::SelfModificationForbidden);
        }

        if ($this->privileges->isOwner($target)) {
            throw DomainError::because(ErrorCode::OwnerProtected);
        }

        $this->privileges->assertCanManage($actor, $target);
    }

    /**
     * Must run inside a transaction. Locks every admin row (SELECT … FOR UPDATE)
     * so concurrent demotions/bans/deactivations serialize, then requires at
     * least one other usable admin to remain.
     */
    public function assertAnotherUsableAdminRemains(User $target): void
    {
        $admins = User::query()
            ->where('account_type', AccountType::Admin)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'deactivated_at', 'banned_at']);

        $remaining = $admins->filter(fn (User $admin) => $admin->id !== $target->id && $admin->isUsable());

        if ($remaining->isEmpty()) {
            throw DomainError::because(ErrorCode::LastAdminProtected);
        }
    }
}
