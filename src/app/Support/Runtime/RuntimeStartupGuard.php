<?php

namespace App\Support\Runtime;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fail-closed startup of the production worker and scheduler commands (R0.9 WP3-A, slice A3).
 *
 * In the effective production environment, `queue:work`, `queue:listen`, `schedule:run` and `schedule:work` do not start unless
 * {@see RuntimeProfile::configuration()} (the configuration-only profile `runtime:preflight` also enforces) reports no violation. The guard listens
 * to {@see CommandStarting}, which Laravel dispatches before a command's own work begins; refusing means throwing {@see RuntimeProfileRefused},
 * which propagates out of the console application to the kernel, which returns exit status 1. No job is reserved, no scheduled task runs and no
 * A2 observation is recorded by a refused command, because none of that code has started. (Verified by real-process tests, not assumed.)
 *
 * Deliberately narrow:
 *  - exactly these four command names, compared for equality (the name Laravel dispatches is the command's canonical name, so an alias or an
 *    abbreviation typed by the operator resolves to it); no wildcard, so no other command is affected;
 *  - production only, meaning EITHER the detected environment (which `--env=` replaces) OR the effective cached `app.env` (case-insensitive) says
 *    production ({@see self::isProduction()}); a process that says production in neither is never blocked;
 *  - configuration only: no database query and no cache probe. The configuration being checked is the effective one, and production
 *    requires it cached ({@see RuntimeProfile}), so a refusal is about what the process actually runs with;
 *  - fail closed: if evaluating the profile itself throws, that is a refusal, never a pass;
 *  - one concise critical log entry per refusal, carrying the command and the categorical violations only.
 *
 * `runtime:preflight`, `database:verify`, `migrate`, `config:cache` and every other command stay usable, so an operator can diagnose and repair
 * an invalid profile. The A2 challenge is NOT enforced here: it remains the explicit deployment verification gate.
 * docs/architecture/runtime-preflight.md.
 */
final class RuntimeStartupGuard
{
    /** The only commands that are guarded. */
    public const COMMANDS = ['queue:work', 'queue:listen', 'schedule:run', 'schedule:work'];

    /**
     * Whether this process is, or is configured as, production (review M-1). Two independent sources, either of which is enough:
     *
     *  - Laravel's DETECTED environment (`app()->environment()`), which a `--env=` option on the command line replaces; and
     *  - the effective, normally CACHED, `app.env`, which `--env=` does not touch, compared case-insensitively (the detected one too, so `--env=PRODUCTION` is production).
     *
     * Either one alone can be wrong: `artisan queue:work --env=local` leaves the cached production configuration in place while reporting `local`,
     * and a cached `APP_ENV=Production` is not the exact `production` the profile requires yet is plainly meant as production. Asking only the
     * detected environment let both skip the guard. {@see RuntimeProfile} stays the single authority on whether the configuration is valid (it still
     * requires the exact value `production`); this only decides whether the profile applies at all.
     */
    private static function isProduction(): bool
    {
        foreach ([app()->environment(), config('app.env')] as $environment) {
            if (is_string($environment) && strtolower(trim($environment)) === 'production') {
                return true;
            }
        }

        return false;
    }

    /** @throws RuntimeProfileRefused */
    public function handle(CommandStarting $event): void
    {
        if (! in_array($event->command, self::COMMANDS, true)) {
            return;
        }

        try {
            if (! self::isProduction()) {
                return;
            }

            $violations = RuntimeProfile::configuration();
        } catch (Throwable $e) {
            // Fail closed, applicability included: if we cannot even tell whether this is production, treat it as production. Only the
            // class name: an exception message can carry configuration values.
            $violations = ['the runtime profile could not be evaluated: '.class_basename($e)];
        }

        if ($violations === []) {
            return;
        }

        try {
            Log::critical('Production runtime profile violated: refusing to start a worker or scheduler command.', [
                'command' => $event->command,
                'violations' => $violations,
            ]);
        } catch (Throwable) {
            // A broken log channel must not turn the refusal into a pass; the exception below still stops the command.
        }

        throw new RuntimeProfileRefused($event->command, $violations);
    }
}
