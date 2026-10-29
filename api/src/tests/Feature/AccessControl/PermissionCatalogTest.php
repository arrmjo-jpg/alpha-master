<?php

namespace Tests\Feature\AccessControl;

use App\Modules\AccessControl\Models\Permission;
use App\Modules\AccessControl\Models\PermissionGroup;
use App\Modules\AccessControl\Models\Role;
use App\Modules\AccessControl\Services\PermissionSynchronizer;
use App\Modules\Core\Audit\AuditLogger;
use Tests\Feature\ApiTestCase;

class PermissionCatalogTest extends ApiTestCase
{
    private const CATALOG = [
        'users.view', 'users.create', 'users.update', 'users.activate', 'users.deactivate',
        'users.ban', 'users.unban', 'users.change-account-type', 'users.revoke-sessions',
        'roles.view', 'roles.create', 'roles.update', 'roles.delete', 'roles.assign',
        'roles.assign-permissions', 'permissions.view', 'audit.view',
    ];

    public function test_catalog_is_synced_with_stable_identifiers(): void
    {
        $this->assertEqualsCanonicalizing(self::CATALOG, Permission::query()->pluck('name')->all());
        $this->assertSame(4, PermissionGroup::query()->count());
    }

    public function test_sync_is_idempotent_and_only_grants_to_the_owner_role(): void
    {
        $custom = $this->roleWith('users.view');

        $sync = app(PermissionSynchronizer::class);
        $sync->sync();
        $sync->sync();

        $this->assertSame(count(self::CATALOG), Permission::query()->count());
        $this->assertSame(1, Role::query()->where('name', Role::SYSTEM_OWNER)->count());
        $this->assertEqualsCanonicalizing(self::CATALOG, Role::findByName(Role::SYSTEM_OWNER)->permissions->pluck('name')->all());
        $this->assertSame(['users.view'], $custom->fresh('permissions')->permissions->pluck('name')->all());
        $this->assertTrue(Role::findByName(Role::SYSTEM_OWNER)->is_protected);
    }

    public function test_permissions_removed_from_code_are_deprecated_not_deleted(): void
    {
        Permission::query()->create(['name' => 'legacy.thing', 'guard_name' => 'web']);
        Role::findByName(Role::SYSTEM_OWNER)->givePermissionTo('legacy.thing');

        $result = app(PermissionSynchronizer::class)->sync();

        $this->assertSame(['legacy.thing'], $result['deprecated']);
        $this->assertTrue(Permission::findByName('legacy.thing')->is_deprecated);
        $this->assertFalse(Role::findByName(Role::SYSTEM_OWNER)->fresh()->hasPermissionTo('legacy.thing'));
    }

    public function test_catalog_endpoint_is_grouped_and_localized(): void
    {
        $this->actingAsAdminClient($this->adminWith('permissions.view'));

        $ar = $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/admin/access/permissions')->assertOk()
            ->assertHeader('Content-Language', 'ar');
        $users = collect($ar->json('data'))->firstWhere('key', 'users');
        $this->assertSame('المستخدمون', $users['name']);
        $this->assertSame('عرض المستخدمين', collect($users['permissions'])->firstWhere('name', 'users.view')['display_name']);

        $en = $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/admin/access/permissions?include=translations')->assertOk();
        $users = collect($en->json('data'))->firstWhere('key', 'users');
        $view = collect($users['permissions'])->firstWhere('name', 'users.view');
        $this->assertSame('View users', $view['display_name']);
        $this->assertSame(['ar' => 'عرض المستخدمين', 'en' => 'View users'], $view['display_name_translations']);
        // The identifier never changes with the language.
        $this->assertSame('users.view', $view['name']);
    }

    public function test_me_lists_effective_permissions_for_ui_only(): void
    {
        $admin = $this->adminWith('users.view', 'roles.view');
        $this->actingAsAdminClient($admin);

        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.account_type', 'admin')
            ->assertJsonPath('data.permissions', ['roles.view', 'users.view']);
    }

    public function test_audit_log_endpoint_requires_permission_and_is_append_only(): void
    {
        $this->actingAsAdminClient($this->adminWith('users.view'));
        $this->assertProblem($this->getJson('/api/v1/admin/access/audit-logs'), 403, 'FORBIDDEN');

        $this->actingAsAdminClient($this->adminWith('audit.view'));
        $this->getJson('/api/v1/admin/access/audit-logs?outcome=success')->assertOk()->assertJsonStructure(['data', 'meta']);

        $log = app(AuditLogger::class)->record('test.event');
        $this->expectException(\LogicException::class);
        $log->update(['event' => 'tampered']);
    }
}
