<?php

namespace App\Modules\Users\Actions;

use App\Models\User;
use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Audit\GuardedOperation;
use App\Modules\Users\Events\CredentialsRevocationRequested;
use App\Modules\Users\Support\AccountGuard;

/**
 * Activation and banning are independent states (ADR-0002 §4). Deactivating or
 * banning revokes every token and session in the same transaction.
 */
class ManageAccountStatus
{
    public function __construct(
        private readonly GuardedOperation $operation,
        private readonly AuditLogger $audit,
        private readonly AccountGuard $guard,
    ) {}

    public function deactivate(User $actor, User $target): User
    {
        return $this->operation->run('user.deactivated', $target, [], function () use ($actor, $target) {
            $this->guard->assertCanAdminister($actor, $target);
            if ($target->isDeactivated()) {
                return $target;
            }
            if ($target->isAdmin()) {
                $this->guard->assertAnotherUsableAdminRemains($target);
            }

            $target->forceFill(['deactivated_at' => now(), 'deactivated_by' => $actor->id])->save();
            CredentialsRevocationRequested::dispatch($target, 'deactivated');
            $this->audit->record('user.deactivated', $target);

            return $target;
        });
    }

    public function activate(User $actor, User $target): User
    {
        return $this->operation->run('user.activated', $target, [], function () use ($actor, $target) {
            $this->guard->assertCanAdminister($actor, $target);
            if (! $target->isDeactivated()) {
                return $target;
            }

            $target->forceFill(['deactivated_at' => null, 'deactivated_by' => null])->save();
            $this->audit->record('user.activated', $target);

            return $target;
        });
    }

    public function ban(User $actor, User $target, string $reason): User
    {
        return $this->operation->run('user.banned', $target, [], function () use ($actor, $target, $reason) {
            $this->guard->assertCanAdminister($actor, $target);
            if ($target->isAdmin() && ! $target->isBanned()) {
                $this->guard->assertAnotherUsableAdminRemains($target);
            }

            $target->forceFill(['banned_at' => now(), 'banned_by' => $actor->id, 'ban_reason' => $reason])->save();
            CredentialsRevocationRequested::dispatch($target, 'banned');
            // The reason is internal; the audit row records that one was given, not its text.
            $this->audit->record('user.banned', $target);

            return $target;
        });
    }

    public function unban(User $actor, User $target): User
    {
        return $this->operation->run('user.unbanned', $target, [], function () use ($actor, $target) {
            $this->guard->assertCanAdminister($actor, $target);
            if (! $target->isBanned()) {
                return $target;
            }

            $target->forceFill(['banned_at' => null, 'banned_by' => null, 'ban_reason' => null])->save();
            $this->audit->record('user.unbanned', $target);

            return $target;
        });
    }

    public function revokeSessions(User $actor, User $target): void
    {
        $this->operation->run('user.sessions_revoked', $target, [], function () use ($actor, $target) {
            $this->guard->assertCanAdminister($actor, $target);
            CredentialsRevocationRequested::dispatch($target, 'admin_revoked');
            $this->audit->record('user.sessions_revoked', $target);
        });
    }
}
