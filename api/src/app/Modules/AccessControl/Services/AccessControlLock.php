<?php

namespace App\Modules\AccessControl\Services;

use App\Modules\AccessControl\Models\Role;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Single serialization point for every change to role holders or role
 * permissions (ManageRoles::syncPermissions/delete, SyncUserRoles).
 *
 * It takes `SELECT … FOR UPDATE` on the protected system-owner role row, which
 * always exists once the catalog is synced. Because every such transaction takes
 * this one lock FIRST:
 *  - competing role assignments and role mutations run one after another, so a
 *    holder added (or strengthened) concurrently is always seen by the next check;
 *  - there is no lock-ordering cycle between these paths (no deadlocks);
 *  - InnoDB (REPEATABLE READ) takes the transaction snapshot at the first plain
 *    read, which happens after the lock wait, so all later checks read the
 *    latest committed state.
 * Access-control changes are rare admin operations, so the coarse lock is cheap.
 */
class AccessControlLock
{
    public function acquire(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('AccessControlLock must be acquired inside a transaction.');
        }

        $locked = Role::query()
            ->where('name', Role::SYSTEM_OWNER)
            ->where('guard_name', 'web')
            ->lockForUpdate()
            ->value('id');

        if ($locked === null) {
            throw new LogicException('Permission catalog is not synced: run `php artisan access:sync-permissions`.');
        }
    }
}
