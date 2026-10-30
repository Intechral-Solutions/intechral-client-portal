<?php

use App\Support\Runtime\RuntimeProfileRefused;
use App\Support\Runtime\RuntimeStartupGuard;
use Illuminate\Config\Repository;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../Support/runtime_profile_config.php';

/*
 * Release 0.9 (R0.9 WP3-A, slice A3): fail-closed startup of the production worker and scheduler commands. The guard listens to CommandStarting
 * and enforces RuntimeProfile::configuration() (configuration only; no database) for exactly queue:work, queue:listen, schedule:run and schedule:work,
 * in production only. docs/architecture/runtime-preflight.md.
 *
 * Laravel does NOT re-route Symfony's command events while it runs unit tests, so the listener is exercised here directly (and, for the whole
 * kernel path, with the events re-routed by hand in tests/Feature/Runtime/StartupGuardIntegrationTest.php). The REAL-PROCESS tests at the end run
 * actual `php artisan` processes, where Laravel always re-routes them: they are the proof that a refusal stops the command and exits non-zero.
 */

const RSG_GUARDED = ['queue:work', 'queue:listen', 'schedule:run', 'schedule:work'];

function rsgProduction(array $override = [], bool $cached = true): void
{
    config(rtpProductionConfig());
    config($override);
    app()->detectEnvironment(fn () => 'production');
    app()->instance('config_loaded_from_cache', $cached);
}

function rsgStart(string $command): void
{
    app(RuntimeStartupGuard::class)->handle(new CommandStarting($command, new ArrayInput([]), new BufferedOutput));
}

afterEach(function () {
    app()->detectEnvironment(fn () => 'testing');
    app()->forgetInstance('config_loaded_from_cache');
});

describe('a valid production configuration', function () {
    it('lets each guarded command start, and logs nothing', function (string $command) {
        rsgProduction();
        Log::spy();

        rsgStart($command);

        Log::shouldNotHaveReceived('critical');
        expect(true)->toBeTrue();
    })->with(RSG_GUARDED);
});

describe('an invalid production configuration', function () {
    it('refuses each guarded command, naming the violation', function (string $command) {
        rsgProduction(['cache.default' => 'redis']);

        try {
            rsgStart($command);
            $refused = null;
        } catch (RuntimeProfileRefused $e) {
            $refused = $e;
        }

        expect($refused)->toBeInstanceOf(RuntimeProfileRefused::class)->and($refused->command)->toBe($command)
            ->and($refused->violations)->toBe(["cache.default is 'redis', expected 'database' (CACHE_STORE)"])
            ->and($refused->getMessage())->toContain("Refusing to start `{$command}`")->toContain("cache.default is 'redis'")->toContain('Nothing was started, reserved or run');
    })->with(RSG_GUARDED);

    it('refuses configuration that is not cached', function () {
        rsgProduction(cached: false);

        expect(fn () => rsgStart('queue:work'))->toThrow(RuntimeProfileRefused::class, 'configuration is not cached');
    });

    it('refuses on each independent violation, and says only that one thing', function (array $override, string $expected) {
        rsgProduction($override);

        try {
            rsgStart('queue:work');
            $violations = [];
        } catch (RuntimeProfileRefused $e) {
            $violations = $e->violations;
        }

        expect($violations)->toHaveCount(1, implode(' | ', $violations))->and($violations[0])->toContain($expected);
    })->with([
        'redis instead of the database cache' => [['cache.default' => 'redis'], "cache.default is 'redis'"],
        'wrong permission cache store' => [['permission.cache.store' => 'redis'], "permission.cache.store is 'redis'"],
        'separate cache connection' => [['cache.stores.database.connection' => 'mariadb'], 'cache.stores.database.connection is set'],
        'database read/write split' => [['database.connections.mysql.read' => ['host' => '10.0.0.2']], 'database.connections.mysql.read is configured'],
        'sync queue' => [['queue.default' => 'sync'], "queue.default is 'sync'"],
        'redis queue' => [['queue.default' => 'redis'], "queue.default is 'redis'"],
        'retry_after below 300' => [['queue.connections.database.retry_after' => 299], 'retry_after must be an integer of at least 300'],
        'insecure session cookie' => [['session.secure' => false], 'session.secure must be true'],
        'session cookie secure unset' => [['session.secure' => null], 'session.secure must be true'],
        'smtp transport override' => [['mail.mailers.smtp.transport' => 'log'], "mail.mailers.smtp.transport is 'log'"],
        'MAIL_URL' => [['mail.mailers.smtp.url' => 'log://localhost'], 'mail.mailers.smtp.url is set'],
        'debug enabled' => [['app.debug' => true], 'app.debug must be false'],
        'no operator alert destination (EPIC-017 O3, RE-H13)' => [['helpdesk.notify_address' => null], 'helpdesk.notify_address is not set'],
        'malformed operator alert destination' => [['helpdesk.notify_address' => 'not-an-address'], 'helpdesk.notify_address is not a single valid email address'],
    ]);

    it('reports every violation at once, in one refusal', function () {
        rsgProduction(['cache.default' => 'redis', 'app.debug' => true, 'session.secure' => false], cached: false);

        try {
            rsgStart('schedule:run');
        } catch (RuntimeProfileRefused $e) {
            expect($e->violations)->toHaveCount(4);

            return;
        }

        $this->fail('the guard did not refuse');
    });

    it('writes exactly one critical log entry, with the command and the violations and nothing else', function () {
        rsgProduction(['cache.default' => 'redis', 'app.debug' => true]);
        Log::spy();

        expect(fn () => rsgStart('queue:work'))->toThrow(RuntimeProfileRefused::class);

        Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $message, array $context) => str_contains($message, 'refusing to start')
            && array_keys($context) === ['command', 'violations'] && $context['command'] === 'queue:work' && count($context['violations']) === 2);
        Log::shouldNotHaveReceived('error');
    });
});

