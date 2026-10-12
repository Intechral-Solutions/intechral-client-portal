<?php

namespace App\Support\Rehearsal;

use InvalidArgumentException;

/**
 * Which migrations form the rehearsal baseline and which may be applied as candidates.
 *
 * Two reviewed manifests decide it, and the migrations directory must match them exactly:
 *
 *  - the BASELINE (`database/rehearsal/baseline.php`): every migration the rehearsal builds its starting schema
 *    from, in order, each pinned to the SHA-256 of the file as it shipped;
 *  - the CANDIDATES (`database/rehearsal/candidates.php`): the migrations under review, each pinned the same way
 *    and chosen by name.
 *
 * Every `.php` file in the directory must be named by one of the two. A file named by neither is refused with
 * its name ("undeclared"), because the old behaviour (baseline = everything not a candidate) let a new
 * migration run before the fixtures existed and so be rehearsed against an empty schema instead of populated
 * data. A baseline file that is missing or no longer hashes to its pin is drift. A candidate must sort after
 * every baseline migration, so rehearsing "baseline, fixtures, then candidates" is the order a real upgrade
 * applies them in. Nothing a caller supplies (a path, a wildcard, a PHP file) is ever loaded.
 *
 * The directory and manifests are constructor arguments only so tests can exercise the rules against a
 * throw-away directory; the command always uses {@see repository()}.
 */
final class MigrationSet
{
    private const NAME = '/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/';

    /**
     * @param  array<string, array<string, mixed>>  $manifest  the approved candidates, by name
     * @param  array<string, string>  $baseline  the pinned baseline: name => SHA-256, in migration order
     */
    public function __construct(
        private readonly string $directory,
        private readonly array $manifest,
        private readonly array $baseline,
    ) {}

    public static function repository(): self
    {
        /** @var array<string, array<string, mixed>> $manifest */
        $manifest = require database_path('rehearsal/candidates.php');
        /** @var array<string, string> $baseline */
        $baseline = require database_path('rehearsal/baseline.php');

        return new self(database_path('migrations'), $manifest, $baseline);
    }

    /** @return list<string> every migration name present in the directory, in order */
    public function names(): array
    {
        $names = array_map(
            fn (string $file) => basename($file, '.php'),
            glob($this->directory.DIRECTORY_SEPARATOR.'*.php') ?: []
        );
        sort($names);

        return $names;
    }

    /** @return list<string> */
    public function candidateNames(): array
    {
        return array_keys($this->manifest);
    }

    /** @return list<string> the migrations the baseline is built from: the pinned list, in its order */
    public function baselineNames(): array
    {
        return array_map('strval', array_keys($this->baseline));
    }

    /**
     * Everything wrong with the pinned sets or with the directory against them. Empty means the directory is
     * exactly the baseline plus the approved candidates, with no file edited or missing.
     *
     * @return list<string>
     */
    public function manifestViolations(): array
    {
        return [...$this->baselineViolations(), ...$this->candidateViolations(), ...$this->driftViolations()];
    }

    /** @return list<string> */
    private function baselineViolations(): array
    {
        $violations = [];
        $names = $this->baselineNames();

        foreach ($this->baseline as $name => $hash) {
            if (! preg_match(self::NAME, (string) $name)) {
                $violations[] = "baseline entry \"{$name}\" is not a migration name";
            }

            if (! is_string($hash) || ! preg_match('/^[0-9a-f]{64}$/', $hash)) {
                $violations[] = "baseline entry \"{$name}\" has no valid sha256";
            }
        }

        $sorted = $names;
        sort($sorted);
        if ($names !== $sorted) {
            $violations[] = 'the pinned baseline is not in migration (ascending name) order';
        }

        return $violations;
    }

