<?php

namespace App\Support\Rehearsal;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The directories a rehearsal writes: a private-file root per role and one for backup sets, all beneath
 * `storage/rehearsal/`. A directory is touched (emptied, written, deleted) only when the harness can prove
 * it created it: it is empty or absent, or it carries the {@see RehearsalTarget::MARKER_FILE} marker for
 * that exact kind. A path that resolves outside `storage/rehearsal/`, or through a symbolic link, is
 * refused, so cleanup can never reach the application's real private storage or anything else.
 */
final class RehearsalFiles
{
    /** Marker kinds. */
    public static function kindFor(RehearsalTarget $target): string
    {
        return 'files:'.$target->role;
    }

    public const BACKUPS = 'backups';

    /**
     * Everything wrong with using $path as a rehearsal directory of $kind.
     *
     * @return list<string>
     */
    public static function violations(string $path, string $kind): array
    {
        $violations = [];
        $base = RehearsalTarget::baseDirectory();

        if (is_link($base)) {
            $violations[] = 'storage/rehearsal is a symbolic link';
        }

        if (file_exists($base) && ! is_link($base)) {
            $realBase = realpath($base);
            $expected = realpath(dirname($base)).DIRECTORY_SEPARATOR.basename($base);

            if ($realBase !== $expected) {
                $violations[] = 'storage/rehearsal does not resolve to its expected location';
            }
        }

        if (is_link($path)) {
            $violations[] = "{$path} is a symbolic link";

            return $violations;
        }

        if (! file_exists($path)) {
            return $violations;
        }

        $realBase = realpath($base);
        $real = realpath($path);

        if ($realBase === false || $real === false || ! str_starts_with($real, $realBase.DIRECTORY_SEPARATOR)) {
            $violations[] = 'the directory is outside storage/rehearsal';

            return $violations;
        }

        if (! is_dir($path)) {
            $violations[] = 'the path exists and is not a directory';

            return $violations;
        }

        $entries = array_values(array_diff(scandir($path) ?: [], ['.', '..', RehearsalTarget::MARKER_FILE]));

        if ($entries !== [] && ! self::hasMarker($path, $kind)) {
            $violations[] = 'a non-empty directory under storage/rehearsal has no matching rehearsal marker';
        }

        return $violations;
    }

    public static function hasMarker(string $path, string $kind): bool
    {
        $file = $path.DIRECTORY_SEPARATOR.RehearsalTarget::MARKER_FILE;

        if (! is_file($file)) {
            return false;
        }

        $marker = json_decode((string) file_get_contents($file), true);

        return is_array($marker)
            && ($marker['signature'] ?? null) === RehearsalTarget::SIGNATURE
            && ($marker['kind'] ?? null) === $kind;
    }

    /** Empty $path (creating it if needed) and mark it. Refuses first unless it is provably ours. */
    public static function reset(string $path, string $kind): void
    {
        self::assertSafe($path, $kind);

        if (is_dir($path)) {
            if (! self::clear($path)) {
                throw new RuntimeException('Could not empty '.self::display($path).'; remove what remains by hand (check its permissions).');
            }
        } else {
            File::ensureDirectoryExists($path, 0700);
        }

        $marker = $path.DIRECTORY_SEPARATOR.RehearsalTarget::MARKER_FILE;
        file_put_contents($marker, json_encode(['signature' => RehearsalTarget::SIGNATURE, 'kind' => $kind]));
        chmod($marker, 0600);
    }

