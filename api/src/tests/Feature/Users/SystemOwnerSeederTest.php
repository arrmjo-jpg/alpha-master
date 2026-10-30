<?php

namespace Tests\Feature\Users;

use App\Models\User;
use App\Modules\AccessControl\Models\Role;
use Database\Seeders\SystemOwnerSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\Feature\ApiTestCase;

class SystemOwnerSeederTest extends ApiTestCase
{
    private function configureOwner(array $overrides = []): void
    {
        config(['seeding.owner' => array_merge([
            'name' => 'Seeded Owner',
            'username' => 'Seeded.Owner',
            'email' => 'Seeded.Owner@example.test',
            'password' => 'Seeded-owner-123',
        ], $overrides)]);
    }

    public function test_creates_a_verified_system_owner_from_configuration(): void
    {
        $this->configureOwner();

        $this->seed(SystemOwnerSeeder::class);

        $owner = User::query()->where('username', 'seeded.owner')->firstOrFail();
        $this->assertSame('seeded.owner@example.test', $owner->email);
        $this->assertTrue($owner->isAdmin());
        $this->assertNotNull($owner->email_verified_at);
        $this->assertTrue($owner->hasRole(Role::SYSTEM_OWNER));
        $this->assertTrue(Hash::check('Seeded-owner-123', $owner->password));
        $this->assertAudited('owner.created');
    }

    public function test_is_idempotent_and_never_resets_an_existing_password(): void
    {
        $this->configureOwner();
        $this->seed(SystemOwnerSeeder::class);

        $this->configureOwner(['password' => 'Another-password-456']);
        $this->seed(SystemOwnerSeeder::class);

        $this->assertSame(1, User::query()->where('username', 'seeded.owner')->count());
        $this->assertTrue(Hash::check('Seeded-owner-123', User::query()->where('username', 'seeded.owner')->value('password')));
    }

    public function test_adds_a_second_owner_when_one_already_exists(): void
    {
        $this->owner();
        $this->configureOwner();

        $this->seed(SystemOwnerSeeder::class);

        $this->assertSame(2, User::role(Role::SYSTEM_OWNER)->count());
    }

    public function test_skips_when_not_configured(): void
    {
        config(['seeding.owner' => ['name' => null, 'username' => null, 'email' => null, 'password' => null]]);

        $this->seed(SystemOwnerSeeder::class);

        $this->assertSame(0, User::role(Role::SYSTEM_OWNER)->count());
    }

    public function test_rejects_a_password_that_breaks_the_password_policy(): void
    {
        $this->configureOwner(['password' => '0102030405']);

        try {
            $this->seed(SystemOwnerSeeder::class);
            $this->fail('A weak password must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
        }

        $this->assertDatabaseMissing('users', ['username' => 'seeded.owner']);
    }
}
