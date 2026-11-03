<?php

namespace App\Support\Backup;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Safe extraction of a set's attachments archive (`attachments.tar.gz`).
 *
 * Before anything is written, every member NAME is checked: only `tickets/`, `tickets/{id}/` and
 * `tickets/{id}/{file}` are allowed (no `..`, no absolute path, no other directory, no duplicate). After extraction,
 * every entry on disk must be a plain directory or regular file and match the listing, so a symbolic link, device
 * or hard link smuggled under an allowed name is rejected too. Extraction never overwrites: the target must not
 * already hold `tickets`.
 */
final class AttachmentArchive
{
    /**
     * Extract $archive into $root (which must not contain `tickets`), and return the inventory of `tickets/…` files.
     *
     * @return array<string, array{size: int, sha256: string}>
     */
    public static function extract(string $archive, string $root): array
    {
        if (! is_dir($root) || is_link($root)) {
            throw new RuntimeException('The extraction target is not a directory.');
        }

        if (file_exists($root.'/tickets') || is_link($root.'/tickets')) {
            throw new RuntimeException('The extraction target already holds tickets/; nothing is extracted over existing files.');
        }

        $names = self::members($archive);

        $process = new Process(['tar', '--extract', '--gzip', '--file', $archive, '--directory', $root,
            '--no-same-owner', '--no-same-permissions', '--keep-old-files'], null, ['LC_ALL' => 'C'], null, 3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('The attachments archive could not be extracted: '.trim($process->getErrorOutput()));
        }

        $expected = array_map(fn (string $n) => rtrim($n, '/'), $names);
        sort($expected);
        $tree = array_map(fn (string $p) => 'tickets'.($p === '' ? '' : '/'.$p), array_keys(BackupSet::tree($root.'/tickets')));
        $actual = ['tickets', ...$tree];
        sort($actual);

        if ($actual !== $expected) {
            throw new RuntimeException('The extracted attachments do not match the archive listing.');
        }

        $inventory = [];
        foreach (BackupSet::tree($root.'/tickets') as $path => $type) {
            if ($type === 'dir') {
                continue;
            }
            if ($type !== 'file' || lstat($root.'/tickets/'.$path)['nlink'] !== 1) {
                throw new RuntimeException('The attachments archive holds something other than plain files and directories.');
            }
            $inventory['tickets/'.$path] = ['size' => (int) filesize($root.'/tickets/'.$path), 'sha256' => (string) hash_file('sha256', $root.'/tickets/'.$path)];
        }

        return $inventory;
    }

    /**
     * The member names of $archive, validated.
     *
     * @return list<string>
     */
    public static function members(string $archive): array
    {
        $process = new Process(['tar', '--list', '--gzip', '--file', $archive, '--quoting-style=escape'], null, ['LC_ALL' => 'C'], null, 600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('The attachments archive cannot be read (corrupt or truncated): '.trim($process->getErrorOutput()));
        }

        $names = array_values(array_filter(explode("\n", $process->getOutput()), fn (string $n) => $n !== ''));
        $file = BackupSet::FILE_NAME;

        foreach ($names as $name) {
            if (! preg_match("#\\Atickets/(\\d+/({$file})?)?\\z#", $name) || str_contains($name, '..')) {
                throw new RuntimeException('The attachments archive holds an unsafe or unexpected entry.');
            }
        }

        if (count($names) !== count(array_unique($names)) || ! in_array('tickets/', $names, true)) {
            throw new RuntimeException('The attachments archive has duplicate entries or no tickets/ directory.');
        }

        return $names;
    }
}
