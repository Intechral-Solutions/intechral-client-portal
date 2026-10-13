<?php

namespace App\Support\Permissions;

use Illuminate\Cache\CacheManager;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Release 0.9 (R0.9-1E): the permission library's registrar with its shared cache fenced by {@see PermissionCacheEpoch}.
 *
 * Only two public methods are overridden, and nothing else about authorization changes:
 *
 *  - getPermissions() is the ONLY route to the library's loader (verified in vendor/spatie/laravel-permission 6.25:
 *    Permission::getPermissions() and therefore findByName/findById/create, hasPermissionTo, User::permission() and the
 *    Gate's before-callback all end here). The first call in a registrar's life, and the first after a reset, reads the
 *    epoch and only then lets the library read the cache and, on a miss, the database. The cache key is
 *    `<permission.cache.key>:<epoch>`.
 *  - forgetCachedPermissions() replaces the epoch (in the caller's transaction, if any) before it clears the library's
 *    in-process copy and the old, unsuffixed key. The library calls it after every change to a role, a permission or a
 *    role's permissions; the application's post-commit and post-rollback hooks call it again, which only advances the
 *    epoch once more.
 *
 * What a decision sees: an authorization decision that is already under way, and any later check made by the same
 * request from its already loaded copy, completes under the state it loaded. A request therefore sees a revocation
 * committed after it loaded its copy only in its next request. Queue workers reset the copy for every job (see
 * AppServiceProvider), so a worker never carries one from job to job.
 */
final class EpochPermissionRegistrar extends PermissionRegistrar
{
    private string $baseKey = '';

    public function __construct(CacheManager $cacheManager, private readonly PermissionCacheEpoch $epoch)
    {
        parent::__construct($cacheManager);
    }

    public function initializeCache(): void
    {
        parent::initializeCache();

        $this->baseKey = (string) $this->cacheKey;
    }

    public function getPermissions(array $params = [], bool $onlyOne = false): Collection
    {
        if ($this->permissions === null) {
            // The fence is read BEFORE any cache or database read of this load, which is what makes a stale store harmless.
            $this->cacheKey = $this->baseKey.':'.$this->epoch->current();
        }

        return parent::getPermissions($params, $onlyOne);
    }

    /**
     * Always true once the epoch has advanced, so `permission:cache-reset` reports success although the old,
     * unsuffixed key it also clears usually does not exist.
     */
    public function forgetCachedPermissions()
    {
        // An exception here (the epoch cannot be written) must reach the caller: inside a transaction it aborts the
        // grant change; outside one the caller learns the invalidation did not happen.
        $this->epoch->advance();

        $this->permissions = null;
        $this->forgetWildcardPermissionIndex();
        $this->cacheKey = $this->baseKey;

        try {
            // Only hygiene now: the unsuffixed key is what code that predates the epoch reads (a rolling deployment).
            // The epoch has already made every suffixed entry unreachable, so a cache outage must not block a revocation.
            parent::forgetCachedPermissions();
        } catch (Throwable $exception) {
            report($exception);
        }

        return true;
    }
}
