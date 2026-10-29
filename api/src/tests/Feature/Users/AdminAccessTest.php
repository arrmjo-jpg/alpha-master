<?php

namespace Tests\Feature\Users;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\ApiTestCase;

class AdminAccessTest extends ApiTestCase
{
    public function test_writer_and_normal_user_are_denied_admin_endpoints(): void
    {
        foreach ([User::factory()->writer()->create(), User::factory()->create()] as $user) {
            Sanctum::actingAs($user, ['client:admin']);

            $this->assertProblem($this->getJson('/api/v1/admin/users'), 403, 'ADMIN_ACCESS_REQUIRED');
            $this->assertProblem($this->getJson('/api/v1/admin/access/roles'), 403, 'ADMIN_ACCESS_REQUIRED');
            $this->assertAudited('admin.access_denied', 'denied');
        }
    }

    public function test_non_admins_cannot_probe_record_existence(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['client:admin']);

        // 403 for both an existing and a non-existent id — never 404.
        $this->assertProblem($this->getJson('/api/v1/admin/users/999999'), 403, 'ADMIN_ACCESS_REQUIRED');
    }

    public function test_admin_account_grants_no_permission_by_itself(): void
    {
        $this->actingAsAdminClient($this->adminWith());

        $this->assertProblem($this->getJson('/api/v1/admin/users'), 403, 'FORBIDDEN');
        $this->assertProblem($this->getJson('/api/v1/admin/access/permissions'), 403, 'FORBIDDEN');
    }

    public function test_admin_with_permission_is_allowed_only_for_that_operation(): void
    {
        $target = User::factory()->create();
        $this->actingAsAdminClient($this->adminWith('users.view'));

        $this->getJson('/api/v1/admin/users')->assertOk()->assertJsonStructure(['data', 'links', 'meta']);
        $this->getJson("/api/v1/admin/users/{$target->id}")->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $this->assertProblem($this->patchJson("/api/v1/admin/users/{$target->id}", ['name' => 'Changed']), 403, 'FORBIDDEN');
        $this->assertProblem($this->postJson("/api/v1/admin/users/{$target->id}/ban", ['reason' => 'spam']), 403, 'FORBIDDEN');
    }

    public function test_unknown_user_id_is_404_for_authorized_admins(): void
    {
        $this->actingAsAdminClient($this->adminWith('users.view'));

        $this->assertProblem($this->getJson('/api/v1/admin/users/999999'), 404, 'RESOURCE_NOT_FOUND');
    }

    public function test_user_list_filters_and_search(): void
    {
        User::factory()->writer()->create(['username' => 'writer.one']);
        User::factory()->banned()->create(['username' => 'banned.one']);
        $this->actingAsAdminClient($this->adminWith('users.view'));

        $this->getJson('/api/v1/admin/users?account_type=writer')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/users?status=banned')->assertOk()->assertJsonPath('data.0.username', 'banned.one');
        $this->getJson('/api/v1/admin/users?search=WRITER')->assertOk()->assertJsonCount(1, 'data');
        $this->assertProblem($this->getJson('/api/v1/admin/users?per_page=1000'), 422, 'VALIDATION_ERROR');
    }

    public function test_create_and_update_reject_protected_fields(): void
    {
        $target = User::factory()->create();
        $this->actingAsAdminClient($this->adminWith('users.create', 'users.update'));

        $payload = [
            'name' => 'Laila Hassan',
            'username' => 'laila',
            'email' => 'Laila@Example.com',
            'password' => 'Strong-password-123',
            'password_confirmation' => 'Strong-password-123',
        ];

        $this->assertProblem($this->postJson('/api/v1/admin/users', $payload + ['account_type' => 'admin']), 422, 'VALIDATION_ERROR')
            ->assertJsonPath('errors.account_type.0.code', 'prohibited');
        $this->assertProblem($this->patchJson("/api/v1/admin/users/{$target->id}", ['account_type' => 'admin']), 422, 'VALIDATION_ERROR');
        $this->assertProblem($this->patchJson("/api/v1/admin/users/{$target->id}", ['banned_at' => '2026-01-01 00:00:00']), 422, 'VALIDATION_ERROR');
        $this->assertProblem($this->patchJson("/api/v1/admin/users/{$target->id}", ['password' => 'x']), 422, 'VALIDATION_ERROR');

        $created = $this->postJson('/api/v1/admin/users', $payload)->assertCreated()
            ->assertJsonPath('data.account_type', 'user')
            ->assertJsonPath('data.email', 'laila@example.com');
        $this->assertAudited('user.created', 'success', $created->json('data.id'));
        $this->assertSame('user', $target->fresh()->account_type->value);
    }

    public function test_username_rules_keep_identifiers_unambiguous(): void
    {
        $this->actingAsAdminClient($this->adminWith('users.create'));
        $base = ['name' => 'Test', 'email' => 'x@example.com', 'password' => 'Strong-password-123', 'password_confirmation' => 'Strong-password-123'];

        foreach (['12345', 'a@b', 'ab', 'admin', '.dot', 'with space'] as $bad) {
            $this->assertProblem($this->postJson('/api/v1/admin/users', $base + ['username' => $bad]), 422, 'VALIDATION_ERROR')
                ->assertJsonStructure(['errors' => ['username']]);
        }
    }

    public function test_changing_email_clears_verification_and_is_audited(): void
    {
        $target = User::factory()->create();
        $this->actingAsAdminClient($this->adminWith('users.update'));

        $this->patchJson("/api/v1/admin/users/{$target->id}", ['email' => 'new@example.com'])->assertOk()
            ->assertJsonPath('data.email_verified_at', null);
        $this->assertSame(['email'], $this->assertAudited('user.updated', 'success', $target->id)->context['fields']);
    }
}