    /** @return list<string> */
    private function candidateViolations(): array
    {
        $violations = [];
        $baseline = $this->baselineNames();
        $last = $baseline === [] ? null : max($baseline);

        foreach ($this->manifest as $name => $entry) {
            if (! preg_match(self::NAME, (string) $name)) {
                $violations[] = "manifest entry \"{$name}\" is not a migration name";

                continue;
            }

            if (isset($this->baseline[$name])) {
                $violations[] = "\"{$name}\" is listed as both a baseline migration and a candidate";
            }

            if ($last !== null && strcmp((string) $name, $last) <= 0) {
                $violations[] = "candidate \"{$name}\" sorts before the baseline migration \"{$last}\": a candidate must come after every baseline migration, as it would in a real upgrade";
            }

            if (! is_file($this->path($name))) {
                $violations[] = "manifest entry \"{$name}\" has no file in the migrations directory";
            }

            if (! is_string($entry['sha256'] ?? null) || ! preg_match('/^[0-9a-f]{64}$/', $entry['sha256'])) {
                $violations[] = "manifest entry \"{$name}\" has no valid sha256";
            }

            foreach (['schema', 'data'] as $key) {
                if (isset($entry[$key]) && ! is_array($entry[$key])) {
                    $violations[] = "manifest entry \"{$name}\": \"{$key}\" must be a list";
                }
            }
        }

        return $violations;
    }

    /**
     * The directory against the pinned sets: undeclared files, missing baseline files, edited baseline files.
     *
     * @return list<string>
     */
    private function driftViolations(): array
    {
        $violations = [];
        $present = $this->names();

        foreach (array_diff($present, $this->baselineNames(), $this->candidateNames()) as $name) {
            $violations[] = "undeclared migration file \"{$name}\": it is in neither database/rehearsal/baseline.php nor database/rehearsal/candidates.php. "
                .'Add it to candidates.php with a reviewed entry (summary, sha256, declared schema changes) so it is rehearsed against populated data; it is never silently added to the baseline';
        }

        foreach ($this->baseline as $name => $pinned) {
            $path = $this->path((string) $name);

            if (! is_file($path)) {
                $violations[] = "baseline migration \"{$name}\" is pinned but missing from the migrations directory";

                continue;
            }

            $actual = (string) hash_file('sha256', $path);

            if (is_string($pinned) && ! hash_equals($pinned, $actual)) {
                $violations[] = "baseline migration \"{$name}\" has changed since it was pinned (pinned {$pinned}, found {$actual}): shipped migrations are not edited";
            }
        }

        return $violations;
    }

    /**
     * Validate the migration set and the operator's selection, and return what to run, in migration order.
     * The pinned sets are validated even when nothing is selected.
     *
     * @param  list<string>  $names
     * @return list<array{name: string, path: string, schema: list<string>, data: list<string>}>
     *
     * @throws InvalidArgumentException
     */
    public function select(array $names): array
    {
        $violations = $this->manifestViolations();
        $selected = [];

        foreach ($names as $name) {
            if (! preg_match(self::NAME, $name)) {
                $violations[] = "\"{$name}\" is not a migration name (a path, a wildcard or \"all\" cannot be selected)";

                continue;
            }

            if (isset($selected[$name])) {
                $violations[] = "\"{$name}\" was selected twice";

                continue;
            }

            if (! array_key_exists($name, $this->manifest)) {
                $violations[] = "\"{$name}\" is not an approved candidate (not in database/rehearsal/candidates.php)";

                continue;
            }

            $path = $this->path($name);
            $real = is_file($path) ? realpath($path) : false;
            $directory = realpath($this->directory);

            if ($real === false || $directory === false || dirname($real) !== $directory) {
                $violations[] = "\"{$name}\" is not a file directly inside the migrations directory";

                continue;
            }

            $expected = (string) ($this->manifest[$name]['sha256'] ?? '');

            if (! hash_equals($expected, hash_file('sha256', $real))) {
                $violations[] = "\"{$name}\" has changed since it was approved (sha256 mismatch): review it again and update the manifest";

                continue;
            }

            $selected[$name] = [
                'name' => $name,
                'path' => $real,
                'schema' => array_values((array) ($this->manifest[$name]['schema'] ?? [])),
                'data' => array_values((array) ($this->manifest[$name]['data'] ?? [])),
            ];
        }

        if ($violations !== []) {
            throw new InvalidArgumentException("Migration set refused:\n  - ".implode("\n  - ", array_unique($violations)));
        }

        ksort($selected);

        return array_values($selected);
    }

    /**
     * Absolute paths of the baseline migrations, in the pinned order. Refuses, like {@see select()}, when the
     * directory does not match the pinned sets, so no caller can build a baseline from drifted files.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public function baselinePaths(): array
    {
        $this->select([]);

        return array_map(fn (string $name) => (string) realpath($this->path($name)), $this->baselineNames());
    }

    private function path(string $name): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$name.'.php';
    }
}
