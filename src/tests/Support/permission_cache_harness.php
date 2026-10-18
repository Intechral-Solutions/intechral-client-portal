<?php

use App\Support\Permissions\PermissionCacheEpoch;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * The process and barrier helpers shared by the permission-cache security tests that run real, independent PHP processes
 * (PermissionCacheEpochTest: file and Redis stores; PermissionCacheDatabaseStoreTest: the database store). Moved out of
 * PermissionCacheEpochTest unchanged except for the optional $env arguments, which add environment variables to a child.
 */

function pceEnv(array $extra = []): array
{
    $connection = config('database.connections.'.config('database.default'));

    return [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => $connection['host'],
        'DB_PORT' => $connection['port'],
        'DB_DATABASE' => $connection['database'],
        'DB_USERNAME' => $connection['username'],
        'DB_PASSWORD' => $connection['password'],
        'DB_URL' => '',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
    ] + $extra;
}

function pceReader(array $store, int $userId, array $permissions, array $options = [], array $env = []): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('tests/Support/permission_epoch_reader.php'), json_encode($store), json_encode(['userId' => $userId, 'permissions' => $permissions] + $options)],
        base_path(),
        pceEnv($env),
    );
    $process->setTimeout(120);

    return $process;
}

/** @return array<string, mixed> */
function pceOutput(Process $process): array
{
    $process->wait();
    if ($process->getExitCode() !== 0) {
        throw new RuntimeException('reader failed ('.$process->getExitCode().'): '.$process->getErrorOutput().$process->getOutput());
    }

    $lines = explode("\n", trim($process->getOutput()));

    return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
}

function pceFresh(array $store, int $userId, array $permissions, array $options = [], array $env = []): array
{
    $process = pceReader($store, $userId, $permissions, $options, $env);
    $process->start();

    return pceOutput($process);
}

/** Wait until the reader is stopped at $point (the barrier is a flag file, not a delay) and return when it got there. */
function pceAwait(Process $reader, string $barrier, string $name, string $point): float
{
    $flag = "$barrier/$name.$point.reached";
    $deadline = microtime(true) + 60;
    while (! file_exists($flag)) {
        if (! $reader->isRunning()) {
            throw new RuntimeException("reader $name ended before reaching $point: ".$reader->getOutput().$reader->getErrorOutput());
        }
        if (microtime(true) > $deadline) {
            throw new RuntimeException("reader $name never reached $point");
        }
        usleep(500);
    }

    return (float) file_get_contents($flag);
}

function pceRelease(string $barrier, string $name, string $point): float
{
    touch("$barrier/$name.$point.release");

    return microtime(true);
}

function pceEpoch(): string
{
    return app(PermissionCacheEpoch::class)->current();
}

function pceGrantExists(string $role, string $permission): bool
{
    return DB::table('role_has_permissions as rhp')
        ->join('roles as r', 'r.id', '=', 'rhp.role_id')
        ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
        ->where('r.name', $role)->where('p.name', $permission)->exists();
}
