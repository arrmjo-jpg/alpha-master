<?php

namespace App\Modules\Users\Console;

use App\Modules\Users\Actions\ManageSystemOwners;
use App\Modules\Users\Rules\ValidUsername;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

/**
 * Bootstraps the first administrator (approved decision D1).
 * The password is read from a hidden prompt — never from arguments or env.
 */
class CreateOwnerCommand extends Command
{
    protected $signature = 'users:create-owner
        {--name= : Full name}
        {--username= : Username}
        {--email= : Email address}
        {--additional : Allow creating another owner when one already exists}';

    protected $description = 'Create a system-owner administrator (server access required; there is no HTTP equivalent)';

    public function handle(ManageSystemOwners $owners): int
    {
        if (! $this->option('additional') && $owners->ownersExist()) {
            $this->components->error('A system owner already exists. Nothing was changed.');

            return self::FAILURE;
        }

        $data = [
            'name' => $this->option('name') ?? $this->ask('Full name'),
            'username' => mb_strtolower(trim((string) ($this->option('username') ?? $this->ask('Username')))),
            'email' => mb_strtolower(trim((string) ($this->option('email') ?? $this->ask('Email')))),
            'password' => $this->secret('Password'),
            'password_confirmation' => $this->secret('Confirm password'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'username' => ['required', 'string', new ValidUsername, Rule::unique('users', 'username')],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        if (! $this->confirm("Create system owner [{$data['username']}] with every permission?", false)) {
            $this->components->warn('Aborted. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $user = $owners->createOwner($validator->safe()->only(['name', 'username', 'email', 'password']), (bool) $this->option('additional'));
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("System owner [{$user->username}] created (id {$user->id}).");

        return self::SUCCESS;
    }
}
