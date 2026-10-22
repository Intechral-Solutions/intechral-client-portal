<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

/**
 * A throw-away deployment for the cron wrapper tests (R0.9 WP3-B1): a `portal` root with `shared/`, any number of "releases" that each hold their own copy of
 * the wrapper (as a deployed release does), a `current` symlink replaced atomically, and a stub PHP binary whose behaviour a test selects by writing a mode
 * file. Everything lives in one directory under the system temp directory (a RAM disk on the development machine, so each test keeps it tiny) and nothing
 * outside it is touched: no database, no container, no volume, no crontab.
 *
 * The wrapper runs exactly as cron runs it: `env -i` with a minimal environment, `/bin/sh <current>/scripts/cron/portal-cron.sh <task>`.
 */
final class PortalCronSandbox
{
    public const WRAPPER = __DIR__.'/../../scripts/cron/portal-cron.sh';

    /** The stub "PHP": records how it was called and then behaves as the current mode says. */
    private const STUB = <<<'SH'
#!/bin/sh
d=${STUB_DIR:?}
printf '%s\n' "$*" >>"$d/argv.log"
printf 'marker=%s stage=%s\n' "${PORTAL_CRON_MARKER:-unset}" "${PORTAL_CRON_STAGE:-unset}" >>"$d/env.log"
echo $$ >"$d/pid"
mode=$(cat "$d/mode" 2>/dev/null || echo ok)
case $mode in
    ok) exit 0 ;;
    exit:*) exit "${mode#exit:}" ;;
    print:*) cat "$d/output"; exit "${mode#print:}" ;;
    hold)
        : >"$d/started"
        while [ ! -e "$d/release" ]; do sleep 0.1; done
        cat "$(dirname "$1")/lazy.txt" >"$d/lazy.out" 2>&1
        exit 0 ;;
    ignore-term) trap '' TERM; : >"$d/started"; while true; do sleep 0.1; done ;;
    honor-term) trap 'exit 0' TERM; : >"$d/started"; while true; do sleep 0.1; done ;;
    orphan) setsid sh -c 'sleep 3' "orphan-of-$d" & exit 0 ;;
