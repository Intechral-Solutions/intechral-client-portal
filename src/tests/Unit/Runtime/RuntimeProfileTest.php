<?php

use App\Support\Runtime\RuntimeProfile;

require_once __DIR__.'/../../Support/runtime_profile_config.php';

/*
 * Release 0.9 (R0.9 WP3-A, slice A1): every configuration rule of the production runtime profile, one violation at a time.
 * Configuration only: nothing here touches the database. docs/architecture/runtime-preflight.md.
 */

beforeEach(fn () => config(rtpProductionConfig()));

it('accepts the approved production configuration', function () {
    expect(RuntimeProfile::configuration(true))->toBe([]);
});

it('refuses each way of leaving the approved profile, and says only that one thing', function (string $name, array $override, string $expected, bool $cached = true) {
    config($override);

    $violations = RuntimeProfile::configuration($cached);

    expect($violations)->toHaveCount(1, $name.' produced: '.implode(' | ', $violations))
        ->and($violations[0])->toContain($expected);
})->with([
    'not production' => ['not production', ['app.env' => 'staging'], "app.env is 'staging'"],
    'debug on' => ['debug on', ['app.debug' => true], 'app.debug must be false'],
    'debug unset' => ['debug unset', ['app.debug' => null], 'app.debug must be false'],
    'not UTC' => ['not UTC', ['app.timezone' => 'America/New_York'], "app.timezone is 'America/New_York'"],
    'no key' => ['no key', ['app.key' => ''], 'app.key is not set'],
    'malformed key' => ['malformed key', ['app.key' => 'base64:c2hvcnQ='], 'app.key is malformed'],
    'http url' => ['http url', ['app.url' => 'http://portal.provider.invalid'], 'app.url must use https'],
    'localhost url' => ['localhost url', ['app.url' => 'https://localhost'], 'app.url points at the local machine'],
    'loopback url' => ['loopback url', ['app.url' => 'https://127.0.0.1:8443'], 'app.url points at the local machine'],
    'unparseable url' => ['unparseable url', ['app.url' => 'not a url'], 'app.url is not a valid URL'],
    'configuration not cached' => ['configuration not cached', [], 'configuration is not cached', false],
    'sqlite default' => ['sqlite default', ['database.default' => 'sqlite', 'queue.failed.database' => 'sqlite'], "database.default is 'sqlite'"],
    'read replica' => ['read replica', ['database.connections.mysql.read' => ['host' => '10.0.0.2']], 'database.connections.mysql.read is configured'],
    'write host' => ['write host', ['database.connections.mysql.write' => ['host' => '10.0.0.3']], 'database.connections.mysql.write is configured'],
    'redis cache' => ['redis cache', ['cache.default' => 'redis'], "cache.default is 'redis'"],
    'file cache' => ['file cache', ['cache.default' => 'file'], "cache.default is 'file'"],
    'permission store' => ['permission store', ['permission.cache.store' => 'redis'], "permission.cache.store is 'redis'"],
    'cache connection' => ['cache connection', ['cache.stores.database.connection' => 'mariadb'], 'cache.stores.database.connection is set'],
    'cache lock connection' => ['cache lock connection', ['cache.stores.database.lock_connection' => 'mariadb'], 'cache.stores.database.lock_connection is set'],
    'cache table' => ['cache table', ['cache.stores.database.table' => 'cache2'], "cache.stores.database.table is 'cache2'"],
    'lock table' => ['lock table', ['cache.stores.database.lock_table' => 'locks'], "cache.stores.database.lock_table is 'locks'"],
    'empty prefix' => ['empty prefix', ['cache.prefix' => ''], 'cache.prefix is empty'],
    'redis queue' => ['redis queue', ['queue.default' => 'redis'], "queue.default is 'redis'"],
    'sync queue' => ['sync queue', ['queue.default' => 'sync'], "queue.default is 'sync'"],
    'queue connection' => ['queue connection', ['queue.connections.database.connection' => 'mariadb'], 'queue.connections.database.connection is set'],
    'jobs table' => ['jobs table', ['queue.connections.database.table' => 'jobs2'], "queue.connections.database.table is 'jobs2'"],
    'retry_after 299' => ['retry_after 299', ['queue.connections.database.retry_after' => 299], 'retry_after must be an integer of at least 300'],
    'retry_after text' => ['retry_after text', ['queue.connections.database.retry_after' => '300'], 'retry_after must be an integer of at least 300'],
    'failed driver' => ['failed driver', ['queue.failed.driver' => 'file'], "queue.failed.driver is 'file'"],
    'failed connection' => ['failed connection', ['queue.failed.database' => 'mariadb'], 'queue.failed.database is not the default database connection'],
    'failed table' => ['failed table', ['queue.failed.table' => 'failed2'], "queue.failed.table is 'failed2'"],
    'file sessions' => ['file sessions', ['session.driver' => 'file'], "session.driver is 'file'"],
    'session connection' => ['session connection', ['session.connection' => 'mariadb'], 'session.connection is set'],
    'session table' => ['session table', ['session.table' => 'sessions2'], "session.table is 'sessions2'"],
    'log mail' => ['log mail', ['mail.default' => 'log'], "mail.default is 'log'"],
    'array mail' => ['array mail', ['mail.default' => 'array'], "mail.default is 'array'"],
    'failover mail' => ['failover mail', ['mail.default' => 'failover'], "mail.default is 'failover'"],
    'ses mail' => ['ses mail', ['mail.default' => 'ses'], "mail.default is 'ses'"],
    'smtp transport replaced by log' => ['smtp transport replaced by log', ['mail.mailers.smtp.transport' => 'log'], "mail.mailers.smtp.transport is 'log', expected 'smtp'"],
    'smtp transport empty' => ['smtp transport empty', ['mail.mailers.smtp.transport' => null], "mail.mailers.smtp.transport is '', expected 'smtp'"],
    'mail url selecting the log driver' => ['mail url selecting the log driver', ['mail.mailers.smtp.url' => 'log://localhost'], 'mail.mailers.smtp.url is set'],
    'mail url replacing host and credentials' => ['mail url replacing host and credentials', ['mail.mailers.smtp.url' => 'smtp://u:p@mailpit:1025'], 'mail.mailers.smtp.url is set'],
    'mail url that is only whitespace' => ['mail url that is only whitespace', ['mail.mailers.smtp.url' => ' '], 'mail.mailers.smtp.url is set'],
    'mail url of the wrong type' => ['mail url of the wrong type', ['mail.mailers.smtp.url' => ['smtp://x']], 'mail.mailers.smtp.url is set'],
    'session cookie secure unset' => ['session cookie secure unset', ['session.secure' => null], 'session.secure must be true'],
    'session cookie secure false' => ['session cookie secure false', ['session.secure' => false], 'session.secure must be true'],
    'session cookie secure string' => ['session cookie secure string', ['session.secure' => 'true'], 'session.secure must be true'],
    'session cookie secure false with SameSite=None' => ['session cookie secure false with SameSite=None', ['session.secure' => false, 'session.same_site' => 'none'], 'session.secure must be true'],
    'session cookie secure unset with SameSite=None' => ['session cookie secure unset with SameSite=None', ['session.secure' => null, 'session.same_site' => 'none'], 'session.secure must be true'],
    'session cookie readable by scripts' => ['session cookie readable by scripts', ['session.http_only' => false], 'session.http_only must be true'],
    'session cookie http_only unset' => ['session cookie http_only unset', ['session.http_only' => null], 'session.http_only must be true'],
    'no mail host' => ['no mail host', ['mail.mailers.smtp.host' => ''], 'mail.mailers.smtp.host is not configured for delivery'],
    'dev mail sink' => ['dev mail sink', ['mail.mailers.smtp.host' => 'mailpit'], 'mail.mailers.smtp.host is not configured for delivery'],
    'mail port 0' => ['mail port 0', ['mail.mailers.smtp.port' => 0], 'mail.mailers.smtp.port is not a valid port'],
    'mail port text' => ['mail port text', ['mail.mailers.smtp.port' => 'smtp'], 'mail.mailers.smtp.port is not a valid port'],
    'mail timeout 0' => ['mail timeout 0', ['mail.mailers.smtp.timeout' => 0], 'mail.mailers.smtp.timeout must be a positive number'],
    'example sender' => ['example sender', ['mail.from.address' => 'hello@example.com'], 'mail.from.address is not a real sender address'],
    'invalid sender' => ['invalid sender', ['mail.from.address' => 'not-an-address'], 'mail.from.address is not a real sender address'],
]);

