<?php

namespace App\Support\Runtime;

use App\Support\Database\DatabaseProfile;
use Throwable;

/**
 * The verifying half of the cross-context runtime check (R0.9 WP3-A, slice A2): does the current challenge hold a trustworthy observation
 * from EVERY required context ({@see RuntimeChallenges::CONTEXTS}), and does each agree with the process that is verifying?
 *
 * The verifying process is the reference. Its own effective configuration, release identity and key proof are recomputed now for the
 * challenge's nonce and compared with what each context stored: nothing stored by the CLI at issue time is taken on trust, so a verifier
 * that is itself in another release or configuration fails too. A context passes only when its observation exists, is well formed, belongs
 * to this challenge, was made inside the challenge's window, was reported with a single result, and equals the reference on every compared
 * configuration value, the release identity and the APP_KEY proof ({@see RuntimeSnapshot}).
 *
 * This class only READS: the challenge, observation and conflict rows, in that order per context except that a context's conflict marker is read BEFORE its
 * observation (see {@see self::context()}). The COMMAND `runtime:preflight --verify` also runs the A1 local verification first, and that is not
 * read-only: its live probes create and remove one temporary cache row and one temporary cache lock row ({@see RuntimeProbes}).
 * Every failure is categorical and secret-safe: configuration names, never values; no exception text.
 *
 * What it proves: the contexts that actually answered THIS challenge. It does not prove that no other, stale worker or pool exists.
 */
final class RuntimeVerification
{
    private const HOW = [
        'cli' => 'run `php artisan runtime:preflight --issue` again',
        'web' => 'request GET /up on the production URL',
        'queue' => 'run the bounded queue worker once, e.g. `php artisan queue:work --stop-when-empty --max-jobs=1`',
        'scheduler' => 'run `php artisan schedule:run`',
    ];

    /**
     * @return array{status: string, challenge: array{id: string, issued_at: int, expires_at: int, seconds_remaining: int}|null, contexts: array<string, array{status: string, differences: list<string>, observed_at: int|null, php: string|null, sapi: string|null}>, violations: list<string>}
     */
    public static function run(?string $wanted = null): array
    {
        try {
            return self::evaluate($wanted);
        } catch (Throwable $e) {
            return ['status' => 'unreadable', 'challenge' => null, 'contexts' => [], 'violations' => ['the challenge state could not be read: '.DatabaseProfile::describe($e)]];
        }
    }

    private static function evaluate(?string $wanted): array
    {
        $report = ['status' => 'none', 'challenge' => null, 'contexts' => [], 'violations' => []];

        if ($wanted !== null && preg_match('/^[0-9a-f]{32}$/', $wanted) !== 1) {
            return [...$report, 'status' => 'invalid', 'violations' => ['the challenge id is not a valid challenge id']];
        }

        ['state' => $state, 'challenge' => $challenge] = RuntimeChallenges::current();

        if ($challenge === null) {
            $report['status'] = $state;
            $report['violations'][] = $state === 'invalid'
                ? 'the stored challenge is malformed and was not trusted; issue a new one with `php artisan runtime:preflight --issue`'
                : 'no challenge is stored; issue one with `php artisan runtime:preflight --issue`';

            return $report;
        }

        $now = now()->getTimestamp();
        $report['challenge'] = ['id' => $challenge['id'], 'issued_at' => $challenge['issued_at'], 'expires_at' => $challenge['expires_at'], 'seconds_remaining' => max(0, $challenge['expires_at'] - $now)];

        if ($wanted !== null && $wanted !== $challenge['id']) {
            $report['status'] = 'superseded';
            $report['violations'][] = 'the requested challenge was superseded by a newer one; its observations do not count';

            return $report;
        }

        if ($state === 'expired') {
            $report['status'] = 'expired';
            $report['violations'][] = 'the challenge has expired; issue a new one with `php artisan runtime:preflight --issue`';

            return $report;
        }

        $report['status'] = 'active';

        foreach (RuntimeChallenges::CONTEXTS as $context) {
            $result = self::context($challenge, $context);
            $report['contexts'][$context] = $result;

            if ($result['status'] !== 'ok') {
                $report['violations'][] = self::message($context, $result);
            }
        }

        return $report;
    }

    /**
     * @param  array{id: string, nonce: string, issued_at: int, expires_at: int}  $challenge
     * @return array{status: string, differences: list<string>, observed_at: int|null, php: string|null, sapi: string|null}
     */
    private static function context(array $challenge, string $context): array
    {
        $result = ['status' => 'missing', 'differences' => [], 'observed_at' => null, 'php' => null, 'sapi' => null];

        // The conflict marker is read BEFORE the observation, on purpose (review L1). A concurrent `--issue` deletes a challenge's observations
        // and then its conflict markers, in one transaction, so once the issuance has committed neither is visible. Read the other way round,
        // an observation read just before that commit would be paired with a marker read just after it: the marker would look absent and a
        // conflicted context would pass. Marker first, a marker that is gone implies that the observation is gone too, which is "missing".
        $conflicted = RuntimeChallenges::hasConflict($challenge['id'], $context);
        $raw = RuntimeChallenges::observationRow($challenge['id'], $context);

        if ($raw === null) {
            return $result;
        }

        $observed = RuntimeSnapshot::wellFormed(RuntimeChallenges::decode($raw), $context);

        if ($observed === null) {
            return [...$result, 'status' => 'malformed'];
        }

        $result['observed_at'] = $observed['observed_at'];
        $result['php'] = $observed['info']['php'];
        $result['sapi'] = $observed['info']['sapi'];

        if ($observed['challenge'] !== $challenge['id'] || $observed['observed_at'] < $challenge['issued_at'] || $observed['observed_at'] > $challenge['expires_at']) {
            return [...$result, 'status' => 'stale'];
        }

        if ($conflicted) {
            return [...$result, 'status' => 'conflict'];
        }

        $differences = RuntimeSnapshot::differences(RuntimeSnapshot::capture($challenge, $context), $observed);

        return [...$result, 'status' => $differences === [] ? 'ok' : 'mismatch', 'differences' => $differences];
    }

    /** @param  array{status: string, differences: list<string>}  $result */
    private static function message(string $context, array $result): string
    {
        return match ($result['status']) {
            'missing' => "{$context}: no observation was recorded for this challenge (".self::HOW[$context].')',
            'malformed' => "{$context}: the stored observation is malformed and was not trusted (".self::HOW[$context].')',
            'stale' => "{$context}: the stored observation does not belong to this challenge or was made outside its window",
            'conflict' => "{$context}: it was observed more than once with differing results (another worker or pool is configured differently)",
            'mismatch' => "{$context}: differs from this process in: ".implode(', ', array_map(self::describe(...), $result['differences'])),
            default => "{$context}: {$result['status']}",
        };
    }

    private static function describe(string $difference): string
    {
        return match ($difference) {
            'release' => 'release (a different physical release directory)',
            'app_key' => 'app_key (a different APP_KEY)',
            default => $difference,
        };
    }
}
