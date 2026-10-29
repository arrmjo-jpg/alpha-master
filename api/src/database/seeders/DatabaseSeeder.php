<?php

namespace Database\Seeders;

use App\Modules\AccessControl\Services\PermissionSynchronizer;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data only (safe in production, idempotent). No user accounts are
     * seeded: the first administrator is created with `php artisan users:create-owner`.
     */
    public function run(PermissionSynchronizer $synchronizer): void
    {
        $synchronizer->sync();
    }
}
