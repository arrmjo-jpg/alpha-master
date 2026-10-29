<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Audit\AuditLogger;
use Tests\Feature\ApiTestCase;

/** End-to-end: real audit events keep their safe context and never store secrets. */
class AuditRedactionTest extends ApiTestCase
{
    private function issueToken(string $device): string
    {
        return $this->postJson('/api/v1/auth/tokens', [
            'identifier' => 'sara', 'password' => 'password', 'device_name' => $device, 'client' => 'public',
        ])->assertCreated()->json('data.token');
    }

    public function test_token_events_keep_token_ids_and_counts(): void
    {
        User::factory()->create(['username' => 'sara']);
        $first = $this->issueToken('phone');
        $second = $this->issueToken('tablet');
        $secondId = (int) explode('|', $second)[0];

        $this->withToken($first)->deleteJson("/api/v1/auth/tokens/{$secondId}")->assertNoContent();
        $this->assertSame($secondId, $this->assertAudited('auth.token_revoked')->context['token_id']);

        $this->app['auth']->forgetGuards();
        $this->withToken($first)->deleteJson('/api/v1/auth/tokens')->assertNoContent();
        $all = $this->assertAudited('auth.all_credentials_revoked')->context;
        $this->assertSame(1, $all['tokens']);
        $this->assertArrayHasKey('sessions', $all);

        $stored = json_encode(AuditLog::query()->pluck('context')->all());
        foreach ([$first, $second] as $token) {
            $this->assertStringNotContainsString(explode('|', $token)[1], $stored, 'A token secret reached the audit log.');
        }
    }

    public function test_logger_redacts_secret_keys_before_storage(): void
    {
        $log = app(AuditLogger::class)->record('test.redaction', null, [
            'token_id' => 5,
            'Authorization' => 'placeholder-value',
            'request' => ['session_id' => 'placeholder-value', 'route' => 'x'],
            'fields' => ['name', 'email'],
        ]);

        // MySQL JSON columns normalize key order, so compare content, not order.
        $this->assertEquals([
            'token_id' => 5,
            'request' => ['route' => 'x'],
            'fields' => ['name', 'email'],
        ], $log->fresh()->context);
    }
}
