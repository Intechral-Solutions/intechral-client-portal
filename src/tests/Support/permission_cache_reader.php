<?php

/*
 * Child process for PermissionCacheCommitOrderTest: another request's view of the shared permission cache.
 *
 * Bootstraps the application against the testing database with its own connection, points the permission library at
 * the shared cache store the test uses, and loads the permission cache exactly as any request does: a cache hit, or a
 * reload from the COMMITTED rows (it cannot see the parent's uncommitted transaction) that it then stores in the
 * shared cache. It prints the role ids the cache maps the permission to and, given a user id, whether that user has
 * the permission, as JSON.
 *
 * Usage: php permission_cache_reader.php <cache-store-config-json> <permission-name> [<user-id>]
 */

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Spatie\Permission\PermissionRegistrar;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$store = json_decode($argv[1] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
$permission = (string) ($argv[2] ?? '');
$userId = isset($argv[3]) ? (int) $argv[3] : null;

config(['cache.stores.permission-race' => $store, 'permission.cache.store' => 'permission-race', 'permission.cache.expiration_time' => 300]);
$registrar = $app->make(PermissionRegistrar::class);
$registrar->initializeCache();
$registrar->clearPermissionsCollection();

$cached = $registrar->getPermissions(['name' => $permission], true)->first();

echo json_encode([
    'roles' => $cached?->roles->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
    'has' => $userId === null ? null : User::findOrFail($userId)->hasPermissionTo($permission),
]);
