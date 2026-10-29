<?php

namespace Tests\Feature\Users;

use App\Models\User;
use App\Modules\AccessControl\Models\Role;
use App\Modules\Core\Audit\AuditLog;
use Illuminate\Testing\PendingCommand;
use Tests\Feature\ApiTestCase;

class SystemOwnerCommandsTest extends ApiTestCase
{
    private function createOwner(string $username, array $extra = []): PendingCommand
    {
        return $this->artisan('users:create-owner', ['--name' => 'Owner One', '--username' => $username, '--email' => "{$username}@example.com"] + $extra)
            ->expectsQuestion('Password', 'Owner-password-123')
            ->expectsQuestion('Confirm password', 'Owner-password-123');
    }

    public function test_bootstrap_creates_an_admin_owner_with_every_permission(): void
    {
        $this->createOwner('owner1')
            ->expectsConfirmation('Create system owner [owner1] with every permission?', 'yes')
            ->assertSuccessful();

        $owner = User::query()->where('username', 'owner1')->firstOrFail();
        $this->assertTrue($owner->isAdmin());
        $this->assertTrue($owner->hasRole(Role::SYSTEM_OWNER));
        $this->assertTrue($owner->hasPermissionTo('users.change-account-type'));
        $this->assertNotNull($owner->email_verified_at);

        $log = $this->assertAudited('owner.created', 'success', $owner->id);
        $this->assertSame('cli', $log->actor_type);
        $this->assertStringNotContainsString('Owner-password-123', json_encode(AuditLog::all()->toArray()));
    }

    public function test_bootstrap_is_not_repeatable_by_accident(): void
    {
        $this->owner();

        $this->artisan('users:create-owner', ['--username' => 'owner2'])
            ->expectsOutputToContain('A system owner already exists')
            ->assertFailed();
        $this->assertDatabaseMissing('users', ['username' => 'owner2']);

        $this->createOwner('owner2', ['--additional' => true])
            ->expectsConfirmation('Create system owner [owner2] with every permission?', 'yes')
            ->assertSuccessful();
    }

    public function test_bootstrap_aborts_without_confirmation_and_validates_input(): void
    {
        $this->createOwner('owner1')
            ->expectsConfirmation('Create system owner [owner1] with every permission?', 'no')
            ->assertFailed();
        $this->assertDatabaseCount('users', 0);

        $this->artisan('users:create-owner', ['--name' => 'X Y', '--username' => 'owner1', '--email' => 'o@example.com'])
            ->expectsQuestion('Password', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_grant_and_revoke_owner_protect_the_last_owner(): void
    {
        $first = $this->owner();
        $admin = User::factory()->admin()->create(['username' => 'admin2']);
        $writer = User::factory()->writer()->create(['username' => 'writer2']);

        $this->artisan('users:grant-owner', ['identifier' => 'writer2'])
            ->expectsConfirmation('Grant system-owner (every permission) to [writer2]?', 'yes')
            ->assertFailed();
        $this->assertFalse($writer->fresh()->hasRole(Role::SYSTEM_OWNER));

        $this->artisan('users:revoke-owner', ['identifier' => $first->username])
            ->expectsConfirmation("Revoke system-owner from [{$first->username}]?", 'yes')
            ->assertFailed();
        $this->assertTrue($first->fresh()->hasRole(Role::SYSTEM_OWNER));

        $this->artisan('users:grant-owner', ['identifier' => 'admin2'])
            ->expectsConfirmation('Grant system-owner (every permission) to [admin2]?', 'yes')
            ->assertSuccessful();
        $this->artisan('users:revoke-owner', ['identifier' => $first->username])
            ->expectsConfirmation("Revoke system-owner from [{$first->username}]?", 'yes')
            ->assertSuccessful();

        $this->assertTrue($admin->fresh()->hasRole(Role::SYSTEM_OWNER));
        $this->assertFalse($first->fresh()->hasRole(Role::SYSTEM_OWNER));
        $this->assertAudited('owner.revoked', 'success', $first->id);
    }

    public function test_there_is_no_http_route_to_create_or_grant_owners(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->implode("\n");

        $this->assertStringNotContainsString('owner', $routes);
    }
}
