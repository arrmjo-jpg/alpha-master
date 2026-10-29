<?php

namespace Tests\Feature\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Models\Role;
use Tests\Feature\ApiTestCase;

class RoleManagementTest extends ApiTestCase
{
    public function test_create_role_requires_both_translations(): void
    {
        $this->actingAsAdminClient($this->adminWith('roles.create'));

        $this->assertProblem($this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/admin/access/roles', [
            'name' => 'content-editor',
            'display_name' => ['en' => 'Content editor'],
        ]), 422, 'VALIDATION_ERROR')->assertJsonPath('errors.display_name.0.message', 'يجب أن يحتوي حقل الاسم المعروض على نص غير فارغ لكل لغة: ar, en.');

        $this->assertProblem($this->postJson('/api/v1/admin/access/roles', [
            'name' => 'Content Editor',
            'display_name' => ['ar' => 'محرر', 'en' => 'Editor'],
        ]), 422, 'VALIDATION_ERROR');

        $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/admin/access/roles', [
            'name' => 'content-editor',
            'display_name' => ['ar' => 'محرر المحتوى', 'en' => 'Content editor'],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'content-editor')
            ->assertJsonPath('data.display_name', 'محرر المحتوى')
            ->assertJsonPath('data.is_protected', false);

        $this->assertAudited('role.created');
    }

    public function test_cannot_create_a_role_with_a_duplicate_or_protected_name(): void
    {
        $this->actingAsAdminClient($this->adminWith('roles.create'));

        $this->assertProblem($this->postJson('/api/v1/admin/access/roles', [
            'name' => Role::SYSTEM_OWNER,
            'display_name' => ['ar' => 'مالك', 'en' => 'Owner'],
        ]), 422, 'VALIDATION_ERROR');
    }

    public function test_protected_system_owner_role_is_immutable_through_the_api(): void
    {
        $owner = Role::findByName(Role::SYSTEM_OWNER);
        $this->actingAsAdminClient($this->owner());

        $this->assertProblem($this->patchJson("/api/v1/admin/access/roles/{$owner->id}", ['display_name' => ['ar' => 'س', 'en' => 'x']]), 403, 'PROTECTED_ROLE');
        $this->assertProblem($this->deleteJson("/api/v1/admin/access/roles/{$owner->id}"), 403, 'PROTECTED_ROLE');
        $this->assertProblem($this->putJson("/api/v1/admin/access/roles/{$owner->id}/permissions", [
            'permissions' => [], 'current_password' => 'password',
        ]), 403, 'PROTECTED_ROLE');
        $this->assertDatabaseHas('roles', ['name' => Role::SYSTEM_OWNER]);
    }

    public function test_grant_ceiling_blocks_granting_permissions_you_do_not_hold(): void
    {
        $role = $this->roleWith('users.view');
        $this->actingAsAdminClient($this->adminWith('roles.assign-permissions', 'users.view'));

        $this->assertProblem($this->putJson("/api/v1/admin/access/roles/{$role->id}/permissions", [
            'permissions' => ['users.view', 'users.ban'],
            'current_password' => 'password',
        ]), 403, 'GRANT_CEILING_EXCEEDED');
        $this->assertSame(['users.view'], $role->fresh('permissions')->permissions->pluck('name')->all());
        $this->assertAudited('role.permissions_synced', 'denied', $role->id);
    }

    public function test_self_escalation_through_own_role_is_blocked(): void
    {
        $actor = $this->adminWith('roles.assign-permissions');
        $ownRole = $actor->roles->first();
        $this->actingAsAdminClient($actor);

        $this->assertProblem($this->putJson("/api/v1/admin/access/roles/{$ownRole->id}/permissions", [
            'permissions' => ['roles.assign-permissions', 'users.change-account-type'],
            'current_password' => 'password',
        ]), 403, 'GRANT_CEILING_EXCEEDED');
        $this->assertFalse($actor->fresh()->hasPermissionTo('users.change-account-type'));
    }

    public function test_sync_role_permissions_requires_password_and_valid_permissions(): void
    {
        $role = $this->roleWith();
        $this->actingAsAdminClient($this->adminWith('roles.assign-permissions', 'users.view'));

        $this->assertProblem($this->putJson("/api/v1/admin/access/roles/{$role->id}/permissions", [
            'permissions' => ['users.view'],
        ]), 422, 'VALIDATION_ERROR');
        $this->assertProblem($this->putJson("/api/v1/admin/access/roles/{$role->id}/permissions", [
            'permissions' => ['does.not-exist'], 'current_password' => 'password',
        ]), 422, 'VALIDATION_ERROR');

        $this->putJson("/api/v1/admin/access/roles/{$role->id}/permissions", [
            'permissions' => ['users.view'], 'current_password' => 'password',
        ])->assertOk()->assertJsonPath('data.permissions', ['users.view']);

        $log = $this->assertAudited('role.permissions_synced', 'success', $role->id);
        $this->assertSame(['users.view'], $log->context['added']);
    }

    public function test_permission_cache_reflects_role_changes_immediately(): void
    {
        $role = $this->roleWith();
        $member = User::factory()->admin()->create();
        $member->assignRole($role);
        $manager = $this->adminWith('roles.assign-permissions', 'users.view');

        $this->actingAsAdminClient($member);
        $this->assertProblem($this->getJson('/api/v1/admin/users'), 403, 'FORBIDDEN');

        $this->actingAsAdminClient($manager);
        $this->putJson("/api/v1/admin/access/roles/{$role->id}/permissions", [
            'permissions' => ['users.view'], 'current_password' => 'password',
        ])->assertOk();

        $this->actingAsAdminClient($member->fresh());
        $this->getJson('/api/v1/admin/users')->assertOk();

        $this->actingAsAdminClient($manager);
        $this->putJson("/api/v1/admin/access/roles/{$role->id}/permissions", [
            'permissions' => [], 'current_password' => 'password',
        ])->assertOk();

        $this->actingAsAdminClient($member->fresh());
        $this->assertProblem($this->getJson('/api/v1/admin/users'), 403, 'FORBIDDEN');
    }

    public function test_assign_roles_to_users(): void
    {
        $actor = $this->adminWith('roles.assign', 'users.view');
        $role = $this->roleWith('users.view');
        $strongRole = $this->roleWith('users.ban');
        $adminTarget = User::factory()->admin()->create();
        $writer = User::factory()->writer()->create();
        $this->actingAsAdminClient($actor);

        $assign = fn (User $u, array $roles) => $this->putJson("/api/v1/admin/users/{$u->id}/roles", ['roles' => $roles, 'current_password' => 'password']);

        $this->assertProblem($assign($writer, [$role->id]), 409, 'ROLES_REQUIRE_ADMIN_ACCOUNT');
        $this->assertProblem($assign($adminTarget, [$strongRole->id]), 403, 'GRANT_CEILING_EXCEEDED');
        $this->assertProblem($assign($adminTarget, [Role::findByName(Role::SYSTEM_OWNER, 'web')->id]), 403, 'PROTECTED_ROLE');
        $this->assertProblem($assign($actor, [$role->id]), 403, 'SELF_MODIFICATION_FORBIDDEN');

        $assign($adminTarget, [$role->id])->assertOk()->assertJsonPath('data.roles.0.name', $role->name);
        $this->assertTrue($adminTarget->fresh()->hasRole($role));
        $this->assertSame([$role->name], $this->assertAudited('user.roles_synced', 'success', $adminTarget->id)->context['added']);
    }

    public function test_delete_role_respects_grant_ceiling(): void
    {
        $strong = $this->roleWith('users.ban');
        $weak = $this->roleWith('users.view');
        $this->actingAsAdminClient($this->adminWith('roles.delete', 'users.view'));

        $this->assertProblem($this->deleteJson("/api/v1/admin/access/roles/{$strong->id}"), 403, 'GRANT_CEILING_EXCEEDED');
        $this->deleteJson("/api/v1/admin/access/roles/{$weak->id}")->assertNoContent();
        $this->assertDatabaseMissing('roles', ['id' => $weak->id]);
        $this->assertAudited('role.deleted');
    }
}
