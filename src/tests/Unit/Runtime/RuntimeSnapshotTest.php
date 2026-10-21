<?php

use App\Support\Runtime\RuntimeSnapshot;

require_once __DIR__.'/../../Support/runtime_profile_config.php';

/*
 * Release 0.9 (R0.9 WP3-A, slice A2): what one process observes about itself for a challenge: the compared configuration, the
 * challenge-bound release identity and APP_KEY proof, and the shape check that decides whether a stored observation is trusted at all.
 * No database. docs/architecture/runtime-preflight.md.
 */

const RSN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const RSN_NONCE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

function rsnChallenge(string $id = RSN_ID, string $nonce = RSN_NONCE): array
{
    return ['id' => $id, 'nonce' => $nonce, 'issued_at' => 1000, 'expires_at' => 2800];
}

/** The snapshot a process with this configuration (and, optionally, this base path) would make. */
function rsnCapture(string $context = 'web', array $config = [], ?string $basePath = null, array $challenge = []): array
{
    $keep = [];
    foreach ($config as $name => $value) {
        $keep[$name] = config($name);
    }
    $originalBase = app()->basePath();

    config($config);
    if ($basePath !== null) {
        app()->setBasePath($basePath);
    }

    try {
        return RuntimeSnapshot::capture(rsnChallenge(...$challenge), $context);
    } finally {
        app()->setBasePath($originalBase);
        config($keep);
    }
}

beforeEach(fn () => config(rtpProductionConfig()));

it('derives every value from the running process and names its context', function () {
    $snapshot = rsnCapture('queue');

    expect($snapshot['context'])->toBe('queue')->and($snapshot['challenge'])->toBe(RSN_ID)->and($snapshot['v'])->toBe(1)
        ->and($snapshot['config']['cache.prefix'])->toBe('portal_prod_')
        ->and($snapshot['config']['permission.epoch_key'])->toBe('portal_prod_spatie.permission.epoch')
        ->and($snapshot['config']['app.url'])->toBe('https://portal.provider.invalid')
        ->and(array_keys($snapshot['config']))->toBe(RuntimeSnapshot::COMPARED)
        ->and($snapshot['info'])->toBe(['php' => PHP_VERSION, 'sapi' => PHP_SAPI]);
});

it('gives every context of the same process the same configuration and release, and its own proof', function () {
    $web = rsnCapture('web');
    $queue = rsnCapture('queue');

    expect($web['config'])->toBe($queue['config'])->and($web['release'])->toBe($queue['release'])->and($web['proof'])->not->toBe($queue['proof'])
        ->and(RuntimeSnapshot::differences(rsnCapture('web'), $web))->toBe([])
        ->and(RuntimeSnapshot::differences(rsnCapture('queue'), $queue))->toBe([]);
});

