<?php

namespace App\Support\Backup;

use RuntimeException;

/**
 * Runs the MariaDB client tools (`mariadb-dump`, `mariadb`) for the backup tooling without exposing credentials.
 *
 * The account and password are written to the child as an option file on an inherited pipe
 * (`--defaults-extra-file=/dev/fd/3`): never on its command line (visible in `ps`), never in its environment, never
 * on disk. The command line carries only non-secret options and the database name. Anything the tool prints is
 * returned with the account and password replaced by `***`, so an error can be shown or logged.
 */
final class MariaDbClient
{
    /** The client section written to the pipe, from a Laravel connection configuration. */
    public static function optionFile(array $config): string
    {
        $lines = ['[client]'];

        foreach (['user' => $config['username'] ?? '', 'password' => $config['password'] ?? '', 'host' => $config['host'] ?? '', 'port' => $config['port'] ?? '', 'socket' => $config['unix_socket'] ?? ''] as $option => $value) {
            if ((string) $value !== '') {
                $lines[] = $option.'='.self::quote((string) $value);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /** An option-file value in double quotes, escaped the way the client's option parser reads it back. */
    public static function quote(string $value): string
    {
        return '"'.strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']).'"';
    }

    /**
     * Run $binary with the connection's credentials on fd 3. $arguments must not contain secrets. Standard input is
     * read from $stdin (a file path) or is empty; standard output goes to $stdout (a file created under the caller's
     * umask) or is discarded.
     *
     * @param  list<string>  $arguments
     * @return array{exit: int, stderr: string, seconds: float}
     */
    public static function run(string $binary, array $arguments, array $config, ?string $stdin = null, ?string $stdout = null, int $timeout = 3600): array
    {
        $descriptors = [
            0 => $stdin === null ? ['file', '/dev/null', 'r'] : ['file', $stdin, 'r'],
            1 => $stdout === null ? ['file', '/dev/null', 'w'] : ['file', $stdout, 'w'],
            2 => ['pipe', 'w'],
            3 => ['pipe', 'r'],
        ];

        $started = microtime(true);
        $process = proc_open([$binary, '--defaults-extra-file=/dev/fd/3', ...$arguments], $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException("{$binary} could not be started.");
        }

        $stderr = '';

        try {
            fwrite($pipes[3], self::optionFile($config));
            fclose($pipes[3]);
            stream_set_blocking($pipes[2], false);

            while (true) {
                $stderr .= (string) stream_get_contents($pipes[2]);
                $status = proc_get_status($process);

                if (! $status['running']) {
                    $stderr .= (string) stream_get_contents($pipes[2]);
                    $exit = $status['exitcode'];

                    break;
                }

                if (microtime(true) - $started > $timeout) {
                    throw new RuntimeException("{$binary} did not finish within {$timeout} seconds and was stopped.");
                }

                $read = [$pipes[2]];
                $write = $except = null;
                @stream_select($read, $write, $except, 0, 200_000);
            }
        } finally {
            // Also reached when a signal handler throws while we wait: never leave the child running.
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 15);
                usleep(200_000);
                proc_get_status($process)['running'] && proc_terminate($process, 9);
            }
            is_resource($pipes[2]) && fclose($pipes[2]);
            proc_close($process);
        }

        return ['exit' => (int) $exit, 'stderr' => self::redact($stderr, $config), 'seconds' => round(microtime(true) - $started, 3)];
    }

    /** $text with the connection's account and password replaced. */
    public static function redact(string $text, array $config): string
    {
        foreach (['password', 'username'] as $key) {
            $secret = (string) ($config[$key] ?? '');
            if (strlen($secret) >= 3) {
                $text = str_replace($secret, '***', $text);
            }
        }

        return trim($text);
    }
}
