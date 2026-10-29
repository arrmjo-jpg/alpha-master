<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Core\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Feature\ApiTestCase;

class AuthenticationTest extends ApiTestCase
{
    private function issueToken(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/tokens', $overrides + [
            'identifier' => 'sara',
            'password' => 'password',
            'device_name' => 'Pixel 9',
            'client' => 'public',
        ]);
    }

    public function test_token_login_with_username_or_email_is_case_insensitive(): void
    {
        User::factory()->create(['username' => 'sara', 'email' => 'sara@example.com']);

        $byUsername = $this->issueToken(['identifier' => 'SARA'])->assertCreated()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.client', 'public');
        $this->issueToken(['identifier' => 'Sara@Example.com'])->assertCreated();

        $this->withToken($byUsername->json('data.token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.username', 'sara')
            ->assertJsonMissingPath('data.password');

        $this->assertAudited('auth.token_issued');
    }

    public function test_unknown_user_and_wrong_password_are_indistinguishable(): void
    {
        User::factory()->create(['username' => 'sara']);

        $unknown = $this->assertProblem($this->issueToken(['identifier' => 'nobody']), 401, 'INVALID_CREDENTIALS');
        $wrong = $this->assertProblem($this->issueToken(['password' => 'wrong-password']), 401, 'INVALID_CREDENTIALS');

        $strip = fn ($json) => collect($json)->except('trace_id')->all();
        $this->assertSame($strip($unknown->json()), $strip($wrong->json()));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_failed_login_is_audited_without_secrets(): void
    {
        User::factory()->create(['username' => 'sara']);
        $this->issueToken(['password' => 'Secret-Attempt-123']);

        $log = $this->assertAudited('auth.login_failed', 'failed');
        $this->assertStringNotContainsString('Secret-Attempt-123', json_encode(AuditLog::all()->toArray()));
        $this->assertSame('wrong_password', $log->context['reason']);
    }

    public function test_unauthenticated_requests_get_a_problem_response(): void
    {
        // Clients that do not send `Accept: application/json` must not be redirected (or crash).
        $this->assertProblem($this->get('/api/v1/auth/me'), 401, 'UNAUTHENTICATED');
        $this->assertProblem($this->get('/api/v1/admin/users'), 401, 'UNAUTHENTICATED');
        $this->assertProblem($this->getJson('/api/v1/auth/me'), 401, 'UNAUTHENTICATED');
        $this->assertProblem($this->withToken('1|not-a-real-token')->getJson('/api/v1/auth/me'), 401, 'UNAUTHENTICATED');
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        User::factory()->create(['username' => 'sara']);
        $first = $this->issueToken()->json('data.token');
        $second = $this->issueToken(['device_name' => 'iPad'])->json('data.token');

        $this->withToken($first)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->assertProblem($this->withToken($first)->getJson('/api/v1/auth/me'), 401, 'UNAUTHENTICATED');
        $this->app['auth']->forgetGuards();
        $this->withToken($second)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_logout_from_all_devices_revokes_every_token(): void
    {
        $user = User::factory()->create(['username' => 'sara']);
        $first = $this->issueToken()->json('data.token');
        $this->issueToken(['device_name' => 'iPad']);

        $this->withToken($first)->getJson('/api/v1/auth/tokens')->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($first)->deleteJson('/api/v1/auth/tokens')->assertNoContent();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_banned_and_deactivated_accounts_cannot_log_in(): void
    {
        User::factory()->banned()->create(['username' => 'banned1']);
        User::factory()->deactivated()->create(['username' => 'inactive1']);

        $this->assertProblem($this->issueToken(['identifier' => 'banned1']), 403, 'ACCOUNT_BANNED');
        $this->assertProblem($this->issueToken(['identifier' => 'inactive1']), 403, 'ACCOUNT_INACTIVE');
        // Status is never revealed without the correct password.
        $this->assertProblem($this->issueToken(['identifier' => 'banned1', 'password' => 'nope']), 401, 'INVALID_CREDENTIALS');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_existing_token_stops_working_when_the_account_is_banned(): void
    {
        $user = User::factory()->create(['username' => 'sara']);
        $token = $this->issueToken()->json('data.token');

        // Simulate a status change that bypassed the revocation path.
        DB::table('users')->where('id', $user->id)->update(['banned_at' => now()]);

        $this->assertProblem($this->withToken($token)->getJson('/api/v1/auth/me'), 403, 'ACCOUNT_BANNED');
        $this->assertSame(0, $user->tokens()->count(), 'The offending token must be destroyed.');
    }

    public function test_expired_tokens_are_rejected(): void
    {
        User::factory()->create(['username' => 'sara']);
        $token = $this->issueToken()->json('data.token');

        $this->travel(config('sanctum.token_ttl_days.public') + 1)->days();

        $this->assertProblem($this->withToken($token)->getJson('/api/v1/auth/me'), 401, 'UNAUTHENTICATED');
    }

    public function test_admin_tokens_are_only_issued_to_admin_accounts(): void
    {
        User::factory()->writer()->create(['username' => 'writer1']);
        User::factory()->admin()->create(['username' => 'admin1']);

        $this->assertProblem($this->issueToken(['identifier' => 'writer1', 'client' => 'admin']), 403, 'ADMIN_ACCESS_REQUIRED');
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->issueToken(['identifier' => 'admin1', 'client' => 'admin'])->assertCreated()->assertJsonPath('data.client', 'admin');
    }

    public function test_public_client_token_cannot_reach_admin_endpoints_even_for_admins(): void
    {
        $admin = $this->adminWith('users.view');
        $admin->forceFill(['username' => 'admin1'])->save();
        $token = $this->issueToken(['identifier' => 'admin1', 'client' => 'public'])->json('data.token');

        $this->assertProblem($this->withToken($token)->getJson('/api/v1/admin/users'), 403, 'ADMIN_ACCESS_REQUIRED');
    }

    public function test_spa_session_login_works_only_for_stateful_origins(): void
    {
        User::factory()->create(['username' => 'sara']);

        $this->assertProblem(
            $this->postJson('/api/v1/auth/login', ['identifier' => 'sara', 'password' => 'password']),
            400, 'STATEFUL_REQUEST_REQUIRED',
        );

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/v1/auth/login', ['identifier' => 'sara', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.username', 'sara');

        $this->assertAuthenticated('web');
        $this->withHeader('Origin', 'http://localhost')->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_login_is_rate_limited(): void
    {
        RateLimiter::clear('login');
        User::factory()->create(['username' => 'sara']);

        for ($i = 0; $i < 5; $i++) {
            $this->issueToken(['password' => 'wrong'])->assertStatus(401);
        }

        $this->assertProblem($this->issueToken(['password' => 'wrong']), 429, 'RATE_LIMIT_EXCEEDED')
            ->assertHeader('Retry-After');
    }

    public function test_login_validation_errors_are_localized(): void
    {
        $ar = $this->assertProblem($this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/auth/tokens', []), 422, 'VALIDATION_ERROR');
        $en = $this->assertProblem($this->withHeader('Accept-Language', 'en')->postJson('/api/v1/auth/tokens', []), 422, 'VALIDATION_ERROR');

        $ar->assertJsonPath('errors.identifier.0.code', 'required')
            ->assertJsonPath('errors.identifier.0.message', 'حقل اسم المستخدم أو البريد الإلكتروني مطلوب.')
            ->assertJsonPath('title', 'البيانات المرسلة غير صالحة.');
        $en->assertJsonPath('errors.identifier.0.message', 'The username or email field is required.')
            ->assertJsonPath('title', 'The submitted data is invalid.');
    }
}
