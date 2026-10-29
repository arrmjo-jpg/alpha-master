<?php

namespace App\Modules\Users\Actions;

use App\Models\User;
use App\Modules\AccessControl\Models\Role;
use App\Modules\AccessControl\Services\PermissionSynchronizer;
use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Errors\DomainError;
use App\Modules\Core\Errors\ErrorCode;
use App\Modules\Users\Enums\AccountType;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * System-owner lifecycle (approved decision D1). Reachable only from the CLI
 * commands — there is no HTTP route to any of these methods.
 */
class ManageSystemOwners
{
    public function __construct(
        private readonly PermissionSynchronizer $synchronizer,
        private readonly AuditLogger $audit,
    ) {}

    public function ownersExist(): bool
    {
        return User::role(Role::SYSTEM_OWNER)->usable()->exists();
    }

    /**
     * @param  array{name: string, username: string, email: string, password: string}  $data
     */
    public function createOwner(array $data, bool $additional): User
    {
        $this->synchronizer->sync();

        return DB::transaction(function () use ($data, $additional) {
            // Serialize concurrent bootstraps on the owner role row.
            Role::query()->where('name', Role::SYSTEM_OWNER)->lockForUpdate()->first();

            if (! $additional && $this->ownersExist()) {
                throw new RuntimeException('A system owner already exists. Use --additional to create another one deliberately.');
            }

            $user = new User;
            $user->fill($data);
            $user->forceFill(['account_type' => AccountType::Admin, 'email_verified_at' => now()]);
            $user->save();
            $user->assignRole(Role::SYSTEM_OWNER);

            $this->audit->record('owner.created', $user, ['additional' => $additional], actorType: 'cli');

            return $user;
        });
    }

    public function grant(User $user): User
    {
        $this->synchronizer->sync();

        return DB::transaction(function () use ($user) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            if (! $user->isAdmin() || ! $user->isUsable()) {
                throw DomainError::because(ErrorCode::RolesRequireAdminAccount);
            }

            if (! $user->hasRole(Role::SYSTEM_OWNER)) {
                $user->assignRole(Role::SYSTEM_OWNER);
                $this->audit->record('owner.granted', $user, actorType: 'cli');
            }

            return $user;
        });
    }

    public function revoke(User $user): User
    {
        return DB::transaction(function () use ($user) {
            $owners = User::role(Role::SYSTEM_OWNER)->orderBy('id')->lockForUpdate()->get();

            if (! $owners->contains('id', $user->id)) {
                return $user;
            }

            if ($owners->filter(fn (User $owner) => $owner->id !== $user->id && $owner->isUsable())->isEmpty()) {
                throw DomainError::because(ErrorCode::LastAdminProtected);
            }

            $user->removeRole(Role::SYSTEM_OWNER);
            $this->audit->record('owner.revoked', $user, actorType: 'cli');

            return $user;
        });
    }
}
