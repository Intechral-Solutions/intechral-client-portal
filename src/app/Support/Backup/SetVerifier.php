<?php

namespace App\Support\Backup;

use Throwable;

/**
 * Verifies an OPENED (decrypted) backup set without a database (Release 0.9 WP-B1).
 *
 * It fails closed: anything it cannot establish is a violation. What a pass means is stated precisely: the set is
 * complete, unaltered since it was sealed (and, with the recorded digest, the set that was made), its dump is a
 * whole `mariadb-dump` of one database with only expected statements, and the dump, the manifest and the attachment
 * archive agree row for row and file for file. It does NOT prove the dump loads: only a restore does
 * ({@see RestoreDrill}).
 */
final class SetVerifier
{
    /**
     * @return array{ok: bool, violations: list<string>, set: array<string, mixed>, manifest: array<string, mixed>, digest: string}
     */
    public static function verifyOpened(string $dir, ?string $expectedDigest = null): array
    {
        $dir = rtrim($dir, '/');
        $result = ['ok' => false, 'violations' => [], 'set' => [], 'manifest' => [], 'digest' => ''];

        $violations = BackupSet::layoutViolations($dir, BackupSet::openedFiles());
        if ($violations !== []) {
            return [...$result, 'violations' => $violations];
        }

        // An opened set's directory is named by the operator; the id must agree between set.json and COMPLETE.
        $id = json_decode((string) file_get_contents($dir.'/'.BackupSet::SET_FILE), true)['backup_id'] ?? '';
        $sealed = BackupSet::sealedMetadata($dir, $expectedDigest, is_string($id) ? $id : '');
        $result['set'] = $sealed['set'];
        $result['digest'] = $sealed['digest'];
        $violations = $sealed['violations'];

        foreach (BackupSet::PAYLOAD as $name) {
            $artifact = $sealed['set']['artifacts'][$name.'.gpg'] ?? [];
            if (hash_file('sha256', $dir.'/'.$name) !== ($artifact['plaintext_sha256'] ?? null) || filesize($dir.'/'.$name) !== ($artifact['plaintext_bytes'] ?? null)) {
                $violations[] = "{$name} is not the file that was encrypted into the set (corrupt, truncated or from another set)";
            }
        }

        if ($violations !== []) {
            return [...$result, 'violations' => $violations];
        }

        $manifest = json_decode((string) file_get_contents($dir.'/'.BackupSet::MANIFEST), true);
        if (! is_array($manifest) || ($manifest['format'] ?? null) !== BackupSet::FORMAT || ($manifest['backup_id'] ?? null) !== ($sealed['set']['backup_id'] ?? false)) {
            return [...$result, 'violations' => ['manifest.json is not this set\'s manifest']];
        }
        $result['manifest'] = $manifest;

        try {
            $dump = DumpReader::read($dir.'/'.BackupSet::DATABASE);
        } catch (Throwable $e) {
            return [...$result, 'violations' => ['the dump cannot be read: '.$e->getMessage()]];
        }

        $violations = [...self::dumpViolations($dump, $manifest), ...self::archiveViolations($dir, $manifest, $dump)];

        return [...$result, 'ok' => $violations === [], 'violations' => $violations];
    }

