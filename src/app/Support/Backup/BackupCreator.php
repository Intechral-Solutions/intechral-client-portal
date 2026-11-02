<?php

namespace App\Support\Backup;

use Closure;
use Illuminate\Contracts\Encryption\Encrypter;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Creates one identified, encrypted backup set (Release 0.9 WP-B1; format: {@see BackupSet}).
 *
 * Consistency. The database is dumped first by `mariadb-dump --single-transaction` (one consistent InnoDB snapshot).
 * The attachment rows are then read FROM THAT DUMP ({@see DumpReader}), and exactly the files those rows name are
 * copied, so the files correspond to the snapshot by construction, not by timing:
 *
 *  - an upload that commits after the snapshot is not in the dump, so its file is not copied (it is counted as a
 *    live file outside the snapshot, for information);
 *  - an upload in flight at the snapshot has written its file but not committed its row: likewise not copied;
 *  - every row in the dump committed before the snapshot, and the application writes a file before inserting its
 *    row (TicketService::storeAttachment), so each referenced file existed when the snapshot was taken.
 *
 * What could still make the copy differ from the snapshot is a committed file deleted or changed after it. The
 * application never does that in ordinary operation (guarded by tests/Unit/Backup/CommittedAttachmentFilesTest.php;
 * the one bulk deleter, `e2e:cleanup-tickets`, refuses outside local and testing). It is detected instead of
 * assumed: a missing file, a symbolic link, a size that differs from its row, a change time in a later second
 * than the snapshot began, or a file that changes while it is read FAILS the backup. A failed or interrupted backup never looks
 * complete: the set is built in `.partial-<id>/`, renamed to `<id>/` only after COMPLETE is written and the sealed
 * set re-verified, and any `.partial-*` left by a killed run is removed by the next run.
 *
 * Secrets. Database credentials go to `mariadb-dump` on a pipe ({@see MariaDbClient}). The APP_KEY is never
 * written: the manifest holds a short key id and a canary encrypted with it. Plaintext exists only in the private
 * `.partial-<id>/plain/` directory (0700, files 0600) and is removed before the set is completed, or when it fails.
 */
final class BackupCreator
{
    public const LOCK_FILE = '.backup.lock';

    public const PARTIAL_PREFIX = '.partial-';

    public const PARTIAL_MARKER = '.portal-backup-partial';

    /** How many live files outside the snapshot are listed by path (all are counted). */
    private const LIVE_SAMPLE = 50;

    private ?string $partial = null;

    /** @var resource|null */
    private $lock = null;

    /** @var list<string> */
    private array $log = [];

    /**
     * @param  array<string, mixed>  $database  the source connection configuration (credentials never leave this process except on a pipe)
     * @param  string  $privateRoot  the private disk root holding `tickets/{id}/…`
     * @param  array{id: ?string, commit: ?string}  $release
     * @param  Closure(string): void|null  $progress
     * @param  Closure(array<string, mixed>): void|null  $afterSnapshot  test hook: runs after the dump is read, before files are copied
     * @param  list<string>  $ignoreTables  source tables that are not part of the installation (the tests' rehearsal marker)
     */
    public function __construct(
        private readonly array $database,
        private readonly string $privateRoot,
        private readonly Encrypter $encrypter,
        private readonly string $cipher,
        private readonly string $appEnvironment,
        private readonly array $release,
        private readonly ?Closure $progress = null,
        private readonly ?Closure $afterSnapshot = null,
        private readonly array $ignoreTables = [],
    ) {}

