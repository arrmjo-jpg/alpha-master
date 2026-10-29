<?php

namespace App\Modules\Users\Actions;

use App\Models\User;
use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Audit\GuardedOperation;
use App\Modules\Users\Enums\AccountType;
use App\Modules\Users\Events\CredentialsRevocationRequested;
use App\Modules\Users\Support\AccountGuard;

/**
 * The only way to change account_type (ADR-0002 §2). Leaving `admin` removes all
 * roles (roles are admin-only) and is protected by the last-admin invariant.
 */
class ChangeAccountType
{
    public function __construct(
        private readonly GuardedOperation $operation,
        private readonly AuditLogger $audit,
        private readonly AccountGuard $guard,
    ) {}

    public function __invoke(User $actor, User $target, AccountType $type): User
    {
        $from = $target->account_type;

        return $this->operation->run('user.account_type_changed', $target, ['from' => $from->value, 'to' => $type->value], function () use ($actor, $target, $type, $from) {
            $this->guard->assertCanAdminister($actor, $target);

            if ($from === $type) {
                return $target;
            }

            $removedRoles = [];
            if ($from === AccountType::Admin) {
                $this->guard->assertAnotherUsableAdminRemains($target);
                $removedRoles = $target->roles->pluck('name')->all();
                $target->syncRoles([]);
            }

            $target->forceFill(['account_type' => $type])->save();
            CredentialsRevocationRequested::dispatch($target, 'account_type_changed');

            $this->audit->record('user.account_type_changed', $target, [
                'from' => $from->value,
                'to' => $type->value,
                'roles_removed' => $removedRoles,
            ]);

            return $target;
        });
    }
}
