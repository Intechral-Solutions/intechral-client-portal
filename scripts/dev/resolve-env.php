<?php

/*
 * Reports what Laravel ACTUALLY resolves inside the app container. It is streamed to
 * `php` over stdin by ./dev (the container only mounts ./src), never stored in the app:
 *
 *   php -- <dev|test> [--pending] < resolve-env.php
 *
 * dev  - boots the application exactly as artisan / php-fpm would.
 * test - loads tests/bootstrap.php first (the same environment forcing Pest uses), so a
 *        bad test target aborts inside AppServiceProvider / TestDatabaseSafety.
 *
 * Output is `key=value` lines. Secrets are never printed. --pending also connects to the
 * database (read-only) and lists migrations that have not run.
 */

$mode = $argv[1] ?? '';
$pending = in_array('--pending', $argv, true);

if (! in_array($mode, ['dev', 'test'], true)) {
    fwrite(STDERR, "usage: php -- <dev|test> [--pending]\n");
    exit(2);
}

$root = getcwd();

if ($mode === 'test') {
    require $root.'/tests/bootstrap.php';
} else {
    require $root.'/vendor/autoload.php';
}

$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$connection = (string) config('database.default');

echo 'env='.$app->environment().PHP_EOL;
echo 'connection='.(string) config("database.connections.{$connection}.driver").PHP_EOL;
echo 'database='.(string) config("database.connections.{$connection}.database").PHP_EOL;
echo 'host='.(string) config("database.connections.{$connection}.host").PHP_EOL;
echo 'port='.(string) config("database.connections.{$connection}.port").PHP_EOL;
echo 'app_url='.(string) config('app.url').PHP_EOL;
echo 'config_cached='.($app->configurationIsCached() ? '1' : '0').PHP_EOL;

if (! $pending) {
    exit(0);
}

try {
    // Same source of truth as `php artisan migrate:status`.
    $migrator = $app->make('migrator');
    $repository = $migrator->getRepository();
    $ran = $repository->repositoryExists() ? $repository->getRan() : [];
    $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [$app->databasePath('migrations')]));
    $names = array_keys(array_diff_key($files, array_flip($ran)));
    sort($names);

    foreach ($names as $name) {
        echo 'pending='.$name.PHP_EOL;
    }

    echo 'pending_count='.count($names).PHP_EOL;
} catch (Throwable $e) {
    echo 'pending_error='.strtok($e->getMessage(), "\n").PHP_EOL;
    exit(3);
}
