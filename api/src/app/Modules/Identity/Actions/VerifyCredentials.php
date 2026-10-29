<?php

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Audit\AuditOutcome;
use App\Modules\Core\Errors\DomainError;
use App\Modules\Core\Errors\ErrorCode;
use Illuminate\Support\Facades\Hash;

/**
 * Resolves an email-or-username identifier and checks the password (ADR-0003 §2).
 * Unknown user and wrong password are indistinguishable (same code, comparable
 * hashing cost). Status is only revealed after a correct password.
 */
class VerifyCredentials
{
    private static ?string $dummyHash = null;

    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(string $identifier, string $password): User
    {
        $identifier = mb_strtolower(trim($identifier));
        $column = str_contains($identifier, '@') ? 'email' : 'username';
        $user = User::query()->where($column, $identifier)->first();

        if (! $user) {
            Hash::check($password, self::$dummyHash ??= Hash::make('alphamaster-timing-equalizer'));
            $this->audit->record('auth.login_failed', null, ['identifier_type' => $column, 'reason' => 'unknown_identifier'], AuditOutcome::Failed);

            throw DomainError::because(ErrorCode::InvalidCredentials);
        }

        if (! Hash::check($password, $user->password)) {
            $this->audit->record('auth.login_failed', $user, ['identifier_type' => $column, 'reason' => 'wrong_password'], AuditOutcome::Failed, actorType: 'anonymous');

            throw DomainError::because(ErrorCode::InvalidCredentials);
        }

        if (! $user->isUsable()) {
            $code = $user->isBanned() ? ErrorCode::AccountBanned : ErrorCode::AccountInactive;
            $this->audit->record('auth.login_denied', $user, ['reason' => $code->value], AuditOutcome::Denied, actorType: 'anonymous');

            throw DomainError::because($code);
        }

        return $user;
    }
}