it('reports an unsupported database default without cascading into read/write checks', function () {
    config(['database.default' => 'pgsql', 'queue.failed.database' => 'pgsql']);

    expect(RuntimeProfile::configuration(true))->toBe(["database.default is 'pgsql', expected 'mysql' or 'mariadb' (DB_CONNECTION)"]);
});

it('accepts mariadb as the database connection and port 587 or 465', function (string $connection, int $port) {
    config(['database.default' => $connection, 'queue.failed.database' => $connection, 'mail.mailers.smtp.port' => $port]);

    expect(RuntimeProfile::configuration(true))->toBe([]);
})->with([['mysql', 587], ['mariadb', 465]]);

it('permits SameSite=None, or any other SameSite value, when the cookie is Secure and HttpOnly', function (string $sameSite) {
    config(['session.same_site' => $sameSite]);

    expect(RuntimeProfile::configuration(true))->toBe([]);
})->with(['none', 'lax', 'strict']);

it('reports a mail transport that was replaced and a URL that carries it, with both messages', function () {
    config(['mail.mailers.smtp.transport' => 'log', 'mail.mailers.smtp.url' => 'log://localhost']);

    expect(RuntimeProfile::configuration(true))->toHaveCount(2);
});

it('still refuses a mail URL when the default mailer is not smtp, and does not go on to check the smtp settings', function () {
    config(['mail.default' => 'log', 'mail.mailers.smtp.url' => 'smtp://u:p@mailpit:1025', 'mail.mailers.smtp.host' => '']);

    $violations = RuntimeProfile::configuration(true);

    expect($violations)->toHaveCount(2)->and($violations[0])->toContain("mail.default is 'log'")->and($violations[1])->toContain('mail.mailers.smtp.url is set');
});

