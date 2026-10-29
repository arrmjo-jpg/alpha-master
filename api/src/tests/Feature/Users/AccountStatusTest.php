<?php

namespace Tests\Feature\Users;

use App\Models\User;
use App\Modules\Core\Errors\DomainError;
use App\Modules\Core\Errors\ErrorCode;
use App\Modules\Users\Support\AccountGuard;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ApiTestCase;

class AccountStatusTest extends ApiTestCase
{
    public function test_ban_revokes_all_tokens_and_database_sessions_and_hides_reason_from_audit(): void
    {
        $target = User::factory()->create();
        $target->createToken('phone', ['client:public']);
        $target->createToken('tablet', ['client:public']);
        DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $target->id, 'payload' => 'x', 'last_activity' => time()]);
        config(['session.driver' => 'database']);

        $this->actingAsAdminClient($this->adminWith('users.ban', 'users.unban'));

        $this->postJson("/api/v1/admin/users/{$target->id}/ban", ['reason' => 'Spam campaign'])->assertOk()
            ->assertJsonPath('data.status.state', 'banned')
            ->assertJsonPath('data.status.ban_reason', 'Spam campaign');

        $this->assertSame(0, $target->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $log = $this->assertAudited('user.banned', 'success', $target->id);
        $this->assertStringNotContainsString('Spam campaign', json_encode($log->context));

        $this->postJson("/api/v1/admin/users/{$target->id}/unban")->assertOk()->assertJsonPath('data.status.state', 'active');
        $this->assertNull($target->fresh()->ban_reason);
    }

    public function test_ban_requires_a_reason(): void
    {
        $target = User::factory()->create();
        $this->actingAsAdminClient($this->adminWith('users.ban'));

        $this->assertProblem($this->postJson("/api/v1/admin/users/{$target->id}/ban", []), 422, 'VALIDATION_ERROR');
        $this->assertNull($target->fresh()->banned_at);
    }

    public function test_deactivate_and_activate(): void
    {
        $target = User::factory()->create(['username' => 'sara']);
        $token = $target->createToken('phone', ['client:public'])->plainTextToken;
        $this->actingAsAdminClient($this->adminWith('users.activate', 'users.deactivate'));

        $this->postJson("/api/v1/admin/users/{$target->id}/deactivate")->assertOk()->assertJsonPath('data.status.state', 'deactivated');
        $this->assertSame(0, $target->tokens()->count());
        $this->assertAudited('user.deactivated', 'success', $target->id);

        $this->app['auth']->forgetGuards();
        $this->assertProblem($this->withToken($token)->getJson('/api/v1/auth/me'), 401, 'UNAUTHENTICATED');
        $this->assertProblem($this->postJson('/api/v1/auth/tokens', [
            'identifier' => 'sara', 'password' => 'password', 'device_name' => 'p', 'client' => 'public',
        ]), 403, 'ACCOUNT_INACTIVE');

        $this->actingAsAdminClient($this->adminWith('users.activate'));
        $this->postJson("/api/v1/admin/users/{$target->id}/activate")->assertOk()->assertJsonPath('data.status.state', 'active');
        $this->assertAudited('user.activated', 'success', $target->id);
    }

    public function test_admin_cannot_ban_or_deactivate_themselves(): void
    {
        $admin = $this->adminWith('users.ban', 'users.deactivate', 'users.revoke-sessions');
        $this->actingAsAdminClient($admin);

        $this->assertProblem($this->postJson("/api/v1/admin/users/{$admin->id}/ban", ['reason' => 'test']), 403, 'SELF_MODIFICATION_FORBIDDEN');
        $this->assertProblem($this->postJson("/api/v1/admin/users/{$admin->id}/deactivate"), 403, 'SELF_MODIFICATION_FORBIDDEN');
        $this->assertProblem($this->deleteJson("/api/v1/admin/users/{$admin->id}/sessions"), 403, 'SELF_MODIFICATION_FORBIDDEN');
        $this->assertTrue($admin->fresh()->isUsable());
    }

    public function test_system_owner_status_cannot_be_changed_through_the_api(): void
    {
        $owner = $this->owner();
        $this->actingAsAdminClient($this->owner());

        $this->assertProblem($this->postJson("/api/v1/admin/users/{$owner->id}/ban", ['reason' => 'test']), 403, 'OWNER_PROTECTED');
        $this->assertProblem($this->postJson("/api/v1/admin/users/{$owner->id}/deactivate"), 403, 'OWNER_PROTECTED');
    }

    public function test_admin_revokes_another_users_sessions(): void
    {
        $target = User::factory()->create();
        $target->createToken('phone', ['client:public']);
        $this->actingAsAdminClient($this->adminWith('users.revoke-sessions'));

        $this->deleteJson("/api/v1/admin/users/{$target->id}/sessions")->assertNoContent();
        $this->assertSame(0, $target->tokens()->count());
        $this->assertAudited('user.sessions_revoked', 'success', $target->id);
    }

    public function test_banning_an_admin_requires_another_usable_admin(): void
    {
        // Direct call: the API actor is always a usable admin, so this guards
        // the invariant itself (concurrency is covered in LastAdminConcurrencyTest).
        $only = User::factory()->admin()->create();
        $guard = app(AccountGuard::class);

        $this->expectExceptionObject(DomainError::because(ErrorCode::LastAdminProtected));
        DB::transaction(fn () => $guard->assertAnotherUsableAdminRemains($only));
    }
}
