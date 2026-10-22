# Cron Execution: the Bounded Queue Worker and Scheduler (R0.9 WP3-B1)

**Authority:** the Release 0.9 shared-hosting runtime gate (database queue, no persistent worker, two five-minute cron entries), the WP3-B design gate and the owner rulings D1 to D4
of 2026-10-21. **Companions:** [Runtime Preflight](./runtime-preflight.md) (the A1 profile, the A2 cross-context check and the A3 startup guard this runs under),
[Notification Delivery](./notification-delivery.md) (why `retry_after` matters and what a send can and cannot promise), [Database Profile](./database-profile.md).

Release 0.9 runs its background work from two independent cron entries on a shared host that allows no daemon. One versioned POSIX shell script,
[`src/scripts/cron/portal-cron.sh`](../../src/scripts/cron/portal-cron.sh), is the only thing those entries call. This document says what it guarantees, what it
only attempts, and how to operate and rehearse it. **Nothing here has been run on the Namecheap host: the timings are implementation defaults that satisfy tested
relationships, not proven limits of that host** (see [Staging rehearsal](#staging-rehearsal-documented-not-executed)).

## The cron entries

```
*/5    * * * *  PORTAL_ROOT=/home/ACCOUNT/portal /bin/sh /home/ACCOUNT/portal/current/scripts/cron/portal-cron.sh schedule
2-59/5 * * * *  PORTAL_ROOT=/home/ACCOUNT/portal /bin/sh /home/ACCOUNT/portal/current/scripts/cron/portal-cron.sh queue
```

- **No redirect and no `--env`.** cPanel mails whatever a cron job prints, so the script prints only on a failure it chooses to report; a redirect to `/dev/null` would discard failures.
  The profile (A3) is read from the cached configuration alone.
- The queue entry is staggered two minutes after the scheduler so the two boots do not coincide. The wrapper records where in its slot each tick starts (see [Scheduler](#scheduler-and-the-0200-utc-task)).
- Cron runs in the host's Eastern time. Both entries are wildcard-hour, so daylight saving never skips or doubles a tick; the application and the wrapper's log use UTC.
- The explicit PHP binary is `/opt/alt/php85/usr/bin/php`. (`/usr/bin/php` on that host is a php-cgi entry point.) `PORTAL_PHP` replaces it for development and CI only.

| What | Where | Mode |
|---|---|---|
| Wrapper | `<release>/scripts/cron/portal-cron.sh` (versioned with the release) | 0755 |
| Shared directories | `$PORTAL_ROOT/shared/run`, `$PORTAL_ROOT/shared/logs` (created if missing; made 0700 on every run) | 0700 |
| Locks | `shared/run/queue.lock`, `shared/run/schedule.lock` (never deleted, rotated or replaced) | 0600 |
| Holder record | `shared/run/<task>.holder` (`pid start-epoch release`) | 0600 |
| Alert state | `shared/run/<task>.alert` (`ok` or `fail <fingerprint> <epoch>`) | 0600 |
| Log | `shared/logs/cron-<task>.log` and one older generation `.1` | 0600 |

`shared/` must already exist (it is part of the deployment layout); a missing `PORTAL_ROOT`, `shared`, PHP binary or required tool (`flock`, `timeout`, `readlink -f`, `awk`, GNU `date`) is a
configuration error that stops the run before anything is launched, exits 64 or 70, and is printed to cron on **every** tick (it cannot be recorded locally until the layout is valid).

## Release safety

Deployments are immutable release directories selected by a `current` symlink. Cron opens `.../current/scripts/cron/portal-cron.sh`, so the shell reads whichever release `current`
names at that instant. Looking at `current` again later to find the application could name a newer release and mix one release's wrapper with another's files.

**Stage 1 / stage 2.** The script resolves its own pathname **once** (`readlink -f "$0"`) and replaces itself (`PORTAL_CRON_STAGE=$$ exec /bin/sh "$self"`) with a shell reading the file at that
**physical** path. Everything after that (its own text, the release root, `artisan`, `cd`) comes from one release, and PHP is given the physical `…/releases/<id>/artisan`, which is
what A2's release identity (`realpath(base_path())`) measures. `current` is never consulted again during the invocation.

What this does **not** do, stated exactly:

- **The stage marker is the shell's own PID.** `exec` keeps the process ID, so stage 2 recognises itself by `PORTAL_CRON_STAGE == $$`. A value inherited from the environment (a stale export,
  `2`, `banana`, an empty string) is not this shell's PID and cannot skip the resolution; the marker is unset before PHP starts, so it never reaches the application. Because a PID can in principle
  coincide with a stale value, stage 2 also checks that it really was started through its physical path (`readlink -f "$0" = "$0"`) and exits 70 (`not started through its physical path`) if not, so even that
  coincidence cannot mix releases. Paths that contain spaces are supported (every expansion is quoted). **Release directory names must not contain whitespace** (they are fields in the log records); that is an accepted constraint,
  not enforced by a policy.
- The first lines of the script (the prologue that resolves and re-executes) come from whichever release the entry point showed when the shell opened it. They only resolve and re-execute,
  so they carry no release-specific behaviour, but if a future release changed that prologue, an invocation straddling a swap would run the older prologue. Keep it minimal and stable.
- A **running** invocation stays on its release until it ends (at most about 270 s), including a worker that finishes after `current` moved. The next tick uses the new release.
  A new release's schema can therefore meet an old release's worker for up to that long; migrations must be compatible with the previous release for that window.
- `readlink` of `current` is atomic only if `current` is replaced atomically (below). A plain `ln -sfn` leaves a moment with no `current`; a tick in that moment fails loudly
  (`sh: ... No such file`, mailed by cron) rather than running an unexpected release.

**Locks and logs are shared across releases** (`$PORTAL_ROOT/shared`), so a tick from the new release still finds the old release's worker holding the lock and skips.

Tested (`tests/Unit/Cron/PortalCronWrapperTest.php`, "release handling"): normal execution through `current`; `current` switched while a job is running (the job stays on its release and
keeps reading from it lazily, the next tick skips on the shared lock, then uses the new release); `current` switched **between the shell opening the script and the wrapper resolving it**
(one release for the whole invocation; verified by giving the two releases' wrapper copies different arguments, and by removing the re-execution, which fails that test), repeated with an inherited
`PORTAL_CRON_STAGE` of `2`, `banana`, empty and `0` (no re-execution loop: one run, one PHP call); the PID-coincidence case (refused with exit 70); and a root path containing spaces.

### Deployment checklist (not a deployment framework)

1. Build the new release directory completely; it is never modified afterwards (A2 assumes this).
2. In it: link or copy the shared `.env` and `storage`; `php artisan config:cache`; `migrate --force`; `database:verify`; `runtime:preflight` (must exit 0). Do all of it **before** the swap:
   a tick that meets an uncached production configuration is refused by A3.
3. Swap atomically: `ln -s releases/<new> current.new && mv -T current.new current`.
4. After the swap: `php artisan queue:restart` (a running old worker finishes its job and stops), then the A2 cross-context sequence.
5. **Keep the previous release for at least 15 minutes** (longer than any worker's life, 270 s, with a margin): PHP loads classes lazily from its release directory, and deleting it under a running worker breaks that worker.
6. Never run `queue:work` or `schedule:run` by hand in production outside the wrapper (below).

## Timing and reservation safety

Implementation defaults (`portal-cron.sh`; the tests pin these relationships, not the host):

| Setting | Value | Role |
|---|---|---|
| `DB_QUEUE_RETRY_AFTER` | 300 s | unchanged; A1/A3 refuse less than 300 |
| `queue:work --max-time` | 120 s | stops the worker **claiming** new jobs; checked between jobs, it does **not** interrupt a job |
| `queue:work --timeout` | 120 s | a per-job alarm (needs `pcntl`); when it fires Laravel kills the worker with SIGKILL (exit 137) |
| `--stop-when-empty --sleep=1 --tries=3 --backoff=60` | | an empty drain costs about 1.6 s (boot plus one second; measured), retries are picked up by the next tick |
| `timeout` TERM | 255 s | from the start of the worker process |
| `timeout -k` | 15 s | SIGKILL, so the worker is gone by 270 s |
| scheduler TERM / KILL | 240 s / +15 s | `schedule:run` |

**What this establishes.** Every job a worker reserves is reserved after the worker started. The OS-level bound (`timeout`, then SIGKILL) ends the worker by 270 s, and `retry_after` is
300 s, so under normal process conditions no reservation held by a live worker becomes available to another worker. The lock independently allows only one worker process at a time.
`tests/Unit/Cron` pins `QUEUE_TERM + KILL_AFTER < RuntimeProfile::MIN_RETRY_AFTER`, `QUEUE_MAX_TIME + QUEUE_JOB_TIMEOUT < QUEUE_TERM`, and that the wrapper's floor equals the profile's,
and the wrapper refuses to run if its own timings (or a test's overrides) break them.

**What it does not establish.** `timeout` and SIGKILL are a strong operating-system bound, not a mathematical guarantee:

- A process in uninterruptible I/O cannot be killed until the call returns, and `timeout` itself needs CPU to deliver its signals; extreme host scheduling or an LVE stall can delay both. (A stuck process
  keeps the lock, so no second worker starts beside it.)
- `--max-time` is not a per-job interruption: a job claimed at the end of the window runs on. `--timeout` is a signal-driven alarm and Laravel acts on it only when the blocked call returns
  (`MAIL_TIMEOUT` is an idle limit, not a send deadline; see [Notification Delivery](./notification-delivery.md)). The 120 + 120 s plan leaves 15 s between the planned graceful end (240 s) and TERM (255 s);
  a slow send can therefore be cut by TERM or SIGKILL, and **not every job necessarily completes gracefully**.
- Delivery stays **at least once**: a worker killed after SMTP accepted a message and before the job was deleted sends it again on its retry, and a job killed this way comes back no sooner than
  300 s after it was reserved, so the next tick or the one after.
- A consumer outside the wrapper (a hand-run worker, a duplicate cron entry with another lock path) is outside all of this.

**The scheduler** has the same shape with 240 + 15 s. That is below the smallest tick spacing seen on the host (269 s), but spacing is jittery and nothing here claims a scheduler tick can never meet the
previous one still running; the lock makes that a recorded skip (below), not an overlap.

## Locking

- One independent lock per task: `flock -n` on descriptor 9, opened on the lock file with `>>` (created 0600, never truncated, deleted or replaced; log rotation and every other step leave it alone).
- **Nonblocking.** A busy lock is not an error: the invocation writes `SKIP lock held holder_pid=… holder_age_s=… holder_release=…` to its log, prints nothing (no cron mail) and exits 0.
- **Lifetime.** The lock belongs to the open file description, which `timeout`, PHP and any child inherit. The kernel releases it when the last of them exits, including after SIGKILL, so there is
  no stale lock file. A child that outlives the wrapper keeps the lock (conservative; tested), and a wrapper killed mid-run leaves its worker holding the lock and a `START` with no `END` in the log.
- **Persistent contention is a failure.** If the holder record is older than TERM + KILL + 30 s (queue 300 s, scheduler 285 s) and unchanged one second later, the skip is reported as
  `category=lock-stale` (exit 75, rate-limited like any failure) instead of staying silent. A single skip of a worker that is still finishing (or was killed at 270 s) is normal and is not escalated.
  The 16-run cron sample did not show a skip, and the design does not assume one cannot happen.
- Ordinary lock contention is never mailed; it is recorded locally.

## Failure detection

The wrapper classifies each run from `timeout`'s exit status and the application's own marker, in this order:

| Category | When | Wrapper exit |
|---|---|---|
| `timeout` | status 124, or 137 after at least TERM + KILL − 1 s | 124 / 137 |
| `sigkill` | status 137 sooner (a job `--timeout`, the OOM killer, an LVE limit, an operator) | 137 |
| `profile-refused` | non-zero and the A3 refusal text (`Refusing to start `) is present | the status (1) |
| `database` | a valid database marker line (class and SQLSTATE) | PHP's status, or 90 if it was 0 |
| `scheduler` | a valid scheduler marker line (class and the task's exit status) | PHP's status, or 90 if it was 0 |
| `marker-invalid` | a line has the marker prefix but not the exact format; never quoted | PHP's status, or 90 if it was 0 |
| `php-exit` | any other non-zero status | the status |
| `lock-stale`, `layout` | see above; no `artisan` in the release | 75, 70 |

### Why the application emits a marker

Two failures leave Laravel's exit status at 0, verified against the installed Laravel (13.36.0) with real processes:

- **A pop failure.** `queue:work` *reports* a failure to pop a job and carries on; with `--stop-when-empty` it then finds nothing and exits 0. With a cache that does not depend on the database
  (as in the acceptance test) a worker facing an unreachable database logs the error and exits 0. Under the **production** profile (database cache store) a worker that starts with the database
  already down instead fails at its first cache read (the queue-restart check), loudly and uncaught, so its own status is non-zero; the swallowed case there is a loss *after* start. Either way the
  report goes through the exception handler, which is where the marker is emitted.
- **A failed scheduled task.** `schedule:run` exits 0 when a task fails, and in this Laravel it does not even print `FAIL`: it passes the task component a boolean where the component matches
  `TaskResult` integers, so the failed task is rendered **`DONE`**. A parser for the `FAIL` text would never trigger. (Pinned by a test that runs a failing task through the real `schedule:run`; if
  a Laravel upgrade changes it, that test fails and this paragraph should be revisited. The marker does not depend on it.) The signal that is reliable is the `ScheduledTaskFailed` event.

[`App\Support\Runtime\CronFailureMarker`](../../src/app/Support/Runtime/CronFailureMarker.php) writes one line per distinct failure to STDERR, and only when **both** the wrapper set
`PORTAL_CRON_MARKER=1` for that PHP process **and** the running command is `queue:work` or `schedule:run`:

```
@@PORTAL-CRON-FAILURE@@ v1 category=database class=PDOException sqlstate=HY000
@@PORTAL-CRON-FAILURE@@ v1 category=scheduler class=Exception exit=1
```

It never carries SQL, bindings, hosts, connection strings, credentials or any exception message; the class is validated against an identifier pattern (`unknown` otherwise) and the SQLSTATE or exit
status against a fixed one. The wrapper re-validates every field with an anchored pattern before it copies anything into a notification, and ignores marker text that is not at the start of a line.
The exception continues through Laravel's normal reporting (logged, retried, failed) unchanged. A database error raised inside a job (a constraint violation, say) is reported the same way and is also
classified as `database`; the marker cannot tell a connection failure from a query failure, so the category means "a database exception was reported", and the SQLSTATE says which.
The application's own scheduler callbacks (the A2 observation) swallow their database errors by design.

### The scheduler and an unavailable database

With the **database cache store** (the production profile), a database outage does not leave `schedule:run` quietly successful. Before it runs any due task, Laravel 13.36's `ScheduleRunCommand` polls the scheduler
pause flag, a cache read of `illuminate:schedule:paused` (`isPaused()`). On the database store that read needs the database, fails with an uncaught `QueryException` (SQLSTATE `HY000`/2002 for a refused connection), and
`schedule:run` exits **1** on its own; under the wrapper that exception is reported, the marker is emitted, and the wrapper reports `category=database status=1` with only the class and SQLSTATE. Verified with a real
`schedule:run` process, `CACHE_STORE=database` and a closed database port (`PortalCronApplicationTest`, "a scheduler whose database-backed cache is unavailable"), with a negative control: the same tick with a cache
that does not use the database succeeds.

This is a **dependency, not a design property of the wrapper**: it holds because of that pause poll in this Laravel version and because the cache store is the database. If the poll were removed or disabled, or the cache
moved off the database, a scheduler tick against a dead database would not fail at that point, and nothing here promises the behaviour would be unchanged; it would show up only through a due task failing
(`ScheduledTaskFailed`, category `scheduler`), which is not guaranteed on every tick. Re-check it on a Laravel upgrade (the test above does).

### Marker integrity and where the application logs

The marker is a **convention, not authentication**. The wrapper accepts a line that starts with the fixed prefix and matches an exact pattern, and it ignores marker-looking text elsewhere in a line; it does not and cannot prove
that a line came from `CronFailureMarker` and not from other output of the same PHP process. In particular, if the application's log channel writes to **stderr**, an exception whose message contains a newline followed by a
marker-looking line could, in theory, forge one. The consequence is bounded (a false `database` or `scheduler` failure notice with a validated class and SQLSTATE or status; no command is run and nothing else changes), but it
means **production must log to a protected file channel** (`LOG_STACK=daily` with a bounded `LOG_DAILY_DAYS`, above) and not to stderr. The tests set `LOG_CHANNEL=stderr` on purpose so the framework's own report lands in
the sandbox log; that is a test context, not a deployment recommendation. No broader marker protocol (signing, a separate descriptor) is part of B1.

### Nested commands

`CommandStarting` is dispatched for **every** command, including one that a running command starts in the same process through `Artisan::call()` (a job, a scheduled callback). The marker therefore remembers only the
**first** command of the process (`??=`): a nested command must not replace the identity of the `queue:work` or `schedule:run` that is running, and a nested `queue:work` must not make an unrelated outer command
(say `migrate`) emit. A process runs one top-level command, so the first is the right one. Tested in-process and with a real `schedule:run` whose callback starts a nested command and whose next task then fails.

## Logging and alerts

Every run writes, to `shared/logs/cron-<task>.log` (UTC):

```
2026-10-22T02:00:07Z schedule pid=412 START release=20261022-1 slot_offset_s=7
2026-10-22T02:00:09Z schedule pid=412 END status=ok category=ok exit=0 duration_s=2 release=20261022-1
2026-10-22T02:05:31Z queue pid=530 END status=fail category=database php_status=0 exit=90 duration_s=2 release=20261022-1 class=PDOException sqlstate=HY000
2026-10-22T02:07:02Z queue pid=530 SKIP lock held holder_pid=529 holder_age_s=155 holder_release=20261022-1
```

- **Retention.** The log rotates at 512 KB to one older generation (about 1 MB per task); captured output is capped at 64 KB per run. Nothing else grows.
- **Captured output is application output and may be sensitive.** It is kept locally, bounded, in a 0600 file, with control and non-ASCII bytes removed and every line prefixed with `| ` so it cannot pass
  for one of the wrapper's records. The prefixing is done by `awk`, which also ends an output whose last line has no terminating newline, so the wrapper's `END` record always starts on its own line and the log stays
  line-parseable (tested, including with a truncated, unterminated last line). **No claim is made that this removes secrets**; it is never sent to cron. The framework's own reports of a failure (which can name a host, a database or SQL) live there and in `laravel.log`.
- **Cron email.** Success and ordinary lock contention print nothing. A failure prints two fixed lines (task, category, status, release, time, and for the marker categories the validated class and SQLSTATE
  or exit status) and a pointer to the log. The same failure is not mailed again for 24 hours; a changed category, status or marker detail is mailed at once; the first success after a failure
  prints one `RECOVERED` line. Failures that are *suppressed* are still recorded (`ALERT suppressed`) and still make the wrapper exit non-zero. State is per task.
  The state file is not locked (no further locking subsystem): the stale-lock path of a *contender* can write it while the lock holder does, so in a rare race a notice can be sent twice or a `RECOVERED`
  notice can be missed. The effect is on notification only, never on job execution; it is a known limitation to evaluate in staging, and alert behaviour is unchanged without evidence of material risk.
  Errors in the cron line or layout (exit 64/70 before the log exists) are printed on every tick.
- **Laravel's application log** defaults to a single file that grows without limit. Production should set `LOG_STACK=daily` with a bounded `LOG_DAILY_DAYS` (for example 14) in the deployment environment.
  This is deployment configuration; the A1 profile does not check it and WP3-B1 does not add a rule.

**Before staging, confirm that cPanel's cron email address exists and is monitored** (Cron Jobs → Cron Email in cPanel). A failure notice that goes nowhere is the same as no notice.

## Scheduler and the 02:00 UTC task

`tickets:auto-close` is `dailyAt('02:00')` in the application's timezone (UTC) and is **unchanged**. `schedule:run` evaluates which tasks are due when it boots, against the clock then. Because the host's
Eastern offset is a whole number of hours, the `*/5` entry's ticks fall on UTC `:00`, `:05`, … in both summer and winter. The task runs only if a tick **starts and boots inside the 02:00 UTC minute**.

**Accepted risk (owner ruling D4):** if the host starts a tick roughly a minute or more late (the observed completion spacing, 269 to 339 s, shows start offsets moving by tens of seconds; the tail is not
bounded by that evidence), or a tick is skipped on the lock, that day's run is missed. The impact is small (auto-close is idempotent and cutoff-based, so the next day's run closes the same tickets a day later),
but it is a real risk and it is not mitigated here. The wrapper therefore records `slot_offset_s` (seconds past the tick's nominal five-minute slot; the queue entry's slot starts at minute 2) in every `START` line,
so the staging rehearsal can measure the real distribution. The 240 s scheduler bound does not eliminate every possible overlap or missed tick. If staging shows a meaningful risk, a bounded
once-per-UTC-day catch-up is a **separate owner decision**; it is not implemented.

## Relationship to the A2 manual worker instructions

[Runtime Preflight](./runtime-preflight.md#operational-sequence) tells an operator to answer the A2 challenge with `queue:work database --stop-when-empty --max-jobs=1 --tries=1`. In production that
command is replaced by the wrapper: `PORTAL_ROOT=… sh …/current/scripts/cron/portal-cron.sh queue`. The wrapper drains **every** eligible job within its bounds, not one, so the "look at `jobs` first" warning there
still applies (a drain sends the queued mail). It takes the same lock as the cron entry, so a manual run and a tick cannot overlap, and it is the supported way to run a worker by hand. The scheduler step is likewise
`… portal-cron.sh schedule`. `queue:work` and `schedule:run` typed directly are for development.

## Staging rehearsal (documented, not executed)

Not run by B1. **No Namecheap work, cron change, database creation or mail is authorised by this document.** Requirements: a separate staging database and credentials, separate storage, a staging domain,
`APP_ENV=production` (so A1/A3 and the migration guard apply), a safe outbound mail sink the owner controls, no customer data and no live integration secrets.

**Prerequisites (owner, before anything is installed):**

1. Confirm the cPanel cron email recipient exists and is monitored.
2. Read the account's current crontab. Establish whether the temporary WP3-0B diagnostic cron is still installed, and count the entries: the account allows five concurrent cron jobs, overlapping runs each count,
   and a queue holder, a queue contender (briefly), the scheduler, the SSL renewal job and the diagnostic would already be five. Remove the diagnostic only on the owner's instruction; never touch the SSL renewal entry.
3. **Host signal prerequisites, with the Namecheap PHP CLI** (`/opt/alt/php85/usr/bin/php`, over SSH, before relying on the worker's `--timeout`; Laravel's worker alarm and its handling of TERM need them, and
   with `pcntl_async_signals` disabled `queue:work` fails at startup):
   ```
   /opt/alt/php85/usr/bin/php -r 'foreach (["pcntl","posix"] as $e) echo $e, extension_loaded($e) ? ": loaded\n" : ": MISSING\n";
     foreach (["pcntl_async_signals","pcntl_signal","pcntl_alarm","pcntl_signal_dispatch","posix_kill","proc_open"] as $f) echo $f, function_exists($f) ? ": callable\n" : ": MISSING\n";
     echo "disable_functions=[", ini_get("disable_functions"), "]\n";'
   /opt/alt/php85/usr/bin/php -r 'pcntl_async_signals(true); pcntl_signal(SIGALRM, function () { echo "SIGALRM handled\n"; exit(0); }); pcntl_alarm(2); while (true) { usleep(100000); }'
   /opt/alt/php85/usr/bin/php -r 'pcntl_async_signals(true); pcntl_signal(SIGTERM, function () { echo "SIGTERM handled\n"; exit(0); }); posix_kill(getmypid(), SIGTERM); usleep(500000); echo "NOT handled\n";'
   ```
   Required: both extensions loaded; every function callable; `disable_functions` listing none of them (`proc_open` is needed by `schedule:run` for scheduled commands); the alarm handled after about 2 s and the TERM handled.
   These are the CLI's results, which are the ones that matter for cron. They say nothing about the web SAPI. A signal handler also runs only when a blocked call returns (see Timing), so this check proves the mechanism exists, not
   that a worker's `--timeout` is prompt. **The external GNU `timeout` and `flock` protections are separate and do not depend on any of this**: if the signal checks fail, the worker's own `--timeout` is unusable but the
   external bound still ends the worker, and the lock still prevents overlap.
4. Deployment environment: `LOG_STACK=daily`, a bounded `LOG_DAILY_DAYS`, `APP_ENV=production`, the staging `APP_URL`, `SESSION_SECURE_COOKIE=true`, `DB_QUEUE_RETRY_AFTER=300`.

**Sequence:**

0. Run the host signal prerequisites above and record the results.
1. Lay out `releases/`, `shared/{run,logs,storage}`, two releases `r1` and `r2` (a trivial difference), `current` → `r1`.
2. `config:cache`, `migrate --force` (the physical migration), `database:verify`, `runtime:preflight`.
3. Run the wrapper by hand from SSH under `env -i PATH=/usr/bin:/bin` for `queue` and `schedule`: silent, `END status=ok`, 0600 files, 0700 directories.
4. A2: `runtime:preflight --issue`, `curl` `/up` (the real Namecheap web PHP), let the installed cron entries answer, `--verify`.
5. A3: break the profile on purpose (`MAIL_MAILER=log` and `config:cache`): the next ticks are refused, one email arrives, the repeat is silent, the log keeps the rule; repair and confirm one `RECOVERED`.
6. Lock overlap: hold `queue.lock` from an SSH session with `flock` and watch `SKIP` records; hold it with an old holder record to see `lock-stale`.
7. Timeout and cleanup with shortened limits on the host (`PORTAL_CRON_TEST=1` overrides and a stub `PORTAL_PHP` that ignores TERM): exit 124/137, no process left (`ps -u`), lock free.
8. Release switching: swap `current` during a slow stub run, confirm the running one finishes on its release, the next uses the new one, A2 `--verify` passes.
9. Let the real entries run for a few hours and read `slot_offset_s` from both logs to evaluate the 02:00 risk before production acceptance. (A 24-hour run of the short diagnostic is not needed.)
10. Remove the staging entries, database and mail credentials.

**The deliberate long-duration survival test is separate and gated.** It needs human Namecheap confirmation that a cron-launched PHP CLI process of up to about 270 s every five minutes is acceptable (and that
no shorter hard wall-clock limit exists), and **explicit owner approval**. Permission for the setup above is not permission for it.

## Tests and CI

- `tests/Unit/Cron/PortalCronWrapperTest.php`: the wrapper as cron runs it (`env -i`, minimal `PATH`, `/bin/sh current/scripts/…`) against a stub PHP with real `flock` and GNU `timeout` and shortened timings:
  syntax under `sh`/`dash`/`bash --posix`, arguments and layout errors, restricted modes, contention, release on exit/kill, child and killed-wrapper lock behaviour, stale holder, no lock replacement,
  TERM-honouring and TERM-ignoring processes, no survivors, alert state machine, quoted/bounded logs, marker validation, release switching.
- `tests/Unit/Cron/PortalCronApplicationTest.php`: the real Laravel worker and scheduler, including the scheduler against an unreachable database-backed cache and a nested Artisan command. **Acceptance:** a real `queue:work` meets an unreachable database (closed port), exits 0 by itself, and under the wrapper the
  marker is emitted, the wrapper exits non-zero and records `category=database`, with no host, user, database name, password, SQL or message in the notification. A real failing scheduled task, an A3 refusal, and
  successful empty-queue and scheduler invocations.
- `tests/Unit/Runtime/CronFailureMarkerTest.php`: what the marker prints, never prints, and when it stays silent.
- The host-parity smoke ([ci.md](../testing/ci.md#database-profile-in-ci-r09-wp3)) adds one valid worker and scheduler tick through the wrapper on the production profile, an A3 refusal through the wrapper, and a
  real stopped database. No timing is tested in CI beyond the seconds-scale cases above.

## Not covered

- Any run on the host; the survival test; the staging rehearsal; the catch-up for the 02:00 task; alerting on `failed_jobs`; enforcing `LOG_STACK=daily` in the profile.
- The alert-state race and the whitespace constraint on release names (above).
- A stuck-I/O process that `timeout` cannot end (the lock then keeps everything else out and the stale-holder escalation reports it).
