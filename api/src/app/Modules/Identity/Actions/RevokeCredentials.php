<?php

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Users\Events\CredentialsRevocationRequested;
use Illuminate\Support\Facades\DB;

/**
 * Deletes every personal access token and every database session of a user.
 * Runs synchronously inside the caller's transaction.
 */
class RevokeCredentials
{
    /** @return array{tokens: int, sessions: int} */
    public function __invoke(User $user): array
    {
        $tokens = $user->tokens()->delete();

        $sessions = config('session.driver') === 'database'
            ? DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete()
            : 0;

        return ['tokens' => $tokens, 'sessions' => $sessions];
    }

    /** Listener for the Users module event. */
    public function handle(CredentialsRevocationRequested $event): void
    {
        $this($event->user);
    }
}
