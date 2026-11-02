<?php

namespace App\Support\Backup;

use RuntimeException;

/**
 * The backup set format (Release 0.9 WP-B1) and the filesystem rules every backup tool shares.
 *
 * A sealed set is one directory named by its id, holding exactly:
 *
 *   set.json                 plain: id, times, source release, recipient, and the SHA-256 of every artifact (both
 *                            encrypted and decrypted). No customer data, credentials or key material.
 *   manifest.json.gpg        the manifest: schema, per-table row counts and digests, ticket identity, the
 *                            attachment inventory and the APP_KEY canary, all derived from the dump
 *   database.sql.gz.gpg      the `mariadb-dump` snapshot
 *   attachments.tar.gz.gpg   exactly the private attachment files the snapshot's rows reference
 *   SHA256SUMS               `sha256sum` lines for set.json and the three encrypted artifacts
 *   COMPLETE                 written last: the id and the SHA-256 of SHA256SUMS (the set digest)
 *
 * The set digest is printed when the set is made. Recording it then, and comparing it before a restore, is what
 * authenticates a set: the digest covers SHA256SUMS, which covers every other file.
 *
 * An opened set (decrypted on the owner's machine by scripts/backup/open-backup-set.sh) holds set.json, SHA256SUMS
 * and COMPLETE plus manifest.json, database.sql.gz and attachments.tar.gz.
 */
final class BackupSet
{
    public const FORMAT = 'intechral-portal-backup/1';

    public const ID_PATTERN = '/\A\d{8}T\d{6}Z-[0-9a-f]{8}\z/';

    public const SET_FILE = 'set.json';

    public const SUMS = 'SHA256SUMS';

    public const COMPLETE = 'COMPLETE';

    public const MANIFEST = 'manifest.json';

    public const DATABASE = 'database.sql.gz';

    public const ATTACHMENTS = 'attachments.tar.gz';

    public const PAYLOAD = [self::MANIFEST, self::DATABASE, self::ATTACHMENTS];

    /** A stored attachment file name: what Laravel's `store()` produces, and the rehearsal fixtures. */
    public const FILE_NAME = '[A-Za-z0-9][A-Za-z0-9._-]{0,254}';

    /** @return list<string> the files of a sealed set, sorted */
    public static function sealedFiles(): array
    {
        $files = [self::SET_FILE, self::SUMS, self::COMPLETE, ...array_map(fn ($f) => $f.'.gpg', self::PAYLOAD)];
        sort($files);

        return $files;
    }

    /** @return list<string> the files of an opened set, sorted */
    public static function openedFiles(): array
    {
        $files = [self::SET_FILE, self::SUMS, self::COMPLETE, ...self::PAYLOAD];
        sort($files);

        return $files;
    }

    /**
     * Whether $path is a safe stored attachment path for $ticketId: `tickets/{ticketId}/{name}`, nothing else.
     */
    public static function safeAttachmentPath(string $path, int $ticketId): bool
    {
        return $ticketId > 0 && preg_match('#\Atickets/'.$ticketId.'/'.self::FILE_NAME.'\z#', $path) === 1
            && ! str_contains($path, '..');
    }

    /**
     * Problems with the files of set directory $dir, as found on disk: exactly $expected regular files, no links,
     * nothing else, and private (directory 0700, files 0600).
     *
     * @param  list<string>  $expected
     * @return list<string>
     */
    public static function layoutViolations(string $dir, array $expected): array
    {
        if (is_link($dir) || ! is_dir($dir)) {
            return ['the set is not a directory (or is a symbolic link)'];
        }

        $violations = [];

        if ((fileperms($dir) & 0077) !== 0) {
            $violations[] = 'the set directory is accessible to other users (mode '.self::mode($dir).')';
        }

        $present = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        sort($present);

        foreach (array_diff($expected, $present) as $missing) {
            $violations[] = "{$missing} is missing";
        }

        foreach (array_diff($present, $expected) as $extra) {
            $violations[] = "unexpected entry {$extra}";
        }

        foreach (array_intersect($expected, $present) as $name) {
            $path = $dir.'/'.$name;
            if (is_link($path) || ! is_file($path)) {
                $violations[] = "{$name} is not a regular file";
            } elseif ((fileperms($path) & 0077) !== 0) {
                $violations[] = "{$name} is accessible to other users (mode ".self::mode($path).')';
            }
        }

        return $violations;
    }

