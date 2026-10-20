<?php

namespace App\Support\Runtime;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;

/**
 * The production runtime profile Release 0.9 is built for on the shared host (R0.9 WP3-A, slice A1), as configuration checks.
 *
 * Release 0.9 runs without Redis and without a persistent worker: the database is the cache, the session store and the queue, and
 * two five-minute cron tasks drain the queue and run the scheduler. The application does not check that it is running in that
 * shape (R0.9 WP2 proved the permission-cache fence only for it), so this class states the shape and `runtime:preflight` refuses
 * anything else. It reads configuration only (nothing live; see {@see RuntimeProbes} for that), and it never puts a value in a message
 * unless the value is not a secret: environment names, driver names and table names, never keys, passwords, hosts of credentials or URLs.
 *
 * It is the production profile: `APP_ENV=production` is itself one of the checks, and development and CI are expected to fail it
 * (`.env.example` is the redis-defaulted dev configuration). docs/architecture/runtime-preflight.md.
 */
final class RuntimeProfile
{
    /** Provisional (R0.9 WP3-A F6): to be confirmed by the WP3-B timing validation on the host. WP1: retry_after must exceed the worker bound. */
    public const MIN_RETRY_AFTER = 300;

    /** The only database drivers whose transactions, locking and SKIP LOCKED the cache, session and queue rely on. */
    public const DATABASE_CONNECTIONS = ['mysql', 'mariadb'];

    /**
     * @param  bool|null  $configurationCached  null: ask the application (a test or a caller can state it)
     * @return list<string>
     */
    public static function configuration(?bool $configurationCached = null): array
    {
        $v = [];
        $cached = $configurationCached ?? app()->configurationIsCached();

        // Application identity and safety.
        if (config('app.env') !== 'production') {
            $v[] = "app.env is '".self::name(config('app.env'))."', expected 'production' (APP_ENV)";
        }

        if (config('app.debug') !== false) {
            $v[] = 'app.debug must be false (APP_DEBUG)';
        }

        if (config('app.timezone') !== 'UTC') {
            $v[] = "app.timezone is '".self::name(config('app.timezone'))."', expected 'UTC'";
        }

        array_push($v, ...self::key());
        array_push($v, ...self::url());

        if (! $cached) {
            $v[] = 'configuration is not cached: run `php artisan config:cache` (production requires it, so every context reads the same resolved configuration)';
        }

        array_push($v, ...self::database(), ...self::cache(), ...self::queue(), ...self::session(), ...self::mail());

        return $v;
    }

    /**
     * Non-secret values an operator needs to compare contexts by eye (R0.9 WP3-A F5). CLI output only; the URL has any credentials, query
     * and fragment removed, and no key, password or DSN is ever included.
     *
     * @return array<string, scalar|null>
     */
    public static function diagnostics(?bool $configurationCached = null): array
    {
        return [
            'app.env' => self::name(config('app.env')),
            'app.url' => self::safeUrl((string) config('app.url')),
            'cache.default' => self::name(config('cache.default')),
            'cache.prefix' => (string) config('cache.prefix'),
            'cache.table' => self::name(config('cache.stores.database.table')),
            'database.default' => self::name(config('database.default')),
            'queue.default' => self::name(config('queue.default')),
            'queue.retry_after' => config('queue.connections.database.retry_after'),
            'session.driver' => self::name(config('session.driver')),
            'mail.default' => self::name(config('mail.default')),
            'configuration_cached' => $configurationCached ?? app()->configurationIsCached(),
            'php' => PHP_VERSION,
        ];
    }

    /** A URL reduced to scheme, host, port and path: no credentials, query or fragment. */
    public static function safeUrl(string $url): string
    {
        $p = parse_url($url);

        if ($p === false || empty($p['host'])) {
            return '(unparseable)';
        }

        return ($p['scheme'] ?? '?').'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '').($p['path'] ?? '');
    }