describe('where it applies', function () {
    it('never blocks development, testing or any non-production environment, whatever the configuration', function (string $environment) {
        // Both sources say non-production: the detected environment and the effective `app.env`.
        rsgProduction(['cache.default' => 'redis', 'app.debug' => true, 'app.env' => $environment], cached: false);
        app()->detectEnvironment(fn () => $environment);
        Log::spy();

        foreach (RSG_GUARDED as $command) {
            rsgStart($command);
        }

        Log::shouldNotHaveReceived('critical');
        expect(true)->toBeTrue();
    })->with(['local', 'testing', 'staging', 'development']);

    it('leaves every other command alone, even when the profile is violated', function (string $command) {
        rsgProduction(['cache.default' => 'redis', 'app.debug' => true], cached: false);   // detected AND configured as production
        Log::spy();

        rsgStart($command);

        Log::shouldNotHaveReceived('critical');
        expect(true)->toBeTrue();
    })->with([
        'migrate', 'migrate:status', 'database:verify', 'runtime:preflight', 'config:cache', 'config:clear', 'cache:clear', 'roles:sync',
        'schedule:list', 'schedule:test', 'schedule:finish', 'queue:failed', 'queue:retry', 'queue:restart', 'queue:monitor', 'tickets:auto-close',
        'list', 'help', '',
        // Near misses: the match is for equality, not a prefix or a wildcard.
        'queue:worker', 'queue:work-all', 'queue:listener', 'schedule:runner', 'schedule:working', 'queue', 'schedule', 'Queue:Work', ' queue:work', 'queue:work ',
    ]);
});

describe('failing closed', function () {
    it('refuses when the profile itself cannot be evaluated, naming only the failure class', function () {
        rsgProduction(['app.url' => new stdClass]);   // `(string)` of an object throws inside RuntimeProfile
        Log::spy();

        try {
            rsgStart('queue:work');
            $refused = null;
        } catch (RuntimeProfileRefused $e) {
            $refused = $e;
        }

        expect($refused)->not->toBeNull()->and($refused->violations)->toBe(['the runtime profile could not be evaluated: Error']);
        Log::shouldHaveReceived('critical')->once();
    });

    it('still refuses when the log channel is broken', function () {
        rsgProduction(['cache.default' => 'redis']);
        Log::shouldReceive('critical')->andThrow(new RuntimeException('disk full on /var/log with token LOGSECRET'));

        try {
            rsgStart('schedule:run');
            $refused = null;
        } catch (Throwable $e) {
            $refused = $e;
        }

        expect($refused)->toBeInstanceOf(RuntimeProfileRefused::class)->and($refused->getMessage())->not->toContain('LOGSECRET');
    });

    it('does not query the database to decide, when it passes or when it refuses', function (array $override) {
        rsgProduction($override);
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        try {
            rsgStart('queue:work');
        } catch (RuntimeProfileRefused) {
            // expected for the invalid case
        }

        expect($queries)->toBe(0);
    })->with([[[]], [['cache.default' => 'redis']]]);
});

