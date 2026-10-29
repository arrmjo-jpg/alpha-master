<?php

namespace App\Modules\Users\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised (synchronously, inside the same transaction) when a user's tokens and
 * sessions must be revoked: ban, deactivation, account-type change, admin revoke.
 * Identity listens; Users does not depend on Identity.
 */
class CredentialsRevocationRequested
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly string $reason,
    ) {}
}