    /**
     * The sealed metadata of set directory $dir (sealed or opened): COMPLETE, SHA256SUMS and set.json, checked
     * against each other and, when given, against the digest recorded when the set was made.
     *
     * @return array{set: array<string, mixed>, digest: string, violations: list<string>}
     */
    public static function sealedMetadata(string $dir, ?string $expectedDigest, ?string $directoryName = null): array
    {
        $violations = [];
        $complete = self::keyValues((string) @file_get_contents($dir.'/'.self::COMPLETE));
        $digest = hash_file('sha256', $dir.'/'.self::SUMS) ?: '';

        if (($complete['format'] ?? null) !== self::FORMAT) {
            $violations[] = 'COMPLETE does not name format '.self::FORMAT;
        }

        if (($complete['sha256sums'] ?? null) !== $digest) {
            $violations[] = 'COMPLETE does not match SHA256SUMS (the set was changed or never completed)';
        }

        if ($expectedDigest !== null && strtolower(trim($expectedDigest)) !== $digest) {
            $violations[] = 'the set digest is not the one recorded when the set was made';
        }

        $sums = [];
        foreach (explode("\n", trim((string) @file_get_contents($dir.'/'.self::SUMS))) as $line) {
            if (! preg_match('/\A([0-9a-f]{64})  ([A-Za-z0-9._]+)\z/', $line, $m)) {
                $violations[] = 'SHA256SUMS has a malformed line';

                continue;
            }
            $sums[$m[2]] = $m[1];
        }

        $sealed = array_values(array_diff(self::sealedFiles(), [self::SUMS, self::COMPLETE]));
        $listed = array_keys($sums);
        sort($listed);
        if ($listed !== $sealed) {
            $violations[] = 'SHA256SUMS does not list exactly '.implode(', ', $sealed);
        }

        if (($sums[self::SET_FILE] ?? null) !== hash_file('sha256', $dir.'/'.self::SET_FILE)) {
            $violations[] = 'set.json does not match SHA256SUMS';
        }

        $set = json_decode((string) @file_get_contents($dir.'/'.self::SET_FILE), true);
        if (! is_array($set) || ($set['format'] ?? null) !== self::FORMAT) {
            $violations[] = 'set.json is not a '.self::FORMAT.' set description';
            $set = [];
        }

        $id = (string) ($set['backup_id'] ?? '');
        if (! preg_match(self::ID_PATTERN, $id) || ($complete['backup_id'] ?? null) !== $id || ($directoryName ?? basename($dir)) !== $id) {
            $violations[] = 'the backup id in set.json, COMPLETE and the directory name do not agree';
        }

        foreach (self::PAYLOAD as $name) {
            if (($set['artifacts'][$name.'.gpg']['sha256'] ?? null) !== ($sums[$name.'.gpg'] ?? false)) {
                $violations[] = "set.json and SHA256SUMS disagree about {$name}.gpg";
            }
        }

        return ['set' => $set, 'digest' => $digest, 'violations' => $violations];
    }

    /**
     * Everything wrong with SEALED set $dir: its layout, its metadata, and every encrypted artifact re-hashed from
     * disk. This is integrity only; it says nothing about whether the contents restore.
     *
     * @return list<string>
     */
    public static function sealedViolations(string $dir, ?string $expectedDigest = null, ?string $directoryName = null): array
    {
        $violations = self::layoutViolations($dir, self::sealedFiles());

        if ($violations !== []) {
            return $violations;
        }

        $metadata = self::sealedMetadata($dir, $expectedDigest, $directoryName);
        $violations = $metadata['violations'];

        foreach (self::PAYLOAD as $name) {
            $artifact = $metadata['set']['artifacts'][$name.'.gpg'] ?? [];
            if (hash_file('sha256', $dir.'/'.$name.'.gpg') !== ($artifact['sha256'] ?? null)
                || filesize($dir.'/'.$name.'.gpg') !== ($artifact['bytes'] ?? null)) {
                $violations[] = "{$name}.gpg does not match its recorded SHA-256 and size (corrupt or replaced)";
            }
        }

        return $violations;
    }

    /** @return array<string, string> `key=value` lines */
    public static function keyValues(string $text): array
    {
        $values = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/\A([a-z0-9_]+)=(.*)\z/', $line, $m)) {
                $values[$m[1]] = $m[2];
            }
        }

        return $values;
    }

    /** Write $contents to a new private file (0600) and flush it to disk. Refuses to replace anything. */
    public static function writePrivate(string $path, string $contents): void
    {
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('Cannot create '.basename($path).' (it exists or the directory is not writable).');
        }

        try {
            chmod($path, 0600);
            if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('Cannot write '.basename($path).'.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** Make a new private directory (0700). Refuses an existing path. */
    public static function makePrivateDirectory(string $path): void
    {
        if (file_exists($path) || is_link($path) || ! @mkdir($path, 0700)) {
            throw new RuntimeException('Cannot create the private directory '.basename($path).'.');
        }

        chmod($path, 0700);
    }

    /**
     * Delete directory $path and everything in it, never following a symbolic link (a link is removed, not its
     * target). Returns false if anything is left.
     */
    public static function removeTree(string $path): bool
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }

        if (! is_dir($path)) {
            return ! file_exists($path);
        }

        @chmod($path, 0700);
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            self::removeTree($path.'/'.$entry);
        }

        return @rmdir($path);
    }

    /**
     * Every entry under $root, relative, as `path => type` (`file`, `dir`, `link` or `other`), never following links.
     *
     * @return array<string, string>
     */
    public static function tree(string $root): array
    {
        $entries = [];
        $walk = function (string $relative) use (&$walk, &$entries, $root): void {
            foreach (array_diff(scandir($root.($relative === '' ? '' : '/'.$relative)) ?: [], ['.', '..']) as $name) {
                $path = $relative === '' ? $name : $relative.'/'.$name;
                $full = $root.'/'.$path;
                $type = is_link($full) ? 'link' : (is_dir($full) ? 'dir' : (is_file($full) ? 'file' : 'other'));
                $entries[$path] = $type;
                if ($type === 'dir') {
                    $walk($path);
                }
            }
        };

        if (is_dir($root) && ! is_link($root)) {
            $walk('');
        }

        ksort($entries);

        return $entries;
    }

    public static function mode(string $path): string
    {
        return substr(sprintf('%o', fileperms($path)), -4);
    }
}
