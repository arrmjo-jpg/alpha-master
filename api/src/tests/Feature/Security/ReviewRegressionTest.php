<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\AccessControl\Services\VersionedPermissionRegistrar;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Audit\AuditLogger;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Feature\ApiTestCase;

/** Regression tests for the findings of the independent Phase 1 review (M1–M4, L1). */
class ReviewRegressionTest extends ApiTestCase
{
    /** M1: editing or deleting a shared role must not weaken an account the actor cannot manage. */
    public function test_role_changes_require_managing_every_holder(): void
    {
        $shared = $this->roleWith('users.view');
        $strong = User::factory()->admin()->create();
        $strong->assignRole($shared, $this->roleWith('users.ban', 'roles.assign-permissions', 'roles.delete'));

        $this->actingAsAdminClient($this->adminWith('users.view', 'roles.assign-permissions', 'roles.delete'));

        $this->assertProblem($this->putJson("/api/v1/admin/access/roles/{$shared->id}/permissions", [
            'permissions' => [], 'current_password' => 'password',
        ]), 403, 'TARGET_NOT_MANAGEABLE');
        $this->assertProblem($this->deleteJson("/api/v1/admin/access/roles/{$shared->id}"), 403, 'TARGET_NOT_MANAGEABLE');

        $this->assertTrue($strong->fresh()->hasPermissionTo('users.view'));
        $this->assertDatabaseHas('roles', ['id' => $shared->id]);
        $this->assertAudited('role.permissions_synced', 'denied', $shared->id);
    }

    /** M2: the permission-cache version switches only after the change commits (never inside the transaction). */
    public function test_permission_cache_is_cleared_after_commit(): void
    {
        $role = $this->roleWith('users.view');
        $this->actingAsAdminClient($this->adminWith('users.view', 'roles.assign-permissions'));
        $baseLevel = DB::transactionLevel(); // RefreshDatabase wraps each test in one transaction

        $levels = [];
        Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$levels) {
            if ($event->key === VersionedPermissionRegistrar::VERSION_KEY) {
                $levels[] = DB::transactionLevel();
            }
        });

        $this->putJson("/api/v1/admin/access/roles/{$role->id}/permissions", [
            'permissions' => [], 'current_password' => 'password',
        ])->assertOk();

        $this->assertNotEmpty($levels, 'The cache version must change after a permission change.');
        $this->assertSame([$baseLevel], array_values(array_unique($levels)), 'The version may only change after the action transaction committed. Levels: '.json_encode($levels));
    }

    /** M3: password re-confirmation is rate-limited per user and failures are audited without the password. */
    public function test_password_reconfirmation_is_throttled_and_audited(): void
    {
        RateLimiter::clear('sensitive');
        $target = User::factory()->create();
        $this->actingAsAdminClient($this->adminWith('users.change-account-type'));

        for ($i = 0; $i < 5; $i++) {
            $this->assertProblem($this->putJson("/api/v1/admin/users/{$target->id}/account-type", [
                'account_type' => 'admin', 'current_password' => "Guess-{$i}-password",
            ]), 422, 'VALIDATION_ERROR');
        }

        $this->assertProblem($this->putJson("/api/v1/admin/users/{$target->id}/account-type", [
            'account_type' => 'admin', 'current_password' => 'password',
        ]), 429, 'RATE_LIMIT_EXCEEDED')->assertHeader('Retry-After');

        $this->assertSame(5, AuditLog::query()->where('event', 'auth.reconfirm_failed')->where('outcome', 'failed')->count());
        $this->assertStringNotContainsString('Guess-', json_encode(AuditLog::all()->toArray()));
        $this->assertSame('user', $target->fresh()->account_type->value);
    }

    /** M4: APP_DEBUG=true outside the local environment must not expose exception details. */
    public function test_debug_details_require_local_environment(): void
    {
        Route::middleware('api')->get('api/v1/_test/boom', fn () => throw new RuntimeException('internal secret detail'));
        config(['app.debug' => true]);

        $this->app['env'] = 'production';
        $this->assertProblem($this->getJson('/api/v1/_test/boom'), 500, 'INTERNAL_SERVER_ERROR')
            ->assertJsonMissingPath('debug');
        $this->assertStringNotContainsString('internal secret detail', $this->getJson('/api/v1/_test/boom')->getContent());

        $this->app['env'] = 'local';
        $this->getJson('/api/v1/_test/boom')->assertStatus(500)->assertJsonPath('debug.message', 'internal secret detail');
    }

    /** L1: audit keeps token references but never secret values. */
    public function test_audit_keeps_token_ids_but_strips_secrets(): void
    {
        User::factory()->create(['username' => 'sara']);
        $issued = $this->postJson('/api/v1/auth/tokens', [
            'identifier' => 'sara', 'password' => 'password', 'device_name' => 'phone', 'client' => 'public',
        ])->assertCreated();

        $log = $this->assertAudited('auth.token_issued');
        $this->assertIsInt($log->context['token_id']);
        $this->assertStringNotContainsString(explode('|', $issued->json('data.token'))[1], json_encode(AuditLog::all()->toArray()));

        $record = app(AuditLogger::class)->record('test.scrub', null, [
            'password' => 'p', 'current_password' => 'p', 'token' => 't', 'otp' => '1', 'secret' => 's',
            'token_id' => 7, 'tokens' => 2, 'nested' => ['access_token' => 'a', 'device' => 'x'],
        ]);
        $this->assertSame(['token_id' => 7, 'tokens' => 2, 'nested' => ['device' => 'x']], $record->context);
    }
}