describe('diagnostics', function () {
    it('never put a secret in the exception, the log entry or the violations', function () {
        rsgProduction([
            'app.key' => 'base64:'.base64_encode(str_pad('KEYMARKER', 32, 'x')),
            'app.url' => 'http://admin:URLPWMARKER@localhost/path?token=QUERYMARKER',
            'cache.default' => 'redis',
            'mail.default' => 'log',
            'mail.mailers.smtp.url' => 'smtp://URLUSERMARKER:MAILURLPWMARKER@mail.invalid',
            'mail.mailers.smtp.password' => 'MAILPWMARKER',
            'database.connections.mysql.password' => 'DBPWMARKER',
            'database.connections.mysql.url' => 'mysql://u:DBURLPWMARKER@h/d',
        ]);
        Log::spy();

        try {
            rsgStart('queue:work');
            $this->fail('the guard did not refuse');
        } catch (RuntimeProfileRefused $e) {
            $text = $e->getMessage().json_encode($e->violations);
        }

        $logged = '';
        Log::shouldHaveReceived('critical')->once()->withArgs(function (string $message, array $context) use (&$logged) {
            $logged = $message.json_encode($context);

            return true;
        });

        foreach (['KEYMARKER', base64_encode(str_pad('KEYMARKER', 32, 'x')), 'URLPWMARKER', 'QUERYMARKER', 'URLUSERMARKER', 'MAILURLPWMARKER', 'MAILPWMARKER', 'DBPWMARKER', 'DBURLPWMARKER', 'admin:'] as $secret) {
            expect($text)->not->toContain($secret)->and($logged)->not->toContain($secret);
        }
    });
});

describe('which environment decides (review M-1)', function () {
    it('applies when the detected environment is overridden but the effective configuration is production', function (string $detected) {
        // `artisan queue:work --env=local` replaces the detected environment and leaves the cached production configuration in place.
        rsgProduction(['mail.default' => 'log']);
        app()->detectEnvironment(fn () => $detected);

        try {
            rsgStart('queue:work');
            $refused = null;
        } catch (RuntimeProfileRefused $e) {
            $refused = $e;
        }

        expect($refused)->not->toBeNull()->and($refused->violations)->toHaveCount(1)->and($refused->violations[0])->toContain("mail.default is 'log'");
    })->with(['local', 'LOCAL', 'staging', 'development', 'PRODUCTION', 'Production', 'prod']);

    it('applies when the effective app.env is a differently spelled production, and the profile still rejects that spelling', function (string $configured) {
        rsgProduction(['app.env' => $configured]);
        app()->detectEnvironment(fn () => $configured);

        try {
            rsgStart('schedule:run');
            $refused = null;
        } catch (RuntimeProfileRefused $e) {
            $refused = $e;
        }

        expect($refused)->not->toBeNull()->and($refused->violations)->toHaveCount(1)->and($refused->violations[0])->toContain('app.env is');
    })->with(['Production', 'PRODUCTION', 'pRoDuCtIoN', ' production ']);

    it('applies when only the detected environment says production, and the profile rejects the configured value', function () {
        rsgProduction(['app.env' => 'local']);

        expect(fn () => rsgStart('queue:work'))->toThrow(RuntimeProfileRefused::class, "app.env is 'local'");
    });

    it('does not apply when neither source says production', function (string $detected, string $configured) {
        rsgProduction(['app.env' => $configured, 'cache.default' => 'redis']);
        app()->detectEnvironment(fn () => $detected);
        Log::spy();

        foreach (RSG_GUARDED as $command) {
            rsgStart($command);
        }

        Log::shouldNotHaveReceived('critical');
        expect(true)->toBeTrue();
    })->with([['local', 'local'], ['testing', 'testing'], ['staging', 'staging'], ['development', 'prod']]);

    it('lets a VALID production profile start whatever the detected name is: the override changes the name, not the validated configuration', function () {
        rsgProduction();
        app()->detectEnvironment(fn () => 'local');

        rsgStart('queue:work');

        expect(true)->toBeTrue();
    });

    it('fails closed when it cannot tell whether this is production', function () {
        rsgProduction(['cache.default' => 'redis']);
        app()->detectEnvironment(fn () => 'local');
        $original = app('config');
        app()->instance('config', new class($original->all()) extends Repository
        {
            public function get($key, $default = null)
            {
                if ($key === 'app.env') {
                    throw new RuntimeException('cannot read config for host db-internal-7 password ENVSECRET');
                }

                return parent::get($key, $default);
            }
        });
        Log::spy();

        try {
            rsgStart('queue:work');
            $refused = null;
        } catch (Throwable $e) {
            $refused = $e;
        } finally {
            app()->instance('config', $original);
        }

        expect($refused)->toBeInstanceOf(RuntimeProfileRefused::class)->and($refused->violations)->toBe(['the runtime profile could not be evaluated: RuntimeException'])
            ->and($refused->getMessage())->not->toContain('ENVSECRET')->not->toContain('db-internal-7');
        Log::shouldHaveReceived('critical')->once();
    });
});

