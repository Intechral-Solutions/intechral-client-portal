<?php

/*
 * Child process for PermissionCacheEpochTest (R0.9-1E): a writer that is killed the instant its transaction has committed.
 *
 * Usage: php permission_epoch_writer.php <options-json>     (options: role, keep, barrier)
 *
 * It revokes through the production path, AccessGrants::syncRolePermissions, inside an outer transaction. A kill callback
 * is registered on that transaction BEFORE AccessGrants registers its own post-commit and post-rollback hooks, and
 * callbacks run in registration order once the commit has happened, so SIGKILL arrives after COMMIT and before ANY
 * application post-commit step. This is what a deployment restart, the OOM killer or a PHP timeout at the wrong moment
 * does. Nothing after the kill runs, including this script's own output.
 */

use App\Services\AccessGrants;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = json_decode($argv[1] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
$grants = $app->make(AccessGrants::class);

DB::transaction(function () use ($options, $grants) {
    DB::afterCommit(function () use ($options) {
        file_put_contents($options['barrier'].'/writer.committed', (string) microtime(true));
        posix_kill(posix_getpid(), SIGKILL);
    });

    $grants->syncRolePermissions(Role::findByName($options['role'], 'web'), $options['keep']);
});

// Reaching this line means the kill callback did not run, which would invalidate the test.
fwrite(STDERR, "the writer survived its own commit\n");
exit(4);