    /**
     * Make a set under $root, encrypted to the key in $keyFile, which must have $fingerprint.
     *
     * @return array{backup_id: string, directory: string, digest: string, attachments: int, live_files_outside_snapshot: int, swept: list<string>}
     *
     * @throws BackupRefused before anything is dumped (unsafe root, another backup running, wrong key)
     * @throws RuntimeException when the backup fails after it began; nothing complete is left behind
     */
    public function create(string $root, string $keyFile, string $fingerprint): array
    {
        $root = $this->checkRoot($root);
        $previousUmask = umask(0077);
        $completed = false;
        register_shutdown_function(function () use (&$completed): void {
            // A fatal error skips finally blocks: remove the plaintext here too.
            if (! $completed && $this->partial !== null) {
                BackupSet::removeTree($this->partial);
            }
        });

        try {
            $this->lock($root);
            $swept = $this->sweep($root);

            $id = gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(4));
            $this->partial = $root.'/'.self::PARTIAL_PREFIX.$id;
            BackupSet::makePrivateDirectory($this->partial);
            BackupSet::writePrivate($this->partial.'/'.self::PARTIAL_MARKER, $id."\n");
            BackupSet::makePrivateDirectory($this->partial.'/plain');
            BackupSet::makePrivateDirectory($this->partial.'/plain/files');
            BackupSet::makePrivateDirectory($this->partial.'/plain/files/tickets');
            BackupSet::makePrivateDirectory($this->partial.'/gnupg');

            $gpg = new Gpg($this->partial.'/gnupg');
            try {
                $recipient = $gpg->recipient($keyFile, $fingerprint);
            } catch (RuntimeException $e) {
                throw new BackupRefused($e->getMessage(), previous: $e);
            }

            $result = $this->build($id, $gpg, $keyFile, $recipient);
            $completed = true;

            return [...$result, 'swept' => $swept];
        } finally {
            if (! $completed && $this->partial !== null && ! BackupSet::removeTree($this->partial)) {
                $this->say('WARNING: the partial set could not be fully removed: '.basename($this->partial).' (the next backup run removes it)');
            }
            $this->partial = null;
            umask($previousUmask);
            if ($this->lock !== null) {
                flock($this->lock, LOCK_UN);
                fclose($this->lock);
                $this->lock = null;
            }
        }
    }

    /** @return list<string> the progress lines so far */
    public function log(): array
    {
        return $this->log;
    }

    /**
     * @param  array{fingerprint: string, encryption_keyids: list<string>}  $recipient
     * @return array<string, mixed>
     */
    private function build(string $id, Gpg $gpg, string $keyFile, array $recipient): array
    {
        $plain = $this->partial.'/plain';
        $times = ['started_at' => self::now()];

        // 1. The database: one consistent snapshot.
        // Start at the beginning of a whole second (file times are whole seconds): then a file whose row the snapshot sees
        // was written in that second or earlier, unless connecting takes longer than the rest of the second (fails closed).
        for ($second = time(); time() === $second;) {
            usleep(5_000);
        }
        $snapshotStarted = time();
        $times['snapshot_started_at'] = gmdate('Y-m-d\TH:i:s\Z', $snapshotStarted);
        $dump = MariaDbClient::run('mariadb-dump', [
            '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', '--order-by-primary',
            '--complete-insert', '--hex-blob', '--default-character-set=utf8mb4', '--tz-utc', '--triggers',
            ...array_map(fn (string $t) => '--ignore-table='.$this->database['database'].'.'.$t, $this->ignoreTables),
            '--', (string) $this->database['database'],
        ], $this->database, null, $plain.'/database.sql');
        $times['snapshot_finished_at'] = self::now();

        if ($dump['exit'] !== 0) {
            throw new RuntimeException('mariadb-dump failed (exit '.$dump['exit'].'): '.$dump['stderr']);
        }

        $summary = DumpReader::read($plain.'/database.sql');
        $this->assertDump($summary);
        $this->say(sprintf('Database snapshot: %d tables, %d rows, %d tickets, %d attachment rows (%.1f s)',
            count($summary['tables']), array_sum(array_column($summary['tables'], 'rows')), $summary['tickets']['count'],
            count($summary['attachments']), $dump['seconds']));

        if ($this->afterSnapshot !== null) {
            ($this->afterSnapshot)($summary);
        }

        // 2. Exactly the files the snapshot's attachment rows reference.
        $attachments = $this->copyAttachments($summary['attachments'], $plain.'/files', $snapshotStarted);
        $live = $this->liveFilesOutsideSnapshot($summary['attachments']);
        $times['files_finished_at'] = self::now();
        $this->say(sprintf('Attachments: %d files, %d bytes copied; %d live file(s) are not in the snapshot',
            count($attachments), array_sum(array_column($attachments, 'size')), $live['count']));

        // 3. The manifest, derived from the dump, and the APP_KEY canary (never the key).
        $nonce = 'intechral-portal-backup-canary:'.$id.':'.bin2hex(random_bytes(16));
        $manifest = [
            'format' => BackupSet::FORMAT,
            'backup_id' => $id,
            'times' => $times,
            'source' => [
                'app_env' => $this->appEnvironment,
                'database' => (string) $this->database['database'],
                'server_version' => $summary['server_version'],
                'release_id' => $this->release['id'] ?? null,
                'release_commit' => $this->release['commit'] ?? null,
            ],
            'database' => [
                'schema_sha256' => $summary['schema_sha256'],
                'tables' => $summary['tables'],
                'tickets' => $summary['tickets'],
                'migrations' => $summary['migrations'],
            ],
            'attachments' => $attachments,
            'live_files_outside_snapshot' => $live,
            'app_key' => [
                'key_id' => self::keyId($this->encrypter->getKey()),
                'cipher' => $this->cipher,
                'canary' => $this->encrypter->encryptString($nonce),
                'canary_sha256' => hash('sha256', $nonce),
            ],
        ];
        BackupSet::writePrivate($plain.'/'.BackupSet::MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        // 4. Compress, encrypt, confirm the recipients, and remove each plaintext file as soon as it is no longer needed
        //    (disk and inodes are capped on shared hosting).
        $this->tool(['gzip', '-n', '--', $plain.'/database.sql']);
        $this->tool(['tar', '--create', '--gzip', '--file', $plain.'/'.BackupSet::ATTACHMENTS, '--directory', $plain.'/files',
            '--sort=name', '--owner=0', '--group=0', '--numeric-owner', '--format=gnu', 'tickets']);
        if (! BackupSet::removeTree($plain.'/files')) {
            throw new RuntimeException('The plaintext attachment copy could not be removed.');
        }

        $artifacts = [];
        foreach (BackupSet::PAYLOAD as $name) {
            $encrypted = $this->partial.'/'.$name.'.gpg';
            $gpg->encrypt($plain.'/'.$name, $encrypted, $keyFile);
            $addressed = $gpg->recipientsOf($encrypted);
            if ($addressed === [] || array_diff($addressed, $recipient['encryption_keyids']) !== []) {
                throw new RuntimeException("{$name}.gpg is not addressed only to the backup key.");
            }
            $artifacts[$name.'.gpg'] = [
                'bytes' => filesize($encrypted),
                'sha256' => hash_file('sha256', $encrypted),
                'plaintext_bytes' => filesize($plain.'/'.$name),
                'plaintext_sha256' => hash_file('sha256', $plain.'/'.$name),
            ];
            if (! @unlink($plain.'/'.$name)) {
                throw new RuntimeException("The plaintext {$name} could not be removed.");
            }
        }

        if (! BackupSet::removeTree($plain) || ! BackupSet::removeTree($this->partial.'/gnupg')) {
            throw new RuntimeException('The plaintext working files could not be removed.');
        }

        // 5. Seal: set.json, SHA256SUMS, then COMPLETE last; verify; publish by renaming.
        $set = [
            'format' => BackupSet::FORMAT,
            'backup_id' => $id,
            'started_at' => $times['started_at'],
            'snapshot_finished_at' => $times['snapshot_finished_at'],
            'completed_at' => self::now(),
            'source' => ['app_env' => $this->appEnvironment, 'release_id' => $this->release['id'] ?? null, 'release_commit' => $this->release['commit'] ?? null],
            'recipient' => $recipient,
            'artifacts' => $artifacts,
        ];
        BackupSet::writePrivate($this->partial.'/'.BackupSet::SET_FILE, json_encode($set, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        $sums = '';
        foreach (array_values(array_diff(BackupSet::sealedFiles(), [BackupSet::SUMS, BackupSet::COMPLETE])) as $name) {
            $sums .= hash_file('sha256', $this->partial.'/'.$name).'  '.$name."\n";
        }
        BackupSet::writePrivate($this->partial.'/'.BackupSet::SUMS, $sums);
        $digest = hash('sha256', $sums);

        // The marker goes before COMPLETE, so the set directory never holds an unexpected entry once it is complete.
        if (! @unlink($this->partial.'/'.self::PARTIAL_MARKER)) {
            throw new RuntimeException('The partial marker could not be removed.');
        }
        BackupSet::writePrivate($this->partial.'/'.BackupSet::COMPLETE, 'format='.BackupSet::FORMAT."\nbackup_id={$id}\nsha256sums={$digest}\n");

        $violations = BackupSet::sealedViolations($this->partial, $digest, $id);
        if ($violations !== []) {
            throw new RuntimeException('The sealed set failed its own verification: '.implode('; ', $violations));
        }

        $final = dirname($this->partial).'/'.$id;
        if (file_exists($final) || is_link($final) || ! @rename($this->partial, $final)) {
            throw new RuntimeException("The completed set could not be published as {$id}.");
        }
        $this->partial = null;

        return [
            'backup_id' => $id,
            'directory' => $final,
            'digest' => $digest,
            'attachments' => count($attachments),
            'live_files_outside_snapshot' => $live['count'],
        ];
    }

    /** @param  array<string, mixed>  $summary */
    private function assertDump(array $summary): void
    {
        $problems = $summary['violations'];

        if (! $summary['trailer']) {
            $problems[] = 'the dump has no completion trailer';
        }

        foreach (['migrations', 'tickets', 'ticket_attachments'] as $table) {
            if (! isset($summary['tables'][$table])) {
                $problems[] = "the dump has no `{$table}` table";
            }
        }

        if ($problems !== []) {
            throw new RuntimeException('The database dump is not usable: '.implode('; ', $problems));
        }
    }

    /**
     * Copy each referenced file into $target, checking it against its row and the snapshot.
     *
     * @param  array<int, array{ticket_id: int, reply_id: ?int, user_id: int, path: string, size: int}>  $rows
     * @return list<array{id: int, ticket_id: int, reply_id: ?int, user_id: int, path: string, size: int, sha256: string}>
     */
    private function copyAttachments(array $rows, string $target, int $snapshotStarted): array
    {
        $copied = [];
        $root = rtrim($this->privateRoot, '/');

        foreach ($rows as $id => $row) {
            $path = $row['path'];
            $label = "attachment {$id} (ticket {$row['ticket_id']})";

            if (! BackupSet::safeAttachmentPath($path, $row['ticket_id'])) {
                throw new RuntimeException("{$label}: its stored path is not a file directly inside tickets/{$row['ticket_id']}/.");
            }

            foreach (['tickets', 'tickets/'.$row['ticket_id']] as $directory) {
                if (is_link($root.'/'.$directory) || ! is_dir($root.'/'.$directory)) {
                    throw new RuntimeException("{$label}: {$directory} is missing or is a symbolic link.");
                }
            }

            $source = $root.'/'.$path;
            clearstatcache(true, $source);
            $before = @lstat($source);

            if ($before === false) {
                throw new RuntimeException("{$label}: its file is missing (deleted after the snapshot?).");
            }
            if (($before['mode'] & 0170000) !== 0100000) {
                throw new RuntimeException("{$label}: its file is not a regular file (a symbolic link?).");
            }
            if ($before['size'] !== $row['size']) {
                throw new RuntimeException("{$label}: its file is {$before['size']} bytes but its row says {$row['size']} (modified?).");
            }
            // A row in the snapshot committed before the snapshot began, and its file was written before the row, so the
            // file cannot have changed in a later second than the snapshot started (stat times are whole seconds).
            if ($before['ctime'] > $snapshotStarted || $before['mtime'] > $snapshotStarted) {
                throw new RuntimeException("{$label}: its file changed after the database snapshot began.");
            }

            $in = @fopen($source, 'rb');
            if ($in === false) {
                throw new RuntimeException("{$label}: its file cannot be read.");
            }

            try {
                $opened = fstat($in);
                if ($opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev']) {
                    throw new RuntimeException("{$label}: its file was replaced while being opened.");
                }

                $directory = $target.'/tickets/'.$row['ticket_id'];
                is_dir($directory) || BackupSet::makePrivateDirectory($directory);
                $out = fopen($target.'/'.$path, 'x');
                $hash = hash_init('sha256');
                $bytes = 0;
                while (! feof($in)) {
                    $chunk = fread($in, 1 << 20);
                    if ($chunk === false) {
                        throw new RuntimeException("{$label}: its file could not be read completely.");
                    }
                    hash_update($hash, $chunk);
                    $bytes += strlen($chunk);
                    fwrite($out, $chunk) === strlen($chunk) || throw new RuntimeException("{$label}: the copy could not be written.");
                }
                fclose($out);
                clearstatcache(true, $source);
                $after = fstat($in);
                $again = @lstat($source);
            } finally {
                fclose($in);
            }

            foreach (['ino', 'size', 'mtime', 'ctime'] as $field) {
                if ($after[$field] !== $before[$field] || ($again[$field] ?? null) !== $before[$field]) {
                    throw new RuntimeException("{$label}: its file changed while it was being copied.");
                }
            }
            if ($bytes !== $row['size']) {
                throw new RuntimeException("{$label}: {$bytes} bytes were read, not {$row['size']}.");
            }

            $copied[] = ['id' => $id, ...$row, 'sha256' => hash_final($hash)];
        }

        return $copied;
    }

    /**
     * Files under the live `tickets/` that no attachment row in the snapshot references: uploads after the snapshot,
     * or files a failed request left behind. Not copied; reported so they are not mistaken for loss.
     *
     * @param  array<int, array{path: string}>  $rows
     * @return array{count: int, sample: list<string>}
     */
    private function liveFilesOutsideSnapshot(array $rows): array
    {
        $referenced = array_flip(array_column($rows, 'path'));
        $outside = [];

        foreach (BackupSet::tree(rtrim($this->privateRoot, '/').'/tickets') as $path => $type) {
            if ($type !== 'dir' && ! isset($referenced['tickets/'.$path])) {
                $outside[] = 'tickets/'.$path;
            }
        }

        return ['count' => count($outside), 'sample' => array_slice($outside, 0, self::LIVE_SAMPLE)];
    }

    private function checkRoot(string $root): string
    {
        if ($root === '' || is_link($root) || ! is_dir($root)) {
            throw new BackupRefused('The backup directory must be an existing directory, not a symbolic link.');
        }

        $real = (string) realpath($root);

        if (fileowner($real) !== posix_geteuid()) {
            throw new BackupRefused('The backup directory must be owned by the account running the backup.');
        }

        if ((fileperms($real) & 0077) !== 0) {
            throw new BackupRefused('The backup directory must be private (mode 0700); it is '.BackupSet::mode($real).'.');
        }

        foreach (['the application' => base_path(), 'storage' => storage_path(), 'the public directory' => public_path(), 'the private disk' => $this->privateRoot] as $label => $other) {
            $other = realpath($other);
            if ($other !== false && ($real === $other || str_starts_with($real.'/', $other.'/') || str_starts_with($other.'/', $real.'/'))) {
                throw new BackupRefused("The backup directory must be outside {$label} (and must not contain it).");
            }
        }

        return $real;
    }

    private function lock(string $root): void
    {
        $handle = @fopen($root.'/'.self::LOCK_FILE, 'c');

        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            $handle && fclose($handle);

            throw new BackupRefused('Another backup is running in this directory.');
        }

        $this->lock = $handle;
    }

    /**
     * Remove the `.partial-<id>` directories killed runs left behind. Safe under the lock: no other run is active.
     *
     * @return list<string>
     */
    private function sweep(string $root): array
    {
        $swept = [];

        foreach (scandir($root) ?: [] as $name) {
            if (! str_starts_with($name, self::PARTIAL_PREFIX)) {
                continue;
            }

            $path = $root.'/'.$name;
            $id = substr($name, strlen(self::PARTIAL_PREFIX));
            $marker = @file_get_contents($path.'/'.self::PARTIAL_MARKER);

            // A run killed after the marker went and before COMPLETE leaves only (encrypted) sealed files: also ours, no plaintext.
            $sealedOnly = $marker === false && is_dir($path) && ! is_link($path)
                && array_diff(array_diff(scandir($path) ?: [], ['.', '..']), BackupSet::sealedFiles()) === [];

            if (is_link($path) || ! is_dir($path) || ! preg_match(BackupSet::ID_PATTERN, $id) || (trim((string) $marker) !== $id && ! $sealedOnly)) {
                $this->say("WARNING: {$name} looks like a partial set but is not one this tool made; left in place.");

                continue;
            }

            if (! BackupSet::removeTree($path)) {
                throw new BackupRefused("An interrupted partial set ({$name}) could not be removed.");
            }

            $swept[] = $name;
            $this->say("Removed the partial set of an interrupted run: {$name}");
        }

        return $swept;
    }

    /** @param  list<string>  $command */
    private function tool(array $command): void
    {
        $process = new Process($command, null, ['LC_ALL' => 'C'], null, 3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException($command[0].' failed (exit '.$process->getExitCode().'): '.trim($process->getErrorOutput()));
        }
    }

    /** A short, non-reversible id for a key, so the escrowed key can be matched to a set without revealing it. */
    public static function keyId(string $key): string
    {
        return substr(hash('sha256', 'intechral-portal-backup-key-id:'.$key), 0, 16);
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private function say(string $line): void
    {
        $this->log[] = $line;
        $this->progress && ($this->progress)($line);
    }
}