describe('real processes', function () {
    /** The cached configuration files this test file built, removed after each test. */
    function rsgCaches(?string $add = null): array
    {
        static $paths = [];

        if ($add !== null) {
            $paths[] = $add;
        }

        return $paths;
    }

    /** The environment of a valid production profile for the shared host. Nothing here reaches a real database: port 1 is closed. */
    function rsgProductionEnvironment(array $override = []): array
    {
        return $override + [
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_TIMEZONE' => 'UTC', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'APP_URL' => 'https://portal.rsg.invalid', 'CACHE_STORE' => 'database', 'CACHE_PREFIX' => 'rsg_', 'SESSION_DRIVER' => 'database',
            'SESSION_SECURE_COOKIE' => 'true', 'QUEUE_CONNECTION' => 'database', 'DB_QUEUE_RETRY_AFTER' => '300', 'MAIL_MAILER' => 'smtp',
            'MAIL_HOST' => 'smtp.rsg.invalid', 'MAIL_PORT' => '587', 'MAIL_TIMEOUT' => '20', 'MAIL_FROM_ADDRESS' => 'portal@rsg.invalid', 'HELPDESK_NOTIFY_ADDRESS' => 'helpdesk@rsg.invalid', 'MAIL_URL' => '',
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_DATABASE' => 'rsg_production', 'DB_USERNAME' => 'rsg',
            'DB_PASSWORD' => 'rsgpw', 'DB_URL' => '', 'LOG_CHANNEL' => 'stderr', 'MAIL_PASSWORD' => 'MAILPWMARKER', 'REDIS_PASSWORD' => 'REDISPWMARKER',
        ];
    }

    /** Build a real `config:cache` file for a production-shaped environment (with `$override` applied) and return its path. */
    function rsgCachedProfile(array $override = []): string
    {
        $path = sys_get_temp_dir().'/rsg-config-'.bin2hex(random_bytes(6)).'.php';
        rsgCaches($path);

        $process = new Process([PHP_BINARY, 'artisan', 'config:cache'], base_path(), ['APP_CONFIG_CACHE' => $path] + rsgProductionEnvironment($override), null, 60);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())->and(is_file($path))->toBeTrue();

        return $path;
    }

    /**
     * Run a real `php artisan` with an explicit environment. A non-existent APP_CONFIG_CACHE (unless one is given) keeps a developer's own
     * configuration cache out of it. No database is touched by a refusal: it precedes any use of one.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    function rsgArtisan(array $arguments, array $environment, ?string $cache = null): array
    {
        $process = new Process([PHP_BINARY, 'artisan', ...$arguments], base_path(), [
            'APP_CONFIG_CACHE' => $cache ?? sys_get_temp_dir().'/rsg-no-config-cache-'.bin2hex(random_bytes(4)).'.php',
            'LOG_CHANNEL' => 'stderr',
            'APP_DEBUG' => 'false',
            'DB_URL' => '',
            'MAIL_PASSWORD' => 'MAILPWMARKER',
            'MAIL_URL' => '',
            'REDIS_PASSWORD' => 'REDISPWMARKER',
        ] + $environment, null, 60);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    }

    afterEach(function () {
        foreach (rsgCaches() as $path) {
            @unlink($path);
        }
    });

    it('exits non-zero, names the rule, logs once and leaks nothing, for each guarded command', function (string $command, array $arguments) {
        [$code, $out, $err] = rsgArtisan($arguments, ['APP_ENV' => 'production', 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'database', 'DB_URL' => 'mysql://u:DBURLPWMARKER@h/d']);

        expect($code)->not->toBe(0)
            ->and($out.$err)->toContain("Refusing to start `{$command}`")->toContain("cache.default is 'file'")
            ->and(substr_count($err, 'production.CRITICAL'))->toBe(1)
            ->and(substr_count($err, 'production.ERROR'))->toBe(0);

        foreach (['MAILPWMARKER', 'DBURLPWMARKER', 'REDISPWMARKER'] as $secret) {
            expect($out.$err)->not->toContain($secret);
        }
    })->with([
        ['queue:work', ['queue:work', 'database', '--once']],
        ['queue:listen', ['queue:listen', 'database']],
        ['schedule:run', ['schedule:run']],
        ['schedule:work', ['schedule:work']],
        'abbreviated queue:work' => ['queue:work', ['queue:wo', 'database', '--once']],
    ]);

    describe('with a CACHED production profile that is invalid (review M-1)', function () {
        it('refuses the same command whatever --env says: the override changes the detected name, not the cached configuration', function (string $override) {
            $cache = rsgCachedProfile(['MAIL_MAILER' => 'log']);

            [$code, $out, $err] = rsgArtisan(array_filter(['queue:work', 'database', '--once', $override]), [], $cache);

            expect($code)->not->toBe(0)
                ->and($out.$err)->toContain('Refusing to start `queue:work`')->toContain("mail.default is 'log'")
                ->and(substr_count($err, '.CRITICAL'))->toBe(1)->and(substr_count($err, '.ERROR'))->toBe(0)
                ->and($out.$err)->not->toContain('MAILPWMARKER')->not->toContain('Connection refused');   // never reached the (closed) database
        })->with(['', '--env=production', '--env=local', '--env=LOCAL', '--env=PRODUCTION', '--env=Production', '--env=staging', '--env=development', '--env=prod']);

        it('refuses the scheduler under an override, and runs no scheduled work', function (string $override) {
            $cache = rsgCachedProfile(['MAIL_MAILER' => 'log']);

            [$code, $out, $err] = rsgArtisan(['schedule:run', $override], [], $cache);

            expect($code)->not->toBe(0)->and($out.$err)->toContain('Refusing to start `schedule:run`')->toContain("mail.default is 'log'")
                ->and($out.$err)->not->toContain('Running [')->not->toContain('Connection refused');
        })->with(['--env=local', '--env=PRODUCTION']);

        it('refuses a cached APP_ENV that is a differently spelled production, and names the profile rule', function (string $spelling) {
            $cache = rsgCachedProfile(['APP_ENV' => $spelling]);

            [$code, $out, $err] = rsgArtisan(['queue:work', 'database', '--once'], [], $cache);

            expect($code)->not->toBe(0)->and($out.$err)->toContain('Refusing to start `queue:work`')->toContain("app.env is '{$spelling}', expected 'production'");
        })->with(['Production', 'PRODUCTION']);

        it('also refuses when the configuration is not cached, from APP_ENV alone, under an override', function () {
            [$code, $out, $err] = rsgArtisan(['queue:work', 'database', '--once', '--env=local'], rsgProductionEnvironment(['CACHE_STORE' => 'file']));

            expect($code)->not->toBe(0)->and($out.$err)->toContain('Refusing to start `queue:work`')->toContain("cache.default is 'file'");
        });
    });

    describe('with a valid CACHED production profile', function () {
        it('does not refuse a guarded command: it gets past the guard (and then meets the closed database)', function (string $override) {
            $cache = rsgCachedProfile();

            [, $out, $err] = rsgArtisan(array_filter(['schedule:run', $override]), [], $cache);

            expect($out.$err)->not->toContain('Refusing to start')->not->toContain('.CRITICAL');
        })->with(['', '--env=local']);

        it('is stopped by the test-database fence under --env=testing, before any command work and without the guard being involved', function (array $arguments) {
            // `--env=testing` makes Laravel believe it is running unit tests, which suppresses CommandStarting: the guard cannot fire. App\Support\
            // TestDatabaseSafety does, while the providers boot, because the cached production database is not the testing database.
            $cache = rsgCachedProfile();

            [$code, $out, $err] = rsgArtisan([...$arguments, '--env=testing'], [], $cache);

            expect($code)->not->toBe(0)
                ->and($out.$err)->toContain('Test database safety check failed before database access')->toContain('resolved APP_ENV="testing" and DB_DATABASE="rsg_production"')
                ->and($out.$err)->not->toContain('Refusing to start')->not->toContain('Connection refused')->not->toContain('Running [');
        })->with([[['queue:work', 'database', '--once']], [['schedule:run']], [['queue:listen', 'database']], [['schedule:work']]]);
    });

    it('does not refuse the same commands in a real local profile, whatever the override', function (string $override) {
        [$code, $out, $err] = rsgArtisan(array_filter(['queue:work', 'sync', '--once', $override]), ['APP_ENV' => 'local', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync']);

        expect($code)->toBe(0)->and($out.$err)->not->toContain('Refusing to start')->not->toContain('.CRITICAL');
    })->with(['', '--env=local', '--env=development']);

    it('leaves other commands usable under the same invalid production environment', function () {
        [$code, $out] = rsgArtisan(['list'], ['APP_ENV' => 'production', 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'database']);

        expect($code)->toBe(0)->and($out)->toContain('runtime:preflight')->not->toContain('Refusing to start');
    });
});
