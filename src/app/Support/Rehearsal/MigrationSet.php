<?php

namespace App\Support\Rehearsal;

use InvalidArgumentException;

/**
 * Which migrations form the rehearsal baseline and which may be applied as candidates.
 *
 * The baseline is every file in the migrations directory that the candidate manifest does not name. A
 * candidate is chosen by name; it must be in the manifest (the review record), present in the directory, and
 * its file must still hash to the recorded SHA-256. Anything else is refused, and no path or PHP file
 * supplied by the caller is ever loaded. The directory and manifest are constructor arguments only so tests
 * can exercise the rules against a throw-away directory; the command always uses {@see repository()}.
 */
final class MigrationSet
{
    private const NAME = '/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/';

    /** @param  array<string, array<string, mixed>>  $manifest */
    public function __construct(private readonly string $directory, private readonly array $manifest) {}

    public static function repository(): self
    {
        /** @var array<string, array<string, mixed>> $manifest */
        $manifest = require database_path('rehearsal/candidates.php');

        return new self(database_path('migrations'), $manifest);
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

    /** @return list<string> the migrations the baseline is built from */
    public function baselineNames(): array
    {
        return array_values(array_diff($this->names(), $this->candidateNames()));
    }

    /**
     * Problems with the manifest itself (a stale entry, a malformed one).
     *
     * @return list<string>
     */
    public function manifestViolations(): array
    {
        $violations = [];

        foreach ($this->manifest as $name => $entry) {
            if (! preg_match(self::NAME, (string) $name)) {
                $violations[] = "manifest entry \"{$name}\" is not a migration name";

                continue;
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
     * Validate the operator's selection and return what to run, in migration order.
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
            throw new InvalidArgumentException("Candidate selection refused:\n  - ".implode("\n  - ", array_unique($violations)));
        }

        ksort($selected);

        return array_values($selected);
    }

    /** @return list<string> absolute paths of the baseline migrations */
    public function baselinePaths(): array
    {
        return array_map(fn (string $name) => (string) realpath($this->path($name)), $this->baselineNames());
    }

    private function path(string $name): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$name.'.php';
    }
}