esac
SH;

    public readonly string $dir;

    public readonly string $root;

    public readonly string $stubDir;

    public readonly string $stub;

    /** @param  string  $between  an extra path segment between the sandbox directory and the portal root (for example one with a space in it) */
    public function __construct(string $between = '')
    {
        $this->dir = sys_get_temp_dir().'/portal-cron-'.bin2hex(random_bytes(5));
        $this->root = $this->dir.($between === '' ? '' : '/'.$between).'/portal';
        $this->stubDir = $this->dir.'/stub';
        $this->stub = $this->dir.'/stub-php.sh';

        mkdir($this->root.'/shared', 0700, true);
        mkdir($this->root.'/releases', 0700, true);
        mkdir($this->stubDir, 0700, true);
        file_put_contents($this->stub, self::STUB."\n");
        chmod($this->stub, 0755);
    }

    /**
     * A release directory with its own copy of the wrapper (optionally altered, so two releases can be told apart by what their wrapper passes to PHP),
     * a fake artisan, and a file the stub reads lazily the way PHP autoloads. The first release created becomes `current`.
     *
     * @param  array<string, string>  $replace
     */
    public function release(string $name, array $replace = [], bool $withArtisan = true): string
    {
        $release = $this->root.'/releases/'.$name;
        mkdir($release.'/scripts/cron', 0755, true);

        $script = (string) file_get_contents(self::WRAPPER);
        $script = strtr($script, $replace);
        file_put_contents($release.'/scripts/cron/portal-cron.sh', $script);
        chmod($release.'/scripts/cron/portal-cron.sh', 0755);
        $withArtisan && touch($release.'/artisan');
        file_put_contents($release.'/lazy.txt', "lazy-from-{$name}\n");

        if (! is_link($this->root.'/current')) {
            $this->switchTo($name);
        }

        return $release;
    }

    /** The atomic replacement a deployment performs (`ln -s` to a temporary name, then `mv -T`): rename(2) over the old link. */
    public function switchTo(string $name): void
    {
        $temporary = $this->root.'/current.new';
        @unlink($temporary);
        symlink($this->root.'/releases/'.$name, $temporary);
        rename($temporary, $this->root.'/current');
    }

    public function entry(): string
    {
        return $this->root.'/current/scripts/cron/portal-cron.sh';
    }

    public function mode(string $mode): void
    {
        file_put_contents($this->stubDir.'/mode', $mode);
    }

    public function output(string $text): void
    {
        file_put_contents($this->stubDir.'/output', $text);
    }

    /** The shortened timings a test may use (the wrapper refuses overrides without PORTAL_CRON_TEST). TERM 3 s, KILL 1 s, retry_after floor 6 s. */
    public function timings(array $override = []): array
    {
        return $override + [
            'PORTAL_CRON_TEST' => '1', 'PORTAL_CRON_QUEUE_MAX_TIME' => '1', 'PORTAL_CRON_QUEUE_JOB_TIMEOUT' => '1', 'PORTAL_CRON_QUEUE_TERM' => '3',
            'PORTAL_CRON_SCHED_TERM' => '3', 'PORTAL_CRON_KILL_AFTER' => '1', 'PORTAL_CRON_RETRY_AFTER_FLOOR' => '6', 'PORTAL_CRON_STALE_GRACE' => '1',
        ];
    }

    /**
     * @param  array<string, string>  $environment  added to the cron-like environment
     * @param  list<string>  $arguments  what follows the script (the task)
     */
    public function process(array $arguments, array $environment = [], ?string $entry = null, string $path = '/usr/bin:/bin', ?string $php = null): Process
    {
        $variables = $environment + ['PATH' => $path, 'PORTAL_ROOT' => $this->root, 'PORTAL_PHP' => $php ?? $this->stub, 'STUB_DIR' => $this->stubDir];
        $command = ['env', '-i'];

        foreach ($variables as $name => $value) {
            $command[] = $name.'='.$value;
        }

        return new Process([...$command, '/bin/sh', $entry ?? $this->entry(), ...$arguments], $this->dir, null, null, 60);
    }

    public function run(string $task, array $environment = [], ?string $entry = null, string $path = '/usr/bin:/bin'): Process
    {
        $process = $this->process([$task], $environment, $entry, $path);
        $process->run();

        return $process;
    }

    public function start(string $task, array $environment = [], ?string $entry = null, string $path = '/usr/bin:/bin'): Process
    {
        $process = $this->process([$task], $environment, $entry, $path);
        $process->start();

        return $process;
    }

    public function waitFor(callable $condition, float $seconds = 10.0): bool
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return true;
            }
            usleep(50_000);
        }

        return (bool) $condition();
    }

    public function stubStarted(): bool
    {
        return is_file($this->stubDir.'/started');
    }

    /** Let a stub in `hold` mode finish. */
    public function releaseStub(): void
    {
        touch($this->stubDir.'/release');
    }

    public function stubPid(): int
    {
        return (int) trim((string) @file_get_contents($this->stubDir.'/pid'));
    }

    /** @return list<string> one entry per stub invocation: the artisan path and its arguments */
    public function invocations(): array
    {
        $lines = @file($this->stubDir.'/argv.log', FILE_IGNORE_NEW_LINES);

        return $lines === false ? [] : $lines;
    }

    public function logPath(string $task): string
    {
        return $this->root.'/shared/logs/cron-'.$task.'.log';
    }

    public function log(string $task): string
    {
        return (string) @file_get_contents($this->logPath($task));
    }

    public function lockPath(string $task): string
    {
        return $this->root.'/shared/run/'.$task.'.lock';
    }

    /** Record a holder as the wrapper does, with an arbitrary start time, for the stale-holder test. */
    public function writeHolder(string $task, int $since, string $release = 'old-release'): void
    {
        @mkdir($this->root.'/shared/run', 0700, true);
        file_put_contents($this->root.'/shared/run/'.$task.'.holder', "4242 {$since} {$release}\n");
    }

    /** Hold the task's lock with a real flock process (as another wrapper would) for `$seconds`. */
    public function holdLock(string $task, int $seconds): Process
    {
        @mkdir($this->root.'/shared/run', 0700, true);
        $process = new Process(['flock', $this->lockPath($task), 'sleep', (string) $seconds]);
        $process->start();
        usleep(300_000);

        return $process;
    }

    /** Whether the lock can be taken right now. */
    public function lockIsFree(string $task): bool
    {
        $process = new Process(['flock', '-n', $this->lockPath($task), 'true']);
        $process->run();

        return $process->getExitCode() === 0;
    }

    /**
     * Live processes (not zombies) whose command line mentions this sandbox: wrapper, timeout, stub, flock holders.
     *
     * @return list<int>
     */
    public function survivors(): array
    {
        $found = [];

        foreach (glob('/proc/[0-9]*') ?: [] as $entry) {
            $pid = (int) basename($entry);

            if ($pid === getmypid()) {
                continue;
            }

            $command = @file_get_contents($entry.'/cmdline');
            $stat = @file_get_contents($entry.'/stat');

            if ($command === false || $stat === false || ! str_contains($command, $this->dir)) {
                continue;
            }

            $state = substr($stat, (int) strrpos($stat, ')') + 2, 1);

            if ($state !== 'Z') {
                $found[] = $pid;
            }
        }

        return $found;
    }

    public function isAlive(int $pid): bool
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");

        if ($stat === false) {
            return false;
        }

        return substr($stat, (int) strrpos($stat, ')') + 2, 1) !== 'Z';
    }

    public function mode_of(string $path): string
    {
        clearstatcache(true, $path);

        return substr(sprintf('%o', fileperms($path)), -4);
    }

    /** Stop anything still running from this sandbox, then remove it. */
    public function destroy(): void
    {
        foreach ($this->survivors() as $pid) {
            @posix_kill($pid, SIGKILL);
        }

        usleep(100_000);

        if (str_starts_with($this->dir, sys_get_temp_dir().'/portal-cron-')) {
            (new Process(['rm', '-rf', '--', $this->dir]))->run();
        }
    }
}
