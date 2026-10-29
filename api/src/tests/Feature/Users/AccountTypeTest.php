<?php

namespace Tests\Feature\Users;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\ApiTestCase;

class AccountTypeTest extends ApiTestCase
{
    private function change(User $target, string $type, string $password = 'password'): TestResponse
    {
        return $this->putJson("/api/v1/admin/users/{$target->id}/account-type", [
            'account_type' => $type,
            'current_password' => $password,
        ]);
    }

    public function test_normal_user_cannot_promote_themselves(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['client:admin']);

        $this->assertProblem($this->change($user, 'admin'), 403, 'ADMIN_ACCESS_REQUIRED');
        $this->assertSame('user', $user->fresh()->account_type->value);
    }

    public function test_admin_cannot_change_their_own_account_type(): void
    {
        $admin = $this->adminWith('users.change-account-type');
        $this->actingAsAdminClient($admin);

        $this->assertProblem($this->change($admin, 'user'), 403, 'SELF_MODIFICATION_FORBIDDEN');
        $this->assertAudited('user.account_type_changed', 'denied', $admin->id);
    }

    public function test_admin_without_permission_cannot_change_account_type(): void
    {
        $target = User::factory()->create();
        $this->actingAsAdminClient($this->adminWith('users.update'));

        $this->assertProblem($this->change($target, 'admin'), 403, 'FORBIDDEN');
        $this->assertSame('user', $target->fresh()->account_type->value);
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $target = User::factory()->create();
        $this->actingAsAdminClient($this->adminWith('users.change-account-type'));

        $this->assertProblem($this->change($target, 'admin', 'wrong-password'), 422, 'VALIDATION_ERROR')
            ->assertJsonPath('errors.current_password.0.code', 'current_password');
        $this->assertSame('user', $target->fresh()->account_type->value);
    }

    public function test_invalid_account_type_is_rejected(): void
    {
        $target = User::factory()->create();
        $this->actingAsAdminClient($this->adminWith('users.change-account-type'));

        $this->assertProblem($this->change($target, 'superadmin'), 422, 'VALIDATION_ERROR');
    }

    public function test_promotion_and_demotion_are_audited_and_demotion_strips_roles_and_credentials(): void
    {
        $actor = $this->adminWith('users.change-account-type', 'users.view');
        $target = User::factory()->writer()->create();
        $target->createToken('phone', ['client:public']);
        $this->actingAsAdminClient($actor);

        $this->change($target, 'admin')->assertOk()->assertJsonPath('data.account_type', 'admin');
        $this->assertAudited('user.account_type_changed', 'success', $target->id);

        $target->assignRole($this->roleWith('users.view'));
        $target->createToken('admin-phone', ['client:admin']);

        $this->change($target->fresh(), 'user')->assertOk()->assertJsonPath('data.account_type', 'user');

        $target->refresh();
        $this->assertCount(0, $target->roles);
        $this->assertSame(0, $target->tokens()->count());
        $log = $this->assertAudited('user.account_type_changed', 'success', $target->id);
        $this->assertSame('admin', $log->context['from']);
        $this->assertCount(1, $log->context['roles_removed']);
    }

    public function test_system_owner_cannot_be_demoted_through_the_api(): void
    {
        $owner = $this->owner();
        $this->actingAsAdminClient($this->owner());

        $this->assertProblem($this->change($owner, 'user'), 403, 'OWNER_PROTECTED');
    }

    public function test_cannot_manage_an_admin_holding_permissions_you_lack(): void
    {
        $actor = $this->adminWith('users.change-account-type');
        $stronger = $this->adminWith('users.change-account-type', 'roles.assign-permissions');
        $this->actingAsAdminClient($actor);

        $this->assertProblem($this->change($stronger, 'user'), 403, 'TARGET_NOT_MANAGEABLE');
    }
}
