<?php

namespace App\Support\Runtime;

use App\Support\Permissions\PermissionCacheEpoch;

/**
 * What one running process observes about itself for a cross-context challenge (R0.9 WP3-A, slice A2).
 *
 * Every value is derived from the process that calls {@see self::capture()}: its resolved configuration, the physical release directory it
 * is executing from, and the application key it holds. Nothing is supplied by a caller, which is what makes an observation from the web,
 * the queue worker and the scheduler worth comparing with the CLI's.
 *
 * Three kinds of value, kept apart on purpose:
 *
 *  - `config` (see {@see self::COMPARED}): effective, non-secret configuration. Contexts must agree exactly.
 *  - `release` and `proof`: bound to ONE challenge (its id and nonce), so neither is a reusable fingerprint and neither can be replayed
 *    into another challenge. `release` identifies the physical release directory; `proof` shows possession of the effective APP_KEY.
 *  - `info`: PHP version and SAPI, for the operator's eyes only. They are never compared: the web and CLI legitimately differ in both
 *    (and in `disable_functions`, which is not recorded at all).
 *
 * docs/architecture/runtime-preflight.md.
 */
final class RuntimeSnapshot
{
    public const VERSION = 1;

    /** The effective configuration the contexts must share, as dotted names. Names only are ever reported when they differ. */
    public const COMPARED = [
        'app.env',
        'app.url',
        'cache.default',
        'cache.prefix',
        'cache.table',
        'cache.lock_table',
        'permission.cache.store',
        'permission.cache.key',
        'permission.epoch_key',
        'database.default',
        'queue.default',
        'configuration_cached',
    ];

    private const KEY_PROOF_DOMAIN = "portal.runtime-preflight.key-proof.v1\n";

    private const RELEASE_DOMAIN = "portal.runtime-preflight.release.v1\n";

    /**
     * @param  array{id: string, nonce: string}  $challenge
     * @return array{v: int, challenge: string, context: string, observed_at: int, release: string, proof: string, config: array<string, scalar>, info: array{php: string, sapi: string}}
     */
    public static function capture(array $challenge, string $context): array
    {
        return [
            'v' => self::VERSION,
            'challenge' => $challenge['id'],
            'context' => $context,
            'observed_at' => now()->getTimestamp(),
            'release' => self::release($challenge['nonce']),
            'proof' => self::proof($challenge['id'], $challenge['nonce'], $context),
            'config' => self::config(),
            'info' => ['php' => PHP_VERSION, 'sapi' => PHP_SAPI],
        ];
    }

    /** @return array<string, scalar> */
    public static function config(): array
    {
        return [
            'app.env' => self::text(config('app.env')),
            'app.url' => RuntimeProfile::safeUrl((string) config('app.url')),
            'cache.default' => self::text(config('cache.default')),
            'cache.prefix' => self::text(config('cache.prefix')),
            'cache.table' => self::text(config('cache.stores.database.table')),
            'cache.lock_table' => self::text(config('cache.stores.database.lock_table') ?: 'cache_locks'),
            'permission.cache.store' => self::text(config('permission.cache.store')),
            'permission.cache.key' => self::text(config('permission.cache.key')),
            'permission.epoch_key' => self::text(app(PermissionCacheEpoch::class)->key()),
            'database.default' => self::text(config('database.default')),
            'queue.default' => self::text(config('queue.default')),
            'configuration_cached' => app()->configurationIsCached(),
        ];
    }

    /**
     * The identity of the physical release this process executes from, bound to the challenge nonce.
     *
     * It is derived from the CANONICAL resolved path of the application base directory (`realpath`), never from a symlink's name: a `current`
     * symlink and the release directory it points at give the same identity, and two different release directories give different ones,
     * whatever their configuration says. `base_path()` is fixed when the application boots (from the real location of `bootstrap/app.php`),
     * so a long-lived process that started in an older release keeps reporting that release. Only a hash is stored: no hosting path is.
     *
     * It assumes a release directory is immutable and never reused for another release (docs/architecture/runtime-preflight.md); it can tell
     * releases apart, not two states of one directory.
     */
    public static function release(string $nonce): string
    {
        $real = realpath(base_path());

        return $real === false ? '' : substr(hash('sha256', self::RELEASE_DOMAIN.$nonce."\n".$real), 0, 32);
    }

    /**
     * HMAC-SHA256 over a domain-separated, challenge-and-context-specific message, keyed with the effective application key. The raw key is
     * never stored or printed, nothing about it is stored except this value, and the value is useless for any other challenge or context.
     * Empty when no usable key is configured.
     */
    public static function proof(string $id, string $nonce, string $context): string
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            return '';
        }

        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        if ($raw === false || $raw === '') {
            return '';
        }

        return hash_hmac('sha256', self::KEY_PROOF_DOMAIN.$id."\n".$nonce."\n".$context, $raw);
    }

    /**
     * What differs between an expected snapshot and an observed one: configuration names, `release`, `app_key`. Empty means they agree.
     * `$observed` must already have passed {@see self::wellFormed()}.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $observed
     * @return list<string>
     */
    public static function differences(array $expected, array $observed): array
    {
        $differences = [];

        foreach (self::COMPARED as $name) {
            if (($expected['config'][$name] ?? null) !== ($observed['config'][$name] ?? null)) {
                $differences[] = $name;
            }
        }

        if ($expected['release'] === '' || ! hash_equals((string) $expected['release'], (string) $observed['release'])) {
            $differences[] = 'release';
        }

        if ($expected['proof'] === '' || ! hash_equals((string) $expected['proof'], (string) $observed['proof'])) {
            $differences[] = 'app_key';
        }

        return $differences;
    }

    /** What must be equal for two observations of the same context to be "the same result" (time and info are not part of it). */
    public static function comparable(array $snapshot): array
    {
        return [$snapshot['config'] ?? null, $snapshot['release'] ?? null, $snapshot['proof'] ?? null];
    }

    /**
     * Whether a decoded stored value has exactly the shape {@see self::capture()} produces, for this context. Anything else (a tampered,
     * truncated or foreign row) is not trusted and not compared. Which challenge it belongs to is the caller's question, not this one's.
     *
     * @return array<string, mixed>|null the observation, or null when it is not well formed
     */
    public static function wellFormed(mixed $value, string $context): ?array
    {
        if (! is_array($value) || ($value['v'] ?? null) !== self::VERSION || ($value['context'] ?? null) !== $context) {
            return null;
        }

        if (! is_int($value['observed_at'] ?? null)
            || ! is_string($value['release'] ?? null) || preg_match('/^[0-9a-f]{0,32}$/', $value['release']) !== 1
            || ! is_string($value['proof'] ?? null) || preg_match('/^([0-9a-f]{64})?$/', $value['proof']) !== 1
            || ! is_array($value['config'] ?? null) || ! is_array($value['info'] ?? null)) {
            return null;
        }

        if (array_diff(self::COMPARED, array_keys($value['config'])) !== [] || ! is_string($value['challenge'] ?? null)) {
            return null;
        }

        foreach ($value['config'] as $name => $setting) {
            if (! in_array($name, self::COMPARED, true) || ! (is_string($setting) || is_bool($setting))) {
                return null;
            }
        }

        foreach (['php', 'sapi'] as $name) {
            if (! is_string($value['info'][$name] ?? null) || strlen($value['info'][$name]) > 40) {
                return null;
            }
        }

        return $value;
    }

    private static function text(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : (is_null($value) ? '' : gettype($value));

        return substr($value, 0, 200);
    }
}
