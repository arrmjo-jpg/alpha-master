<?php

namespace Tests\Feature\Users;

use App\Models\User;
use App\Modules\AccessControl\Services\PermissionSynchronizer;
use App\Modules\Core\Errors\DomainError;
use App\Modules\Core\Errors\ErrorCode;
use App\Modules\Users\Actions\ChangeAccountType;
use App\Modules\Users\Actions\ManageAccountStatus;
use App\Modules\Users\Enums\AccountType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Two admins, X and Y, try to ban each other at the same time. Without row
 * locks both could succeed and leave no usable admin. This test commits real
 * transactions on two MySQL connections, so it truncates instead of rolling back.
 */
class LastAdminConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private User $x;

    private User $y;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionSynchronizer::class)->sync();
        config(['database.connections.mysql_second' => config('database.connections.mysql')]);

        $role = $this->roleWith('users.ban', 'users.change-account-type');
        $this->x = User::factory()->admin()->create();
        $this->y = User::factory()->admin()->create();
        $this->x->assignRole($role);
        $this->y->assignRole($role);
    }

    protected function tearDown(): void
    {
        DB::connection('mysql_second')->rollBack(0);
        DB::purge('mysql_second');

        // This test commits rows; leave a clean, seeded schema for the transactional tests.
        $this->artisan('migrate:fresh', ['--seed' => true]);

        parent::tearDown();
    }

    public function test_concurrent_mutual_bans_cannot_remove_every_admin(): void
    {
        $second = DB::connection('mysql_second');

        // Connection 2 = "X bans Y", in flight: holds the admin-row locks, not yet committed.
        $second->beginTransaction();
        $second->table('users')->where('account_type', 'admin')->lockForUpdate()->get();
        $second->table('users')->where('id', $this->y->id)->update(['banned_at' => now()]);

        // Connection 1 = "Y bans X": must block on the same locks rather than read stale state.
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            app(ManageAccountStatus::class)->ban($this->y, $this->x, 'race');
            $this->fail('The ban must wait for the concurrent transaction’s admin-row lock.');
        } catch (QueryException $e) {
            $this->assertSame(1205, $e->errorInfo[1] ?? null, 'Expected a lock wait timeout (MySQL 1205).');
        }

        $second->commit();

        // Retried after the first ban committed: Y is banned, so X is the last usable admin.
        try {
            app(ManageAccountStatus::class)->ban($this->y, $this->x, 'race');
            $this->fail('Banning the last usable admin must be refused.');
        } catch (DomainError $e) {
            $this->assertSame(ErrorCode::LastAdminProtected, $e->errorCode);
        }

        $this->assertTrue($this->x->fresh()->isUsable());
        $this->assertSame(1, User::query()->where('account_type', 'admin')->usable()->count());
    }

    public function test_demoting_the_last_usable_admin_is_refused(): void
    {
        DB::table('users')->where('id', $this->y->id)->update(['deactivated_at' => now()]);

        $this->expectExceptionObject(DomainError::because(ErrorCode::LastAdminProtected));
        app(ChangeAccountType::class)($this->y, $this->x, AccountType::User);
    }
}
