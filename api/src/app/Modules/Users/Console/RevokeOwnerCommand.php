<?php

namespace App\Modules\Users\Console;

use App\Models\User;
use App\Modules\Core\Errors\DomainError;
use App\Modules\Users\Actions\ManageSystemOwners;
use Illuminate\Console\Command;

class RevokeOwnerCommand extends Command
{
    protected $signature = 'users:revoke-owner {identifier : Username or email}';

    protected $description = 'Revoke the system-owner role (refused for the last active owner)';

    public function handle(ManageSystemOwners $owners): int
    {
        $identifier = mb_strtolower(trim($this->argument('identifier')));
        $user = User::query()->where(str_contains($identifier, '@') ? 'email' : 'username', $identifier)->first();

        if (! $user) {
            $this->components->error('No such user.');

            return self::FAILURE;
        }

        if (! $this->confirm("Revoke system-owner from [{$user->username}]?", false)) {
            $this->components->warn('Aborted. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $owners->revoke($user);
        } catch (DomainError $e) {
            $this->components->error($e->errorCode->title());

            return self::FAILURE;
        }

        $this->components->info("System-owner revoked from [{$user->username}].");

        return self::SUCCESS;
    }
}
