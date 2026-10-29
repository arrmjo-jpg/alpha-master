<?php

/**
 * Test helper: runs ONE real application action in a separate PHP process so
 * concurrency tests can interleave it with another action in the test process.
 *
 * Usage: php tests/Support/concurrent-runner.php '<json>'
 *   {"scenario": "...", "args": {...}, "pause_on": "<sql fragment>",
 *    "marker": "<file to create when paused>", "resume": "<file to wait for>|null",
 *    "hold_seconds": 2}
 *
 * After the first SQL statement containing `pause_on` has executed (so its lock
 * is held), the runner creates `marker`, then waits for `resume` (if given) or
 * sleeps `hold_seconds`, and only then lets the action continue and commit.
 * Prints one JSON line: {"ok": true, ...} or {"ok": false, "error": "<code>"}.
 */

use App\Models\User;
use App\Modules\AccessControl\Actions\ManageRoles;
use App\Modules\AccessControl\Models\Role;
use App\Modules\Core\Errors\DomainError;
use App\Modules\Users\Actions\SyncUserRoles;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$job = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

$paused = false;
DB::listen(function ($query) use (&$paused, $job) {
    if ($paused || stripos($query->sql, $job['pause_on']) === false) {
        return;
    }
    $paused = true;
    touch($job['marker']);

    if ($job['resume'] ?? null) {
        $deadline = microtime(true) + 20;
        while (! file_exists($job['resume']) && microtime(true) < $deadline) {
            usleep(20_000);
        }
    } else {
        usleep((int) (($job['hold_seconds'] ?? 2) * 1_000_000));
    }
});

$args = $job['args'];

try {
    $result = match ($job['scenario']) {
        'sync_user_roles' => (function () use ($args) {
            $user = app(SyncUserRoles::class)(User::findOrFail($args['actor']), User::findOrFail($args['target']), $args['roles']);

            return ['roles' => $user->roles->pluck('name')->sort()->values()->all()];
        })(),
        'sync_role_permissions' => (function () use ($args) {
            $role = app(ManageRoles::class)->syncPermissions(User::findOrFail($args['actor']), Role::findOrFail($args['role']), $args['permissions']);

            return ['permissions' => $role->permissions->pluck('name')->sort()->values()->all()];
        })(),
        // A request that loads the permission map from the database (cache miss),
        // pauses after reading, and only then writes it to the cache.
        'load_permission_cache' => (function () {
            $registrar = app(PermissionRegistrar::class);
            $key = $registrar->cacheKey;
            $registrar->getPermissions();

            return ['cache_key' => $key];
        })(),
    };

    echo json_encode(['ok' => true] + $result), PHP_EOL;
} catch (DomainError $e) {
    echo json_encode(['ok' => false, 'error' => $e->errorCode->value]), PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e::class, 'message' => $e->getMessage()]), PHP_EOL;
}