describe('APP_KEY proof', function () {
    it('matches for the same effective key and differs for another', function () {
        $expected = rsnCapture('web');

        expect(RuntimeSnapshot::differences($expected, rsnCapture('web')))->toBe([])
            ->and(RuntimeSnapshot::differences($expected, rsnCapture('web', ['app.key' => 'base64:'.base64_encode(str_repeat('z', 32))])))->toBe(['app_key']);
    });

    it('is bound to the challenge: a proof from an earlier challenge, id or nonce, does not match a new one', function () {
        $old = rsnCapture('web');

        expect(RuntimeSnapshot::differences(rsnCapture('web', challenge: [str_repeat('c', 32)]), $old))->toContain('app_key')
            ->and(RuntimeSnapshot::differences(rsnCapture('web', challenge: [RSN_ID, str_repeat('d', 64)]), $old))->toContain('app_key');
    });

    it('is bound to the context, so a proof cannot be copied from one context to another', function () {
        expect(RuntimeSnapshot::proof(RSN_ID, RSN_NONCE, 'web'))->not->toBe(RuntimeSnapshot::proof(RSN_ID, RSN_NONCE, 'queue'));
    });

    it('is a 64-hex HMAC that contains neither the key nor its base64 form, and is empty without a key', function () {
        $proof = RuntimeSnapshot::proof(RSN_ID, RSN_NONCE, 'web');
        $raw = str_pad('KEYMARKER', 32, 'x');

        expect($proof)->toMatch('/^[0-9a-f]{64}$/')->not->toContain('KEYMARKER')
            ->and($proof)->toBe(hash_hmac('sha256', "portal.runtime-preflight.key-proof.v1\n".RSN_ID."\n".RSN_NONCE."\nweb", $raw));

        config(['app.key' => '']);
        expect(RuntimeSnapshot::proof(RSN_ID, RSN_NONCE, 'web'))->toBe('');
        config(['app.key' => 'base64:!!!']);
        expect(RuntimeSnapshot::proof(RSN_ID, RSN_NONCE, 'web'))->toBe('');
    });

    it('never matches an empty expected proof, so a verifier without a key cannot pass', function () {
        $observed = rsnCapture('web');
        $expected = [...$observed, 'proof' => ''];

        expect(RuntimeSnapshot::differences($expected, $observed))->toBe(['app_key']);
    });

    it('does not change the application encryption key', function () {
        $before = config('app.key');
        rsnCapture('web');

        expect(config('app.key'))->toBe($before);
    });
});

describe('release identity', function () {
    beforeEach(function () {
        $this->root = sys_get_temp_dir().'/rsn-release-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/releases/a', 0777, true);
        mkdir($this->root.'/releases/b');
    });

    afterEach(function () {
        foreach (['current', 'releases/a', 'releases/b', 'releases'] as $entry) {
            is_link($this->root.'/'.$entry) ? unlink($this->root.'/'.$entry) : @rmdir($this->root.'/'.$entry);
        }
        @rmdir($this->root);
    });

    it('is the same for the same physical release, whatever the context, and for a symlink to it', function () {
        symlink($this->root.'/releases/a', $this->root.'/current');

        $direct = rsnCapture('web', basePath: $this->root.'/releases/a');
        $viaSymlink = rsnCapture('scheduler', basePath: $this->root.'/current');

        expect($direct['release'])->toBe($viaSymlink['release'])->and(RuntimeSnapshot::differences($direct, $viaSymlink))->not->toContain('release');
    });

    it('differs between two physical releases even when every configuration value matches', function () {
        $a = rsnCapture('web', basePath: $this->root.'/releases/a');
        $b = rsnCapture('web', basePath: $this->root.'/releases/b');

        expect($a['config'])->toBe($b['config'])->and($a['proof'])->toBe($b['proof'])
            ->and(RuntimeSnapshot::differences($a, $b))->toBe(['release']);
    });

    it('follows the symlink: after the swap the same name is another release', function () {
        symlink($this->root.'/releases/a', $this->root.'/current');
        $before = rsnCapture('web', basePath: $this->root.'/current');

        unlink($this->root.'/current');
        symlink($this->root.'/releases/b', $this->root.'/current');
        $after = rsnCapture('web', basePath: $this->root.'/current');

        expect(RuntimeSnapshot::differences($before, $after))->toBe(['release']);
    });

    it('is bound to the challenge and exposes no path', function () {
        $a = rsnCapture('web', basePath: $this->root.'/releases/a');
        $other = rsnCapture('web', basePath: $this->root.'/releases/a', challenge: [RSN_ID, str_repeat('e', 64)]);

        expect($a['release'])->toMatch('/^[0-9a-f]{32}$/')->not->toBe($other['release'])
            ->and(json_encode($a))->not->toContain('rsn-release')->not->toContain(sys_get_temp_dir());
    });

    it('is empty, and so never matches, when the base path cannot be resolved', function () {
        $snapshot = rsnCapture('web', basePath: $this->root.'/gone');

        expect($snapshot['release'])->toBe('')->and(RuntimeSnapshot::differences($snapshot, $snapshot))->toBe(['release']);
    });
});

