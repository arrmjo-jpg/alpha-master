<?php

namespace App\Modules\Users\Console;

use App\Models\User;
use App\Modules\Core\Errors\DomainError;
use App\Modules\Users\Actions\ManageSystemOwners;
use Illuminate\Console\Command;

class GrantOwnerCommand extends Command
{
    protected $signature = 'users:grant-owner {identifier : Username or email of an active admin account}';

    protected $description = 'Grant the protected system-owner role to an existing active admin';

    public function handle(ManageSystemOwners $owners): int
    {
        $identifier = mb_strtolower(trim($this->argument('identifier')));
        $user = User::query()->where(str_contains($identifier, '@') ? 'email' : 'username', $identifier)->first();

        if (! $user) {
            $this->components->error('No such user.');

            return self::FAILURE;
        }

        if (! $this->confirm("Grant system-owner (every permission) to [{$user->username}]?", false)) {
            $this->components->warn('Aborted. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $owners->grant($user);
        } catch (DomainError $e) {
            $this->components->error($e->errorCode->title());

            return self::FAILURE;
        }

        $this->components->info("[{$user->username}] is now a system owner.");

        return self::SUCCESS;
    }
}
