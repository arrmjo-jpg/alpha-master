<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\AccessControl\Models\PermissionGroup;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\ApiTestCase;

/** MySQL-specific behavior the design relies on (ADR-0011). */
class DatabaseConstraintsTest extends ApiTestCase
{
    public function test_running_on_mysql(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
    }

    public function test_email_uniqueness_is_case_insensitive_at_the_database_level(): void
    {
        User::factory()->create(['email' => 'sara@example.com']);

        $this->expectException(QueryException::class);
        DB::table('users')->insert([
            'name' => 'X', 'username' => 'other', 'email' => 'SARA@example.com', 'password' => 'x',
        ]);
    }

    public function test_username_is_unique(): void
    {
        User::factory()->create(['username' => 'sara']);

        $this->expectException(QueryException::class);
        User::factory()->create(['username' => 'sara']);
    }

    public function test_permission_group_with_permissions_cannot_be_deleted(): void
    {
        $this->expectException(QueryException::class);
        DB::table('permission_groups')->where('id', PermissionGroup::query()->where('key', 'users')->value('id'))->delete();
    }

    public function test_deleting_a_user_nulls_audit_actor_and_status_actor_references(): void
    {
        $actor = User::factory()->admin()->create();
        $target = User::factory()->create(['banned_by' => $actor->id, 'banned_at' => now()]);
        DB::table('audit_logs')->insert([
            'occurred_at' => now(), 'event' => 'x', 'outcome' => 'success', 'actor_type' => 'user', 'actor_id' => $actor->id,
        ]);

        $actor->delete();

        $this->assertNull($target->fresh()->banned_by);
        $this->assertNull(DB::table('audit_logs')->where('event', 'x')->value('actor_id'));
    }

    public function test_expected_schema_exists(): void
    {
        foreach (['users', 'personal_access_tokens', 'permissions', 'roles', 'model_has_roles', 'role_has_permissions', 'permission_groups', 'audit_logs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasColumns('users', ['username', 'account_type', 'deactivated_at', 'banned_at', 'ban_reason', 'preferred_locale']));
        $this->assertTrue(Schema::hasColumns('permissions', ['permission_group_id', 'display_name', 'description', 'is_sensitive', 'is_deprecated']));
    }
}