    /**
     * The dump against the manifest that was derived from it.
     *
     * Tables in $relaxed are compared by definition only: their rows may legitimately change after a restore (the
     * recovery clears sessions and cache, and the operator reviews queued jobs). When $relaxed is given, tables with
     * recovery-blanked columns ({@see DumpReader::RECOVERY_BLANKED}) are compared without those columns.
     *
     * @param  array<string, mixed>  $dump
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $relaxed
     * @return list<string>
     */
    public static function dumpViolations(array $dump, array $manifest, array $relaxed = []): array
    {
        $violations = array_map(fn (string $v) => 'dump: '.$v, $dump['violations']);

        if (! $dump['trailer']) {
            $violations[] = 'dump: no completion trailer (truncated)';
        }

        $expected = $manifest['database'] ?? [];

        if ($dump['schema_sha256'] !== ($expected['schema_sha256'] ?? null)) {
            $violations[] = 'dump: the schema differs from the manifest';
        }

        foreach (array_keys($dump['tables'] + ($expected['tables'] ?? [])) as $table) {
            $found = $dump['tables'][$table] ?? null;
            $wanted = $expected['tables'][$table] ?? null;
            if ($relaxed !== [] && $found !== null && $wanted !== null) {
                // Volatile tables by definition only; a table with recovery-blanked columns by its stable digest.
                $keys = in_array($table, $relaxed, true) ? ['create_sha256', 'engine']
                    : (isset(DumpReader::RECOVERY_BLANKED[$table]) ? ['create_sha256', 'engine', 'rows', 'stable_sha256'] : null);
                if ($keys !== null) {
                    $keys = array_flip($keys);
                    [$found, $wanted] = [array_intersect_key($found, $keys), array_intersect_key($wanted, $keys)];
                }
            }
            if ($found !== $wanted) {
                $violations[] = "dump: table `{$table}` differs from the manifest (rows, content or definition)";
            }
        }

        if ($dump['tickets']['duplicates'] !== 0) {
            $violations[] = 'dump: '.$dump['tickets']['duplicates'].' duplicate ticket reference(s)';
        }

        foreach (['tickets', 'migrations'] as $part) {
            if ($dump[$part] !== ($expected[$part] ?? null)) {
                $violations[] = "dump: the {$part} summary differs from the manifest";
            }
        }

        $rows = [];
        foreach ($manifest['attachments'] ?? [] as $a) {
            $rows[(int) $a['id']] = ['ticket_id' => $a['ticket_id'], 'reply_id' => $a['reply_id'], 'user_id' => $a['user_id'], 'path' => $a['path'], 'size' => $a['size']];
        }
        if ($rows !== $dump['attachments']) {
            $violations[] = 'dump: the attachment rows differ from the manifest inventory';
        }

        foreach ($dump['attachments'] as $id => $row) {
            if (! BackupSet::safeAttachmentPath($row['path'], $row['ticket_id'])) {
                $violations[] = "dump: attachment {$id} has a path outside tickets/{$row['ticket_id']}/";
            }
        }

        return $violations;
    }

    /**
     * The archive against the manifest inventory: exactly those files, byte for byte, and nothing else.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $dump
     * @return list<string>
     */
    private static function archiveViolations(string $dir, array $manifest, array $dump): array
    {
        $work = dirname($dir).'/.'.basename($dir).'.verify-'.bin2hex(random_bytes(4));
        $previous = umask(0077);

        try {
            BackupSet::makePrivateDirectory($work);
            $inventory = AttachmentArchive::extract($dir.'/'.BackupSet::ATTACHMENTS, $work);
            $directories = array_keys(array_filter(BackupSet::tree($work.'/tickets'), fn (string $t) => $t === 'dir'));
        } catch (Throwable $e) {
            return ['attachments: '.$e->getMessage()];
        } finally {
            umask($previous);
            BackupSet::removeTree($work);
        }

        return self::inventoryViolations($inventory, $directories, $manifest['attachments'] ?? []);
    }

    /**
     * Files and directories found against the manifest inventory.
     *
     * @param  array<string, array{size: int, sha256: string}>  $inventory  `tickets/…` => size and hash
     * @param  list<string>  $directories  ticket directory names found under tickets/
     * @param  list<array<string, mixed>>  $attachments
     * @return list<string>
     */
    public static function inventoryViolations(array $inventory, array $directories, array $attachments): array
    {
        $violations = [];
        $expectedDirectories = [];

        foreach ($attachments as $a) {
            $expectedDirectories[(string) $a['ticket_id']] = true;
            $found = $inventory[$a['path']] ?? null;
            if ($found === null) {
                $violations[] = "attachments: the file of attachment {$a['id']} is missing";
            } elseif ($found['size'] !== $a['size'] || $found['sha256'] !== $a['sha256']) {
                $violations[] = "attachments: the file of attachment {$a['id']} does not match its recorded size and SHA-256";
            }
            unset($inventory[$a['path']]);
        }

        foreach (array_keys($inventory) as $unexpected) {
            $violations[] = "attachments: unexpected file {$unexpected}";
        }

        foreach ($directories as $directory) {
            if (! isset($expectedDirectories[$directory])) {
                $violations[] = "attachments: unexpected directory tickets/{$directory}";
            }
        }

        return $violations;
    }
}
