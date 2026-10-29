<?php

namespace App\Modules\AccessControl\Console;

use App\Modules\AccessControl\Services\PermissionSynchronizer;
use App\Modules\Core\Audit\AuditLogger;
use Illuminate\Console\Command;

class SyncPermissionsCommand extends Command
{
    protected $signature = 'access:sync-permissions';

    protected $description = 'Mirror the code-defined permission catalog to the database (idempotent; run on every deploy)';

    public function handle(PermissionSynchronizer $synchronizer, AuditLogger $audit): int
    {
        $result = $synchronizer->sync();
        $audit->record('permissions.synced', null, $result, actorType: 'cli');

        $this->components->info("Synced {$result['groups']} groups and {$result['permissions']} permissions.");
        if ($result['deprecated'] !== []) {
            $this->components->warn('Marked deprecated: '.implode(', ', $result['deprecated']));
        }

        return self::SUCCESS;
    }
}