describe('compared configuration', function () {
    it('reports each differing value by name only', function (array $override, array $names) {
        $differences = RuntimeSnapshot::differences(rsnCapture('web'), rsnCapture('web', $override));

        expect($differences)->toBe($names);
    })->with([
        'prefix' => [['cache.prefix' => 'other_'], ['cache.prefix', 'permission.epoch_key']],
        'app url' => [['app.url' => 'https://other.provider.invalid'], ['app.url']],
        'cache table' => [['cache.stores.database.table' => 'cache2'], ['cache.table']],
        'lock table' => [['cache.stores.database.lock_table' => 'locks'], ['cache.lock_table']],
        'permission store' => [['permission.cache.store' => 'redis'], ['permission.cache.store']],
        'permission key' => [['permission.cache.key' => 'other.permission.cache'], ['permission.cache.key']],
        'cache store' => [['cache.default' => 'redis'], ['cache.default']],
        'database default' => [['database.default' => 'mariadb'], ['database.default']],
        'queue default' => [['queue.default' => 'sync'], ['queue.default']],
        'environment' => [['app.env' => 'staging'], ['app.env']],
    ]);

    it('ignores the credentials, query and fragment of the URL', function () {
        expect(RuntimeSnapshot::differences(rsnCapture('web'), rsnCapture('web', ['app.url' => 'https://u:p@portal.provider.invalid?x=1#y'])))->toBe([]);
    });

    it('does not compare the PHP version or SAPI', function () {
        $expected = rsnCapture('web');
        $observed = [...$expected, 'info' => ['php' => '7.4.33', 'sapi' => 'fpm-fcgi']];

        expect(RuntimeSnapshot::differences($expected, $observed))->toBe([])->and(RuntimeSnapshot::COMPARED)->not->toContain('php')->not->toContain('sapi');
    });
});

describe('shape check', function () {
    it('accepts what capture produces', function () {
        $snapshot = json_decode(json_encode(rsnCapture('web')), true);

        expect(RuntimeSnapshot::wellFormed($snapshot, 'web'))->toBe($snapshot);
    });

    it('trusts nothing else', function (Closure $tamper) {
        $snapshot = json_decode(json_encode(rsnCapture('web')), true);

        expect(RuntimeSnapshot::wellFormed($tamper($snapshot), 'web'))->toBeNull();
    })->with([
        'not an array' => [fn ($s) => 'text'],
        'wrong version' => [function ($s) {
            $s['v'] = 2;

            return $s;
        }],
        'another context' => [function ($s) {
            $s['context'] = 'queue';

            return $s;
        }],
        'observed_at as text' => [function ($s) {
            $s['observed_at'] = 'now';

            return $s;
        }],
        'release too long' => [function ($s) {
            $s['release'] = str_repeat('a', 33);

            return $s;
        }],
        'release not hex' => [function ($s) {
            $s['release'] = 'zz';

            return $s;
        }],
        'proof not hex' => [function ($s) {
            $s['proof'] = str_repeat('g', 64);

            return $s;
        }],
        'proof too short' => [function ($s) {
            $s['proof'] = 'abcd';

            return $s;
        }],
        'config missing a value' => [function ($s) {
            unset($s['config']['cache.prefix']);

            return $s;
        }],
        'config with an extra value' => [function ($s) {
            $s['config']['evil'] = 'x';

            return $s;
        }],
        'config value an array' => [function ($s) {
            $s['config']['app.url'] = ['x'];

            return $s;
        }],
        'config value a number' => [function ($s) {
            $s['config']['app.env'] = 5;

            return $s;
        }],
        'info missing' => [function ($s) {
            unset($s['info']);

            return $s;
        }],
        'info sapi too long' => [function ($s) {
            $s['info']['sapi'] = str_repeat('x', 41);

            return $s;
        }],
        'challenge missing' => [function ($s) {
            unset($s['challenge']);

            return $s;
        }],
    ]);
});