    /** @return list<string> */
    private static function key(): array
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            return ['app.key is not set (APP_KEY)'];
        }

        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        if ($raw === false || ! Encrypter::supported($raw, (string) config('app.cipher'))) {
            return ['app.key is malformed for the configured cipher (APP_KEY)'];
        }

        return [];
    }

    /** @return list<string> */
    private static function url(): array
    {
        $p = parse_url((string) config('app.url'));

        if ($p === false || empty($p['host'])) {
            return ['app.url is not a valid URL (APP_URL)'];
        }

        $v = [];

        if (($p['scheme'] ?? '') !== 'https') {
            $v[] = 'app.url must use https (APP_URL): queued mail builds its links from it';
        }

        if (in_array(strtolower($p['host']), ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            $v[] = 'app.url points at the local machine (APP_URL): queued mail builds its links from it';
        }

        return $v;
    }

    /** @return list<string> */
    private static function database(): array
    {
        $v = [];
        $default = (string) config('database.default');

        if (! in_array($default, self::DATABASE_CONNECTIONS, true)) {
            return ["database.default is '".self::name($default)."', expected 'mysql' or 'mariadb' (DB_CONNECTION)"];
        }

        // The permission-cache epoch and the queue read through the primary only; a read/write split or replica breaks that.
        foreach (['read', 'write'] as $side) {
            if (filled(config("database.connections.{$default}.{$side}"))) {
                $v[] = "database.connections.{$default}.{$side} is configured: read/write splitting and replicas are not supported";
            }
        }

        return $v;
    }

    /** @return list<string> */
    private static function cache(): array
    {
        $v = [];

        if (config('cache.default') !== 'database') {
            $v[] = "cache.default is '".self::name(config('cache.default'))."', expected 'database' (CACHE_STORE)";
        }

        if (config('permission.cache.store') !== 'default') {
            $v[] = "permission.cache.store is '".self::name(config('permission.cache.store'))."', expected 'default'";
        }

        if (filled(config('cache.stores.database.connection'))) {
            $v[] = 'cache.stores.database.connection is set (DB_CACHE_CONNECTION): the entries and the permission epoch must share one connection';
        }

        if (filled(config('cache.stores.database.lock_connection'))) {
            $v[] = 'cache.stores.database.lock_connection is set (DB_CACHE_LOCK_CONNECTION)';
        }

        if (config('cache.stores.database.table') !== 'cache') {
            $v[] = "cache.stores.database.table is '".self::name(config('cache.stores.database.table'))."', expected 'cache' (DB_CACHE_TABLE)";
        }

        $lockTable = config('cache.stores.database.lock_table');
        if (filled($lockTable) && $lockTable !== 'cache_locks') {
            $v[] = "cache.stores.database.lock_table is '".self::name($lockTable)."', expected 'cache_locks' (DB_CACHE_LOCK_TABLE)";
        }

        if (! filled(config('cache.prefix'))) {
            $v[] = 'cache.prefix is empty (CACHE_PREFIX): the permission epoch and every cache entry would share keys with anything else in the database';
        }

        return $v;
    }

    /** @return list<string> */
    private static function queue(): array
    {
        $v = [];

        if (config('queue.default') !== 'database') {
            $v[] = "queue.default is '".self::name(config('queue.default'))."', expected 'database' (QUEUE_CONNECTION)";
        }

        if (filled(config('queue.connections.database.connection'))) {
            $v[] = 'queue.connections.database.connection is set (DB_QUEUE_CONNECTION): jobs must be written through the default connection';
        }

        if (config('queue.connections.database.table') !== 'jobs') {
            $v[] = "queue.connections.database.table is '".self::name(config('queue.connections.database.table'))."', expected 'jobs' (DB_QUEUE_TABLE)";
        }

        $retryAfter = config('queue.connections.database.retry_after');
        if (! is_int($retryAfter) || $retryAfter < self::MIN_RETRY_AFTER) {
            $v[] = 'queue.connections.database.retry_after must be an integer of at least '.self::MIN_RETRY_AFTER.' seconds (DB_QUEUE_RETRY_AFTER; provisional, to be confirmed by the worker timing validation)';
        }

        if (config('queue.failed.driver') !== 'database-uuids') {
            $v[] = "queue.failed.driver is '".self::name(config('queue.failed.driver'))."', expected 'database-uuids' (QUEUE_FAILED_DRIVER)";
        }

        if (config('queue.failed.database') !== config('database.default')) {
            $v[] = 'queue.failed.database is not the default database connection';
        }

        if (config('queue.failed.table') !== 'failed_jobs') {
            $v[] = "queue.failed.table is '".self::name(config('queue.failed.table'))."', expected 'failed_jobs'";
        }

        return $v;
    }

    /** @return list<string> */
    private static function session(): array
    {
        $v = [];

        if (config('session.driver') !== 'database') {
            $v[] = "session.driver is '".self::name(config('session.driver'))."', expected 'database' (SESSION_DRIVER)";
        }

        if (filled(config('session.connection'))) {
            $v[] = 'session.connection is set (SESSION_CONNECTION): sessions must use the default connection';
        }

        if (config('session.table') !== 'sessions') {
            $v[] = "session.table is '".self::name(config('session.table'))."', expected 'sessions' (SESSION_TABLE)";
        }

        // The session cookie carries the login (R0.9 WP3-A review F2). Strict comparisons: Laravel's default for `secure` is null, which
        // sends the cookie over plain http too, and a stray string such as "false" must not pass for true. SameSite is deliberately not
        // checked: `None` is permitted when Secure is true, and Secure is what is required here.
        if (config('session.secure') !== true) {
            $v[] = 'session.secure must be true (SESSION_SECURE_COOKIE): the session cookie must only be sent over https';
        }

        if (config('session.http_only') !== true) {
            $v[] = 'session.http_only must be true (SESSION_HTTP_ONLY): the session cookie must not be readable by scripts';
        }

        return $v;
    }

    /**
     * A supported, configured, DELIVERING transport (R0.9 WP3-A F6). `log`, `array`, `failover` and everything else that does not provably
     * deliver through the one transport the SMTP bounds were validated for is refused, not tolerated.
     *
     * The transport must also be the discrete SMTP settings below, not a URL (review F1): Laravel parses `mail.mailers.smtp.url` (MAIL_URL)
     * over the mailer's own configuration and lets it replace the transport (`log://localhost` turns the `smtp` mailer into the log driver
     * and `smtp://user:pass@host:port` replaces the host, port and credentials validated here). A non-empty URL is refused, never parsed,
     * and never printed: it can carry credentials.
     *
     * @return list<string>
     */
    private static function mail(): array
    {
        $v = [];
        $default = config('mail.default');

        if ($default !== 'smtp') {
            $v[] = "mail.default is '".self::name($default)."' (MAIL_MAILER): production requires the 'smtp' transport; log, array and other non-delivering or unvalidated transports are refused";
        }

        $transport = config('mail.mailers.smtp.transport');
        if ($transport !== 'smtp') {
            $v[] = "mail.mailers.smtp.transport is '".self::name($transport)."', expected 'smtp'";
        }

        $url = config('mail.mailers.smtp.url');
        if ($url !== null && $url !== '') {
            $v[] = 'mail.mailers.smtp.url is set (MAIL_URL): a transport URL can replace the transport, host and credentials validated here; use the discrete MAIL_* settings';
        }

        if ($default !== 'smtp') {
            return $v;
        }

        $host = strtolower(trim((string) config('mail.mailers.smtp.host')));

        if ($host === '' || $host === 'mailpit') {
            $v[] = 'mail.mailers.smtp.host is not configured for delivery (MAIL_HOST is empty or the development mail sink)';
        }

        $port = config('mail.mailers.smtp.port');
        if (! is_numeric($port) || (int) $port < 1 || (int) $port > 65535) {
            $v[] = 'mail.mailers.smtp.port is not a valid port (MAIL_PORT)';
        }

        $timeout = config('mail.mailers.smtp.timeout');
        if (! is_numeric($timeout) || (float) $timeout <= 0) {
            $v[] = 'mail.mailers.smtp.timeout must be a positive number of seconds (MAIL_TIMEOUT)';
        }

        $from = (string) config('mail.from.address');
        $fromDomain = strtolower((string) Str::after($from, '@'));
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false || in_array($fromDomain, ['example.com', 'example.org', 'example.net'], true)) {
            $v[] = 'mail.from.address is not a real sender address (MAIL_FROM_ADDRESS)';
        }

        return $v;
    }

    /** A configuration NAME (an environment, a driver, a table): printable, bounded, and never a value an operator could have put a secret in. */
    private static function name(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : (is_null($value) ? '' : gettype($value));

        return preg_match('/^[A-Za-z0-9_.:\/-]{0,40}$/', $value) === 1 ? $value : '(unprintable)';
    }
}