    /**
     * Every file or directory at or under $path that allows any access to its group or to others, relative
     * to $path ("." for $path itself). Empty means the whole tree is private to its owner. Paths only: file
     * contents never appear in a message.
     *
     * @return list<string>
     */
    public static function exposedPaths(string $path): array
    {
        if (! file_exists($path)) {
            return [];
        }

        $exposed = (fileperms($path) & 0077) !== 0 ? ['.'] : [];
        $prefix = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $entry) {
            if (! $entry->isLink() && ($entry->getPerms() & 0077) !== 0) {
                $exposed[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($prefix)));
            }
        }

        sort($exposed);

        return $exposed;
    }

    /** Remove $path entirely, only if it is provably a rehearsal directory of $kind. */
    public static function remove(string $path, string $kind): void
    {
        self::assertSafe($path, $kind);

        if (! is_dir($path)) {
            return;
        }

        // A removal that did not finish is an error, never a silent success, and it leaves the marker in place:
        // the directory stays provably the harness's own, so the cleanup can simply be run again once the cause
        // (usually a permission) is fixed. The marker goes last.
        if (! self::clear($path)) {
            throw new RuntimeException('Could not fully remove '.self::display($path).'; remove what remains by hand (check its permissions), then run the cleanup again.');
        }

        @unlink($path.DIRECTORY_SEPARATOR.RehearsalTarget::MARKER_FILE);
        @rmdir($path);

        if (file_exists($path)) {
            throw new RuntimeException('Could not fully remove '.self::display($path).'; remove what remains by hand (check its permissions), then run the cleanup again.');
        }
    }

    /**
     * Remove everything beneath $path except the marker. True when nothing but the marker is left. A symbolic
     * link is removed as a link, never followed.
     */
    private static function clear(string $path): bool
    {
        $clear = true;

        foreach (array_diff(scandir($path) ?: [], ['.', '..', RehearsalTarget::MARKER_FILE]) as $name) {
            $child = $path.DIRECTORY_SEPARATOR.$name;

            $clear = (is_dir($child) && ! is_link($child) ? File::deleteDirectory($child) : @unlink($child)) && $clear;
        }

        return $clear && array_diff(scandir($path) ?: [], ['.', '..', RehearsalTarget::MARKER_FILE]) === [];
    }

    /** A path as the operator knows it: relative to the application's storage directory. */
    private static function display(string $path): string
    {
        $base = dirname(RehearsalTarget::baseDirectory());

        return str_starts_with($path, $base) ? 'storage'.substr($path, strlen($base)) : basename($path);
    }

    public static function assertSafe(string $path, string $kind): void
    {
        $violations = self::violations($path, $kind);

        if ($violations !== []) {
            throw new UnsafeRehearsalTarget($violations);
        }
    }

    /**
     * A private disk over $path: files 0600, directories 0700. The permissions are stated, not left to the
     * driver's defaults (which are 0644 / 0755 for the default "public" visibility): rehearsal files, like
     * the backups taken of them, must never be readable by another account.
     */
    public static function disk(string $path): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::build([
            'driver' => 'local',
            'root' => $path,
            'throw' => true,
            'visibility' => 'private',
            'permissions' => [
                'file' => ['public' => 0600, 'private' => 0600],
                'dir' => ['public' => 0700, 'private' => 0700],
            ],
        ]);

        return $disk;
    }

    /**
     * Every regular file under $path (marker excluded), relative to it, sorted.
     *
     * @return list<string>
     */
    public static function listFiles(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $files = [];
        $root = rtrim(realpath($path) ?: $path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new RuntimeException("Unexpected symbolic link in a rehearsal directory: {$file->getPathname()}");
            }

            if ($file->isFile() && $file->getFilename() !== RehearsalTarget::MARKER_FILE) {
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getRealPath(), strlen($root)));
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Directories directly under $path/$subdir (for example `tickets/`), by name.
     *
     * @return list<string>
     */
    public static function listDirectories(string $path, string $subdir): array
    {
        $dir = $path.DIRECTORY_SEPARATOR.$subdir;

        if (! is_dir($dir)) {
            return [];
        }

        $names = array_values(array_filter(
            array_diff(scandir($dir) ?: [], ['.', '..']),
            fn (string $name) => is_dir($dir.DIRECTORY_SEPARATOR.$name)
        ));

        sort($names);

        return $names;
    }
}
