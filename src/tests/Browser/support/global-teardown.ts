import { spawnSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Suite-level cleanup of the tickets a browser run created (EPIC-017 §25.3, WP2 G3-A).
 *
 * Playwright runs this ONCE, in the runner process, after every worker has finished (a failed or
 * interrupted-by-signal run included, but not a killed one: SIGKILL, an OOM kill or a lost container
 * run nothing, so those leave their tickets for the next recovery sweep in `./dev test:e2e`).
 *
 * It is the only place the browser suite runs `e2e:cleanup-tickets`. The command is untargeted by design
 * and every worker's tickets share the `E2E-` prefix, so calling it from a test, a fixture or a worker
 * would delete tickets other workers are still using. `E2eCleanup` (per test) deliberately does not.
 *
 * WHO runs it depends on how the suite was started, because the private attachment files are `0600` in `0700`
 * directories owned by the web-server user (`portal`) and this process runs as whoever launched Playwright:
 *
 *  - `./dev test:e2e` sets E2E_TICKET_CLEANUP_OWNER=dev. The wrapper then runs the command itself, as `portal`,
 *    after Playwright has exited, and reconciles the ticket rows and attachment directories. This hook does
 *    nothing but say so: a hosted runner's UID could not see those directories, and the cleanup must not be
 *    attempted under an identity that cannot do it.
 *  - Started by hand (`npm run test:e2e`) there is no wrapper, so this hook is responsible. It runs the command as
 *    the current user. If that user cannot see the files the command refuses and exits non-zero (it never reads
 *    "cannot look" as "already gone"), which throws here and fails the run. Permissions are not relaxed.
 *
 * A non-zero result throws, which Playwright reports as a failed run: a ticket left behind, a storage
 * error and a refusal (linked time or tasks, an unsupported environment) are never swallowed. `php` missing is
 * reported the same way rather than skipped, because a skipped cleanup is a silent leak.
 */
const appRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');

/** Set by `./dev test:e2e` for the Playwright it launches (see scripts/dev/commands.sh). */
export const CLEANUP_OWNER_VARIABLE = 'E2E_TICKET_CLEANUP_OWNER';

export default function globalTeardown() {
    if (process.env[CLEANUP_OWNER_VARIABLE] === 'dev') {
        process.stdout.write(
            '\n[e2e cleanup] Handed to ./dev test:e2e: it removes the suite\'s tickets as the web-server user once Playwright has exited.\n',
        );

        return;
    }

    const result = spawnSync('php', ['artisan', 'e2e:cleanup-tickets', '--no-interaction'], {
        cwd: appRoot,
        encoding: 'utf8',
    });

    if (result.stdout) process.stdout.write(`\n[e2e cleanup] ${result.stdout.trim().replace(/\n/g, '\n[e2e cleanup] ')}\n`);
    if (result.stderr) process.stderr.write(result.stderr);

    if (result.error) {
        throw new Error(
            `E2E ticket cleanup could not start (${result.error.message}). Run the suite with ./dev test:e2e.`,
        );
    }

    if (result.status !== 0) {
        throw new Error(
            `E2E ticket cleanup failed (exit ${result.status}). Tickets may remain; fix the cause shown above ` +
                'and run ./dev test:e2e: its recovery sweep runs the cleanup as the web-server user.',
        );
    }
}
