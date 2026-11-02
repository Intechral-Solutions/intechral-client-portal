<?php

namespace App\Support\Backup;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Connection;
use Throwable;

/**
 * Proves a restored installation equals the set it came from (Release 0.9 WP-B1). Read-only.
 *
 *  - Database: the restored database is dumped again with the same `mariadb-dump` options and read with the same
 *    {@see DumpReader}. Every table must have the same definition and, row for row, the same content (digest) as the
 *    manifest: so the same records, the same numeric keys and therefore the same associations (replies, status
 *    histories, tasks, time entries and attachments to their tickets), the same ticket references, the same
 *    migrations. With $strict false (a re-check after the recovery's own cleanup), the volatile tables
 *    ({@see RestoreLoader::VOLATILE_TABLES}) are compared by definition only and their row counts reported.
 *  - Files: `tickets/` holds exactly the manifest's files (size and SHA-256), in their ticket directories, and nothing
 *    else; no links.
 *  - APP_KEY: the key supplied decrypts the canary made with the original key.
 *
 * The ticket identity fingerprint compared here comes from the restored copy, never from a live query elsewhere.
 */
final class RestoredState
{
    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $ignoreTables  tables of the target that are not part of the set (the drill's marker)
     * @return array{ok: bool, violations: list<string>, facts: array<string, mixed>}
     */
    public static function compare(
        string $openedDir,
        array $manifest,
        array $config,
        Connection $connection,
        string $privateRoot,
        ?Encrypter $key,
        bool $strict,
        array $ignoreTables = [],
    ): array {
        $violations = [];
        $facts = [];
        $work = RestoreLoader::workDirectory($openedDir, 'redump');

        try {
            $ignore = array_map(fn (string $t) => '--ignore-table='.$config['database'].'.'.$t, $ignoreTables);
            $result = MariaDbClient::run('mariadb-dump', [
                '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', '--order-by-primary',
                '--complete-insert', '--hex-blob', '--default-character-set=utf8mb4', '--tz-utc', '--triggers',
                ...$ignore, '--', (string) $config['database'],
            ], $config, null, $work.'/restored.sql');

            if ($result['exit'] !== 0) {
                $violations[] = 'the restored database could not be dumped for comparison: '.$result['stderr'];
                $dump = null;
            } else {
                $dump = DumpReader::read($work.'/restored.sql');
            }
        } finally {
            BackupSet::removeTree($work);
        }

        if ($dump !== null) {
            $relaxed = $strict ? [] : RestoreLoader::VOLATILE_TABLES;
            $violations = [...$violations, ...array_map(
                fn (string $v) => str_replace('dump: ', 'restored database: ', $v),
                SetVerifier::dumpViolations($dump, $manifest, $relaxed)
            )];

            if (! $strict) {
                foreach (RestoreLoader::VOLATILE_TABLES as $table) {
                    if (isset($dump['tables'][$table])) {
                        $facts['rows_now'][$table] = $dump['tables'][$table]['rows'];
                        $facts['rows_in_set'][$table] = $manifest['database']['tables'][$table]['rows'] ?? null;
                    }
                }
            }

            $facts['tables'] = count($dump['tables']);
            $facts['rows'] = array_sum(array_column($dump['tables'], 'rows'));
            $facts['tickets'] = $dump['tickets'];
            $facts['attachments'] = count($dump['attachments']);
            $facts['migrations'] = $dump['migrations']['count'];
        }

        $violations = [...$violations, ...self::fileViolations($privateRoot, $manifest['attachments'] ?? [])];

        if ($key === null) {
            $violations[] = 'APP_KEY: no key was supplied, so the restored key is not verified';
        } else {
            $facts['app_key_id_matches'] = BackupCreator::keyId($key->getKey()) === ($manifest['app_key']['key_id'] ?? null);
            try {
                $ok = hash('sha256', $key->decryptString((string) ($manifest['app_key']['canary'] ?? ''))) === ($manifest['app_key']['canary_sha256'] ?? null);
            } catch (Throwable) {
                $ok = false;
            }
            if (! $ok) {
                $violations[] = 'APP_KEY: the supplied key does not decrypt the canary made with the original key';
            }
        }

        $facts['queue'] = self::queue($connection);

        return ['ok' => $violations === [], 'violations' => $violations, 'facts' => $facts];
    }

    /**
     * @param  list<array<string, mixed>>  $attachments
     * @return list<string>
     */
    public static function fileViolations(string $privateRoot, array $attachments): array
    {
        $root = rtrim($privateRoot, '/').'/tickets';

        if ($attachments !== [] && (is_link($root) || ! is_dir($root))) {
            return ['files: tickets/ is missing or is a symbolic link'];
        }

        $inventory = [];
        $directories = [];
        $violations = [];

        foreach (BackupSet::tree($root) as $path => $type) {
            if ($type === 'dir') {
                if (str_contains($path, '/')) {
                    $violations[] = "files: unexpected directory tickets/{$path}";
                } else {
                    $directories[] = $path;
                }
            } elseif ($type === 'file') {
                $inventory['tickets/'.$path] = ['size' => (int) filesize($root.'/'.$path), 'sha256' => (string) hash_file('sha256', $root.'/'.$path)];
            } else {
                $violations[] = "files: tickets/{$path} is not a regular file ({$type})";
            }
        }

        return [...$violations, ...array_map(
            fn (string $v) => str_replace('attachments: ', 'files: ', $v),
            SetVerifier::inventoryViolations($inventory, $directories, $attachments)
        )];
    }

    /**
     * What the operator must review before queue processing resumes: queued jobs by class, and failed jobs. Names
     * only, never payloads.
     *
     * @return array{jobs: array<string, int>, failed_jobs: int}
     */
    private static function queue(Connection $connection): array
    {
        $jobs = [];

        try {
            foreach ($connection->table('jobs')->orderBy('id')->pluck('payload') as $payload) {
                $name = (string) (json_decode((string) $payload, true)['displayName'] ?? 'unknown');
                $jobs[$name] = ($jobs[$name] ?? 0) + 1;
            }
            $failed = (int) $connection->table('failed_jobs')->count();
        } catch (Throwable) {
            return ['jobs' => ['(queue tables unreadable)' => -1], 'failed_jobs' => -1];
        }

        ksort($jobs);

        return ['jobs' => $jobs, 'failed_jobs' => $failed];
    }
}
