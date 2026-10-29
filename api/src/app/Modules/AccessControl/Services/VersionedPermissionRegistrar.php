<?php

namespace App\Modules\AccessControl\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Spatie's registrar with a versioned cache key (M2).
 *
 * Spatie caches the whole permission→roles map under one key and refills it on
 * a miss. A request that read the map BEFORE a permission change committed could
 * write that stale map back AFTER the post-commit clear, and it would then be
 * served until the cache expires (24h). A second clear cannot prevent that.
 *
 * Here the key is `<permission.cache.key>.<version>`, where the version is a ULID
 * kept in the same (shared) cache store. After a permission change commits,
 * invalidate() switches to a new ULID: a late writer can only write under the
 * old key, which nothing reads any more.
 *
 * - Rollback: invalidateAfterCommit() uses DB::afterCommit, so a rolled-back
 *   change never bumps the version (the cache keeps the committed state).
 * - Nested transactions: the bump waits for the outermost commit.
 * - Multiple instances: the version lives in the configured cache store
 *   (database/redis), which every instance reads when it builds its registrar.
 * - ULIDs never repeat, so an evicted version key cannot resurrect an old entry.
 */
class VersionedPermissionRegistrar extends PermissionRegistrar
{
    public const VERSION_KEY = 'spatie.permission.cache.version';

    public function initializeCache(): void
    {
        parent::initializeCache();

        $this->cacheKey = $this->keyFor($this->currentVersion());
    }

    public function currentVersion(): string
    {
        return (string) $this->cache->rememberForever(self::VERSION_KEY, fn () => (string) Str::ulid());
    }

    /** Switch every instance to a fresh cache key. Call only after the change has committed. */
    public function invalidate(): void
    {
        $previousKey = $this->cacheKey;
        $version = (string) Str::ulid();

        $this->cache->forever(self::VERSION_KEY, $version);
        $this->cacheKey = $this->keyFor($version);
        $this->clearPermissionsCollection();
        // Housekeeping only: anything written to the old key later is never read.
        $this->cache->forget($previousKey);
    }

    /** Bump the version once the current (outermost) transaction commits; never on rollback. */
    public function invalidateAfterCommit(): void
    {
        DB::afterCommit(fn () => $this->invalidate());
    }

    private function keyFor(string $version): string
    {
        return config('permission.cache.key').'.'.$version;
    }
}