it('accepts an empty mail URL, which Laravel ignores', function () {
    config(['mail.mailers.smtp.url' => '']);

    expect(RuntimeProfile::configuration(true))->toBe([]);
});

it('never prints a mail URL or its credentials, whatever scheme it uses', function (string $url) {
    config(['mail.mailers.smtp.url' => $url]);

    $text = implode("\n", RuntimeProfile::configuration(true));

    expect($text)->toContain('mail.mailers.smtp.url is set')
        ->not->toContain('URLPWMARKER')->not->toContain('URLUSERMARKER')->not->toContain('localhost')->not->toContain('mailpit')->not->toContain('log://')->not->toContain('smtp://');
})->with([
    'log://URLUSERMARKER:URLPWMARKER@localhost',
    'smtp://URLUSERMARKER:URLPWMARKER@mailpit:1025',
    'ses+smtp://URLUSERMARKER:URLPWMARKER@default?region=x',
]);

it('reports every violation of a development configuration at once', function () {
    config(['app.env' => 'local', 'app.debug' => true, 'cache.default' => 'redis', 'queue.default' => 'redis', 'session.driver' => 'file', 'mail.default' => 'log']);

    expect(RuntimeProfile::configuration(false))->toHaveCount(7);
});

it('never puts a secret, a credential or an unprintable value in a message', function () {
    config([
        'app.key' => 'base64:SECRETKEYVALUE'.str_repeat('A', 20),
        'app.url' => 'http://admin:URLPWMARKER@localhost/path?token=QUERYMARKER',
        'app.env' => 'staging@URLPWMARKER',
        'cache.default' => "redis' OR 'x'='x",
        'mail.default' => 'log',
        'mail.mailers.smtp.url' => 'smtp://user:URLPWMARKER@mail.provider.invalid',
        'queue.default' => ['not', 'a', 'string'],
    ]);

    $text = implode("\n", RuntimeProfile::configuration(false));

    foreach (['SECRETKEYVALUE', 'URLPWMARKER', 'QUERYMARKER', 'MAILPWMARKER', 'MAILUSERMARKER', 'OR \'x\''] as $secret) {
        expect($text)->not->toContain($secret);
    }

    expect($text)->toContain("app.env is '(unprintable)'")->and($text)->toContain("queue.default is 'array'");
});

it('prints diagnostics that are safe to share: the effective prefix and URL, with no credentials, query or secret', function () {
    config(['app.url' => 'https://admin:URLPWMARKER@portal.provider.invalid:8443/base?token=QUERYMARKER#frag']);

    $diagnostics = RuntimeProfile::diagnostics(true);
    $text = json_encode($diagnostics);

    expect($diagnostics['cache.prefix'])->toBe('portal_prod_')
        ->and($diagnostics['app.url'])->toBe('https://portal.provider.invalid:8443/base')
        ->and($diagnostics['configuration_cached'])->toBeTrue()
        ->and($text)->not->toContain('URLPWMARKER')->not->toContain('QUERYMARKER')->not->toContain('KEYMARKER')->not->toContain('MAILPWMARKER');
});

it('reduces an unparseable URL to a placeholder', function () {
    expect(RuntimeProfile::safeUrl('::::'))->toBe('(unparseable)');
});
