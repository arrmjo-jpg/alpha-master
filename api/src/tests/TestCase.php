<?php

namespace Tests;

use App\Models\User;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\AccessControl\Models\Role;
use App\Modules\Core\Audit\AuditLog;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /** Admin account holding exactly the given permissions through one role. */
    protected function adminWith(string ...$permissions): User
    {
        $admin = User::factory()->admin()->create();

        if ($permissions !== []) {
            $admin->assignRole($this->roleWith(...$permissions));
        }

        return $admin->fresh();
    }

    protected function roleWith(string ...$permissions): Role
    {
        $role = Role::query()->create([
            'name' => 'role-'.Str::lower(Str::random(8)),
            'guard_name' => 'web',
            'display_name' => ['ar' => 'دور تجريبي', 'en' => 'Test role'],
        ]);
        $role->syncPermissions(Permission::query()->whereIn('name', $permissions)->get());

        return $role->fresh('permissions');
    }

    protected function owner(): User
    {
        $owner = User::factory()->admin()->create();
        $owner->assignRole(Role::SYSTEM_OWNER);

        return $owner->fresh();
    }

    /** Authenticate as an admin client (token with the client:admin ability). */
    protected function actingAsAdminClient(User $user): static
    {
        Sanctum::actingAs($user, ['client:admin']);

        return $this;
    }

    protected function assertProblem(TestResponse $response, int $status, string $code): TestResponse
    {
        $response->assertStatus($status)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', $status)
            ->assertJsonPath('code', $code)
            ->assertJsonStructure(['type', 'title', 'status', 'code', 'trace_id']);

        return $response;
    }

    protected function assertAudited(string $event, string $outcome = 'success', ?int $subjectId = null): AuditLog
    {
        $log = AuditLog::query()
            ->where('event', $event)
            ->where('outcome', $outcome)
            ->when($subjectId, fn ($q) => $q->where('subject_id', $subjectId))
            ->latest('id')
            ->first();

        $this->assertNotNull($log, "Expected audit event [{$event}] with outcome [{$outcome}].");

        return $log;
    }
}
