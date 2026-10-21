<?php

namespace App\Console\Commands;

use App\Support\Database\DatabaseProfile;
use App\Support\Runtime\RuntimeChallenges;
use App\Support\Runtime\RuntimeProbes;
use App\Support\Runtime\RuntimeProfile;
use App\Support\Runtime\RuntimeVerification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

#[Signature('runtime:preflight {--json : Print the report as JSON} {--issue : After the local checks pass, issue a cross-context challenge (A2)} {--verify : Also verify that the CLI, web, queue and scheduler contexts answered the current challenge (A2)} {--challenge= : With --verify, the challenge id --issue printed; a different current challenge fails as superseded}')]
#[Description('Production runtime readiness for the shared-host profile: cached production configuration, database cache/session/queue, a delivering SMTP transport, the database profile, and a disposable cache and lock probe (Release 0.9, WP3-A). Run after `migrate`; fails closed. --issue / --verify add the cross-context proof')]
class RuntimePreflight extends Command
{
    public function handle(): int
    {
        if ($this->option('issue') && $this->option('verify')) {
            $this->components->error('--issue and --verify are separate steps: issue a challenge, trigger the contexts, then verify.');

            return self::FAILURE;
        }

        if ($this->option('challenge') !== null && ! $this->option('verify')) {
            $this->components->error('--challenge only goes with --verify.');

            return self::FAILURE;
        }

        [$violations, $probed] = $this->local();
        $extra = $this->option('issue') ? ['mode' => 'issue', 'challenge' => null] : [];

        if ($this->option('issue') && array_values(array_unique($violations)) === []) {
            try {
                $challenge = RuntimeChallenges::issue();
                $extra['challenge'] = ['id' => $challenge['id'], 'issued_at' => $challenge['issued_at'], 'expires_at' => $challenge['expires_at'], 'ttl_seconds' => RuntimeChallenges::TTL];
            } catch (RuntimeException $e) {
                $violations[] = $e->getMessage();
            }
        }

        $verification = null;

        if ($this->option('verify')) {
            $verification = RuntimeVerification::run($this->option('challenge'));
            $violations = [...$violations, ...$verification['violations']];
            $extra = ['mode' => 'verify', 'challenge_status' => $verification['status'], 'challenge' => $verification['challenge'], 'contexts' => $verification['contexts']];
        }

        $violations = array_values(array_unique($violations));
        $passed = $violations === [];
        $diagnostics = RuntimeProfile::diagnostics();

        if ($this->option('json')) {
            $this->line(json_encode(['passed' => $passed, 'violations' => $violations, 'probes_ran' => $probed, 'diagnostics' => $diagnostics] + $extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $passed ? self::SUCCESS : self::FAILURE;
        }

        foreach ($violations as $violation) {
            $this->components->error($violation);
        }

        $this->newLine();
        foreach ($diagnostics as $name => $value) {
            $this->line(sprintf('  %-22s %s', $name, is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value));
        }
        $this->newLine();

        if ($verification !== null) {
            $this->verificationReport($verification);
        }

        if ($passed && ($extra['mode'] ?? null) === 'issue') {
            $this->issueReport($extra['challenge']);
        } elseif ($passed && $verification !== null) {
            $this->components->info('Every context answered the challenge and agrees: CLI, web, queue and scheduler share the configuration, the release and the APP_KEY.');
        } elseif ($passed) {
            $this->components->info('The runtime profile is satisfied. The probes created and removed one cache row and one cache lock row; nothing else was written.');
        } elseif (! $probed) {
            $this->components->warn('The live cache and lock probes did not run.');
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{0: list<string>, 1: bool} the local checks (A1, unchanged): violations and whether the live probes ran */
    private function local(): array
    {
        $violations = RuntimeProfile::configuration();
        $probed = false;

        try {
            $db = DB::connection();
            $database = DatabaseProfile::safelyWithMigrations($db);
            $violations = [...$violations, ...$database];

            // The live probes need a reachable, migrated database; when it cannot even be verified they would only repeat that.
            if (! collect($database)->contains(fn (string $v) => str_starts_with($v, 'the database could not be verified'))) {
                $probed = true;
                $violations = [...$violations, ...RuntimeProbes::run($db)];
            }
        } catch (Throwable $e) {
            $violations[] = 'the database connection could not be opened: '.DatabaseProfile::describe($e);
        }

        return [$violations, $probed];
    }

    /** @param  array{id: string, issued_at: int, expires_at: int, ttl_seconds: int}  $challenge */
    private function issueReport(array $challenge): void
    {
        $this->components->info('The local checks passed and a challenge was issued.');
        $this->line('  challenge id        '.$challenge['id']);
        $this->line('  valid until         '.gmdate('Y-m-d H:i:s', $challenge['expires_at']).' UTC ('.intdiv($challenge['ttl_seconds'], 60).' minutes)');
        $this->line('  recorded            cli (this process); one queue job is waiting for a worker');
        $this->newLine();
        $this->line('  Now trigger the other contexts, then run: php artisan runtime:preflight --verify --challenge='.$challenge['id']);
        $this->line('    web         request GET /up on the production URL');
        $this->line('    queue       run the bounded queue worker once');
        $this->line('    scheduler   run the scheduler once');
        $this->newLine();
    }

    /** @param  array{status: string, challenge: array{id: string, expires_at: int, seconds_remaining: int}|null, contexts: array<string, array{status: string, differences: list<string>, php: string|null, sapi: string|null}>}  $verification */
    private function verificationReport(array $verification): void
    {
        $this->line('  challenge           '.($verification['challenge']['id'] ?? '(none)').'  ['.$verification['status'].']');

        foreach ($verification['contexts'] as $context => $result) {
            $this->line(sprintf('  %-19s %-9s %s', $context, $result['status'], $result['php'] !== null ? 'php '.$result['php'].' ('.$result['sapi'].')' : ''));
        }

        $this->newLine();
    }
}
