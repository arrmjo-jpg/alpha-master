<?php

namespace App\Modules\Users\Actions;

use App\Models\User;
use App\Modules\AccessControl\Services\PrivilegeGuard;
use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Audit\GuardedOperation;
use App\Modules\Core\Errors\DomainError;
use App\Modules\Core\Errors\ErrorCode;

/** Create and edit accounts. Never touches account_type, status, roles or passwords of others. */
class ManageUsers
{
    public function __construct(
        private readonly GuardedOperation $operation,
        private readonly AuditLogger $audit,
        private readonly PrivilegeGuard $privileges,
    ) {}

    /**
     * New accounts are always type `user`; promotion is a separate, audited action.
     *
     * @param  array{name: string, username: string, email: string, password: string, preferred_locale?: string|null}  $data
     */
    public function create(User $actor, array $data): User
    {
        return $this->operation->run('user.created', null, [], function () use ($data) {
            $user = new User;
            $user->fill($data);
            $user->save();

            $this->audit->record('user.created', $user, ['account_type' => $user->account_type->value]);

            return $user;
        });
    }

    /**
     * @param  array{name?: string, username?: string, email?: string, preferred_locale?: string|null}  $data
     */
    public function update(User $actor, User $target, array $data): User
    {
        $fields = array_keys($data);

        return $this->operation->run('user.updated', $target, ['fields' => $fields], function () use ($actor, $target, $data, $fields) {
            if (! $actor->is($target)) {
                if ($this->privileges->isOwner($target)) {
                    throw DomainError::because(ErrorCode::OwnerProtected);
                }
                $this->privileges->assertCanManage($actor, $target);
            }

            $target->fill(array_intersect_key($data, array_flip(['name', 'username', 'email', 'preferred_locale'])));

            if ($target->isDirty('email')) {
                $target->email_verified_at = null;
            }

            $changed = array_keys($target->getDirty());
            $target->save();

            if ($changed !== []) {
                $this->audit->record('user.updated', $target, ['fields' => array_values(array_intersect($fields, $changed))]);
            }

            return $target;
        });
    }
}
