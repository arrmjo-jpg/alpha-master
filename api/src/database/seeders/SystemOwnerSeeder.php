<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Users\Actions\ManageSystemOwners;
use App\Modules\Users\Rules\ValidUsername;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Creates a system owner from OWNER_NAME / OWNER_USERNAME / OWNER_EMAIL / OWNER_PASSWORD
 * (config/seeding.php). Same rules and action as `users:create-owner`, without the prompts.
 *
 * Not part of DatabaseSeeder: run it on purpose with
 *   php artisan db:seed --class=SystemOwnerSeeder --force
 *
 * Idempotent: an existing account with that username or email is left untouched
 * (its password is never reset by seeding).
 */
class SystemOwnerSeeder extends Seeder
{
    public function run(ManageSystemOwners $owners): void
    {
        $config = config('seeding.owner');
        $data = [
            'name' => trim((string) ($config['name'] ?? '')),
            'username' => mb_strtolower(trim((string) ($config['username'] ?? ''))),
            'email' => mb_strtolower(trim((string) ($config['email'] ?? ''))),
            'password' => (string) ($config['password'] ?? ''),
        ];

        if (in_array('', $data, true)) {
            $this->command?->warn('SystemOwnerSeeder skipped: set OWNER_NAME, OWNER_USERNAME, OWNER_EMAIL and OWNER_PASSWORD.');

            return;
        }

        $existing = User::query()->where('username', $data['username'])->orWhere('email', $data['email'])->first();
        if ($existing) {
            $this->command?->info("SystemOwnerSeeder: account [{$existing->username}] already exists. Nothing was changed.");

            return;
        }

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'username' => ['required', 'string', new ValidUsername, Rule::unique('users', 'username')],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults()],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        // A second owner is deliberate here: the variables name exactly who to create.
        $user = $owners->createOwner($validator->validated(), additional: $owners->ownersExist());

        $this->command?->info("SystemOwnerSeeder: system owner [{$user->username}] created (id {$user->id}).");
    }
}
