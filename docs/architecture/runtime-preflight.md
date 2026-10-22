# Runtime Preflight: the Production Shape of Release 0.9 (R0.9 WP3-A, slices A1, A2 and A3)

**Authority:** the Release 0.9 shared-hosting runtime gate (database cache, sessions and queue; no Redis; no persistent worker; two five-minute cron
tasks) and the WP3-A design gate rulings of 2026-10-19. **Companions:** [Database Profile](./database-profile.md) (what `database:verify` checks),
[Role Governance](./role-governance.md#permission-cache-security-on-the-database-store-r09-wp2) (why the cache topology matters),
[Notification Delivery](./notification-delivery.md) (why `retry_after` and the mail transport matter).

`php artisan runtime:preflight [--json]` states the production shape the application is built for and refuses anything else. **Slice A1** (this section and the
next three) checks ONE process, one context. **Slice A2** ([Cross-context verification](#cross-context-verification-slice-a2)) adds `--issue` and `--verify`, which prove that the
CLI, the web, a real database queue worker and the scheduler agree with each other. **Slice A3** ([Fail-closed startup](#fail-closed-worker-and-scheduler-startup-slice-a3)) makes the queue worker and scheduler commands refuse to start in production when the configuration-only profile is violated.

Run it **after `migrate`**, with `APP_ENV=production` and the configuration cached. Its local checks (also run first by `--issue` and `--verify`) write two disposable rows (below) and nothing else, and it exits
non-zero on any violation; `--issue` additionally writes the challenge rows described under [Challenge lifecycle](#challenge-lifecycle-and-what-is-written). A check that cannot run is a violation. Development and CI are expected to fail it: it is the production profile, and
`.env.example` is the redis-defaulted development configuration.

## What it checks

**Configuration** (`App\Support\Runtime\RuntimeProfile`; configuration only, no I/O):

| Area | Requirement |
|---|---|
| Application | `app.env` is `production`; `app.debug` is `false`; `app.timezone` is `UTC`; `app.key` is set and valid for the cipher; `app.url` is an `https` URL that is not the local machine (queued mail builds its links from it) |
| Configuration cache | `php artisan config:cache` has been run in this context (so every context reads the same resolved configuration, and `env()` is no longer consulted) |
| Database | `database.default` is `mysql` or `mariadb`; no `read`/`write` split on it (the permission-cache epoch and the queue read through the primary only) |
| Cache | `cache.default` is `database`; `permission.cache.store` is `default`; no `DB_CACHE_CONNECTION` or `DB_CACHE_LOCK_CONNECTION`; table `cache` and lock table `cache_locks`; `cache.prefix` is non-empty |
| Queue | `queue.default` is `database`; no `DB_QUEUE_CONNECTION`; table `jobs`; `retry_after` is an integer of at least **300** (provisional: the cron wrapper's timings keep the worker inside it, [Cron Execution](./cron-execution.md); the host is not yet validated); failed jobs in `failed_jobs` through `database-uuids` on the default connection |
| Session | `session.driver` is `database`; no `SESSION_CONNECTION`; table `sessions`; **`session.secure` is exactly `true`** (`SESSION_SECURE_COOKIE=true`: Laravel's default is `null`, which also sends the cookie over plain http, and `false` or the string `"true"` do not pass) and **`session.http_only` is exactly `true`**. `SameSite` is deliberately not checked: `None` is permitted when Secure is true, and Secure is what is required |
| Mail | `mail.default` is `smtp` (the one transport whose bounds WP1 validated) **and `mail.mailers.smtp.transport` is `smtp`**; **`mail.mailers.smtp.url` (`MAIL_URL`) is empty or null**; host is set and is not the development sink; a valid port; a positive timeout; a real `from` address. `log`, `array`, `failover`, `ses` and the rest are refused, not tolerated |

**Why a mail URL is refused, not parsed.** Laravel parses `mail.mailers.smtp.url` over the mailer's own configuration, and the URL can replace the transport and the credentials the checks above
validated: `MAIL_URL=log://localhost` turns the `smtp` mailer into the log driver (mail is silently not delivered), and `smtp://user:pass@host:port` replaces the host, port and credentials. A non-empty URL
is therefore a violation whatever it says, and the message never contains it (it can carry credentials). An empty string is accepted because Laravel ignores it.

**Database:** [`DatabaseProfile::safelyWithMigrations()`](../../src/app/Support/Database/DatabaseProfile.php), the same call `database:verify` makes (engine, collation,
UTC strict session, REPEATABLE-READ, foreign keys against the manifest; pending migrations make the foreign keys unverifiable, which is a failure). Transaction isolation
is **verified, never enforced** (owner ruling F8).

**Live probes** (`App\Support\Runtime\RuntimeProbes`), each narrowly scoped and disposable:

1. every required table exists: `migrations`, `cache`, `cache_locks`, `jobs`, `failed_jobs`, `sessions`;
2. **cache round trip**: a random value is written through the application cache store and must be found by the permission epoch's own path (its table, the
   primary of the default connection, its key prefix); this proves the cache store and `PermissionCacheEpoch` use the same connection, table and prefix. The
   epoch row itself is never read-created or advanced;
3. **cache lock**: a lock is acquired through the database cache lock, must be recorded in `cache_locks`, must exclude a second holder, and must be gone after release.

**Actual writes, stated exactly:** one `cache` row (random key `<prefix>runtime-preflight:<nonce>`, 60 s) and one `cache_locks` row (random key, 15 s), each
removed in a `finally` and, if that fails, expiring by themselves. No other table is written; in particular nothing is written to `jobs` in this slice.

## Output and secrets

Human-readable and `--json` forms carry the violations and a short diagnostics block: environment, **effective `CACHE_PREFIX`** and **`APP_URL`** (owner ruling F5: the
URL with any credentials, query and fragment removed), cache and queue names, `retry_after`, whether configuration is cached, and the PHP version. No key, password, DSN or
mail credential is ever printed. Messages contain only configuration *names* (an environment, a driver, a table); a value that is not a plain name is printed as
`(unprintable)`. A failing probe or an unreachable database is reported as the exception class (and SQLSTATE) only, never its message, because a query exception's message carries the
host, database and SQL. The tests pin all of this with fake secrets.

## Deployment order

The configuration cache is built **before** the migration, and `runtime:preflight` runs after it ([the database-profile order](./database-profile.md#5-deployment-order); the full procedure is
[Staging First Install](../operations/staging-first-install.md)):

1. `php artisan config:cache` (under `umask 077`, mode verified 0600, because it holds the resolved key and database password; before `migrate --force`, so every later command runs against the configuration all contexts will use)
2. guarded `migrate`, then the mandatory post-migration `database:verify`
3. `php artisan runtime:preflight`: must exit 0. A non-zero exit aborts the release.

Re-run `config:cache` and `runtime:preflight` after any change to the environment file, then prove the contexts against each other with the [cross-context sequence](#operational-sequence) below. That sequence needs the
application **up**: under `php artisan down` the queue worker pauses without taking a job and scheduled events are not due, so the queue and scheduler contexts can never answer (only `GET /up` is excepted from maintenance mode).

## How CI exercises it

The host-parity smoke ([`scripts/ci/host-parity-smoke.sh`](../../scripts/ci/host-parity-smoke.sh), see [ci.md](../testing/ci.md#database-profile-in-ci-r09-wp3)) runs it on the disposable MariaDB with the host's
defaults: refusal without cached configuration, a pass on the production profile (with no probe row left, and with `SESSION_SECURE_COOKIE=true` set explicitly and any inherited `MAIL_URL` neutralised),
and refusals for a `log` mail transport, a `MAIL_URL` selecting the log driver or carrying credentials (also checked to be absent from the output), a session cookie that is not Secure (`false`, unset,
and `false` with `SameSite=None`) or not HttpOnly, a `file` cache store, a `retry_after` of 299 and a missing `cache_locks` table; and it accepts `SameSite=None` on a Secure cookie. The configuration cache it needs is written to a temporary path through `APP_CONFIG_CACHE` (Laravel honours an absolute path), so a developer's own
`bootstrap/cache/config.php` is never read, overwritten, removed or left behind; the script asserts it is untouched.
Unit and feature tests cover every rule one violation at a time (`tests/Unit/Runtime`, `tests/Feature/Runtime`). The shared migration-state rule has its own tests
(`tests/Feature/Runtime/MigrationStateVerificationTest.php`): with a migration pending, and with the migration state unreadable (the repository read fails, or the migrator fails), both
`database:verify` and `runtime:preflight` exit non-zero with the corresponding message, never read the foreign keys, and print none of the failure's message. The simulations are DML in the test's own
transaction (a deleted `migrations` row, rolled back with the test) or a guarded query hook that is disarmed afterwards; none runs DDL.

## Cross-context verification (slice A2)

A1 proves that THIS process is configured for the production shape. It cannot prove that the web server, the cron-launched queue worker and the cron-launched scheduler, which are
separate PHP processes with their own environment, their own `.env` or cached configuration, and possibly their own release directory (a `current` symlink that was swapped, a PHP
pool that still serves the previous release), are the same application. A2 makes each of them report what it observes about itself, for one challenge, and the CLI compares.

### Design in one paragraph

`runtime:preflight --issue` runs the local preflight; only if it passes does it create a **challenge** (random id and nonce, valid for **30 minutes**), record the **CLI**'s own
observation and queue **one** `RecordRuntimeObservation` job, in one database transaction (all three exist afterwards or none does). The other contexts answer on their own:

| Context | What answers | Trigger |
|---|---|---|
| `web` | the `DiagnosingHealth` event of Laravel's `GET /up`, in the real web process | any request to `https://<production host>/up` |
| `queue` | the queued job, run by a real `queue:work` | the bounded worker (or the queue cron entry) |
| `scheduler` | a callback in the existing schedule (`runtime-context-observation`) | `schedule:run` (or the scheduler cron entry) |
| `cli` | the issuing process | recorded at `--issue` |

`runtime:preflight --verify [--challenge=<id>]` recomputes the same observation **in the verifying process** and requires that every context's stored observation exists, is well
formed, belongs to this challenge, was made inside the challenge window, was reported with a single result, and equals the verifier on the compared values below. There is no new route,
no new table, no Redis, no file and no external service; no public endpoint issues or reports anything, and `GET /up` returns exactly what it always did.

### What each observation holds, and what is compared

Derived from the running process, never supplied by a caller:

- **Compared configuration (names only are ever reported):** `app.env`, `app.url` (credentials, query and fragment removed), `cache.default`, `cache.prefix`, `cache.table`,
  `cache.lock_table`, `permission.cache.store`, `permission.cache.key`, `permission.epoch_key` (the effective key of the permission epoch row, i.e. prefix plus name),
  `database.default`, `queue.default`, and whether the configuration is cached.
- **Release identity** and **APP_KEY proof** (below), both bound to this challenge.
- **Informational only, never compared:** PHP version and SAPI. `disable_functions` is not even recorded. The web and the CLI legitimately differ in all three.

Two contexts that use different databases cannot disagree visibly: the one with another database never sees the challenge and so is **missing**. The same holds for a context with
another cache table. Both fail the check.

### Release identity

An observation carries `sha256(domain | nonce | realpath(base_path()))`, truncated to 32 hex characters. It is the identity of the **physical release directory** the process runs from:

- **Canonical, not nominal.** A `current` symlink and the release directory it points at give the same identity; two release directories give different identities even when every configuration
  value is the same. After a symlink swap, a process that still runs from the previous release (a long-lived PHP-FPM pool, a cron command that uses the old absolute path) keeps reporting the
  previous release and fails the check. `base_path()` is fixed at boot from the real location of `bootstrap/app.php`, so no `.git` directory is needed.
- **Challenge-bound, no path exposed.** It is not a reusable fingerprint (the nonce is part of it) and only a hash is stored or printed; no hosting path appears in any output.
- **Immutability assumption.** Each deployment is a NEW release directory that is never modified or reused afterwards. The identity distinguishes release directories, not two states of
  one directory: an in-place deployment into the same directory cannot be told apart from the old one by this check. If a release is ever deployed in place, this check proves nothing about it.

### APP_KEY proof

An observation carries `HMAC-SHA256(key = the effective application key, message = "portal.runtime-preflight.key-proof.v1" | challenge id | challenge nonce | context)`.

- A matching effective key gives a matching proof; a different key (another `.env`, a stale configuration cache) gives a different one and the context fails as `app_key`.
- The raw key is never stored or printed, nothing about it is stored except this value, and the value is useless for any other challenge or context: an observation copied from an earlier
  challenge, or one whose challenge id was rewritten, does not match. There is no reusable, unchallenged key fingerprint anywhere.
- The key itself is untouched; nothing here changes how the application encrypts.

### Operational sequence

Run on the host (staging first), as the account that owns the application, with the same PHP binary and working directory the cron entries use (on Namecheap `/opt/alt/php85/usr/bin/php`).
Do the queue and scheduler steps through the same command lines the cron entries run, or simply wait for their next ticks (at most five or ten minutes, well inside the 30-minute window):

```
# 1. After deploy: config:cache, migrate --force, database:verify and runtime:preflight all pass.
php artisan runtime:preflight --issue
#    prints the challenge id, "valid until ..." and the next steps

# 2. The web context (any machine; the body is the usual health page). It must reach PHP: a CDN, proxy or page cache that answers
#    /up itself leaves the web context "missing", which fails closed.
curl -fsS https://<production host>/up

# 3. The queue context. FIRST look at what is queued (class names only; no payload):
#      SELECT id, queue, JSON_UNQUOTE(JSON_EXTRACT(payload, '$.displayName')) AS job FROM jobs ORDER BY id;
#    then either let the queue cron entry run, or one bounded worker run:
php artisan queue:work database --stop-when-empty --max-jobs=1 --tries=1

# 4. The scheduler context (or let the scheduler cron entry run)
php artisan schedule:run

# 5. Verify, naming the challenge so that a newer one cannot be verified by mistake
php artisan runtime:preflight --verify --challenge=<id printed in step 1>
```

**In production the queue and scheduler steps go through the cron wrapper** ([Cron Execution](./cron-execution.md#relationship-to-the-a2-manual-worker-instructions)): `PORTAL_ROOT=… sh …/current/scripts/cron/portal-cron.sh queue`
(and `… schedule`) instead of the bare `queue:work` and `schedule:run` commands below. It takes the same lock as the cron entries, so a manual run cannot overlap a tick, and it drains every eligible job within its bounds,
not one. The bare commands are for development, CI and the smoke script.

**Check the queue before running a worker by hand.** `--max-jobs=1` processes the oldest AVAILABLE job, which is not necessarily the observation job: with other jobs queued ahead of it (mail
notifications, for instance) the worker would execute one of THOSE, and the observation job would stay queued, leaving the queue context missing. Do not answer that by draining the queue without
looking (`--stop-when-empty` without `--max-jobs` runs every queued business job). On staging, where the queue should hold only the observation job, the command above is safe; if other jobs are
queued, let the normal queue cron entry process them in order, which will also reach the observation job, or run the worker once per job while checking `jobs` between runs.

A successful `--verify` exits 0 and ends with `Every context answered the challenge and agrees: CLI, web, queue and scheduler share the configuration, the release and the APP_KEY.`;
a short table lists each context's status and the PHP version and SAPI it ran under (`ok`, with `cli-server`, `fpm-fcgi` or `cli` as the case may be). `--json` adds `mode`, `challenge_status`,
`challenge` and `contexts` to the A1 keys (`passed`, `violations`, `probes_ran`, `diagnostics`), which are unchanged; without `--issue`/`--verify` the output is exactly A1's.

### Reading a failure

| Message | Meaning | Do |
|---|---|---|
| `no challenge is stored` | nothing issued, or the cache was cleared | `--issue` |
| `the challenge has expired` | more than 30 minutes since `--issue` | `--issue` again |
| `the requested challenge was superseded` | a newer `--issue` ran; observations of the older one never count | verify the new id, or start over |
| `the stored challenge is malformed` | the challenge row is not a valid challenge | `--issue` again; look for who writes to the cache table |
| `<context>: no observation was recorded` | that context did not answer: it never ran, or answers from another database or cache table, or the write failed | run its trigger; if it still fails, run `runtime:preflight` in THAT context |
| `<context>: differs from this process in: ...` | it answered with other values; the names are the compared values | compare `runtime:preflight` diagnostics in both contexts; fix the `.env` or configuration cache of the odd one |
| `... release (a different physical release directory)` | that context runs from another release directory than the verifier | a stale pool or cron path: restart the pool, fix the cron command's path |
| `... app_key (a different APP_KEY)` | that context holds another effective key | fix its `.env`/configuration cache |
| `<context>: it was observed more than once with differing results` | two workers of the same context answered differently within one challenge (for example two PHP pools) | find and fix the odd worker; then `--issue` |
| `<context>: the stored observation is malformed` / `does not belong to this challenge` | a row was tampered with, truncated, or copied from another challenge | `--issue` again and investigate |
| `the challenge state could not be read: <Class> (SQLSTATE ...)` | the database could not be read | fix the database first |

### Challenge lifecycle and what is written

Keys are fixed and live in the existing database cache table (`cache`) on the default connection, under `portal:runtime-context:` (not under `cache.prefix`, so a prefix mismatch is
reported as a mismatch rather than as a missing observation). Raw rows, as the permission epoch does it. The permission epoch row is never read, created or advanced, and nothing flushes the cache.

| Write | When | Rows | Lifetime |
|---|---|---|---|
| `portal:runtime-context:challenge` | `--issue` | 1 (replaced on re-issue) | valid 30 minutes; the row's own `expiration` is `expires_at` + 1 hour |
| `portal:runtime-context:obs:<id>:<context>` | each context, once per challenge | up to 4 | same |
| `portal:runtime-context:conflict:<id>:<context>` | only when a context reports two different results | 0, normally | same |
| `jobs` | `--issue` | exactly 1 job (`RecordRuntimeObservation`, carrying only the challenge id) | deleted when a worker runs it; a job for an expired or superseded challenge records nothing |

- **Re-issuing** removes every row of the `portal:runtime-context:` namespace first, and only that namespace, so an observation of an older challenge can neither be found nor counted. The old
  challenge's still-queued job is harmless: it records nothing for a superseded challenge.
- **Expiry is logical, not physical.** Validity is enforced from the `expires_at` and `issued_at` stored INSIDE the challenge (and the observation window is checked against them). The
  `expiration` column of a row does not make the database delete it: Laravel's database cache store removes an expired row only when that very key is read THROUGH the store (and its
  random pruning covers the lock table only), and these rows are read and written raw, so **nothing prunes them automatically**. An expired challenge therefore stays physically present, and
  `--verify` keeps reporting it as expired, until the next `--issue` deletes the whole `portal:runtime-context:` namespace (or the cache is cleared). The retained namespace is small and bounded in
  normal operation: one challenge row and at most four observation rows (plus conflict markers in the abnormal case), because every issuance replaces the previous ones. There is no cleanup
  service and no migration; if the cache table were ever pruned by an operator, a verification of the pruned challenge reports it absent, which is a failure.
- **Observation is write-once.** An identical repeat (a second `GET /up`, a second job run) changes nothing. A different second result sets the conflict marker, so the outcome never depends
  on which worker answered first or last. A failed write by an observer is swallowed (a health check and a scheduled callback must never fail because of this) and the verifier reports the
  context as missing.
- **Without a challenge** the observers do almost nothing: `GET /up` and the scheduler callback do one indexed `SELECT` on the cache table and return, **in production only**. This means that **every production `GET /up` performs one database lookup even when no challenge is active**, and that during a database outage or connection delay a health request pays that connection attempt (its failure is swallowed, so the response itself is unchanged, but it can be slow). A monitor that polls `/up` frequently therefore adds one indexed lookup per poll. In every other
  environment they do not touch the database at all (the callback is filtered out before it runs; the `/up` listener returns first), so development and CI acquire no dependency. The scheduler
  callback runs at every `schedule:run` (the planned `*/5` cron makes that every five minutes), not only at minutes divisible by five, so a manual run at any minute answers a challenge.
- **What verification writes.** The challenge comparison itself (`RuntimeVerification`) only READS the challenge, observation and conflict rows, so it can be interrupted or repeated freely. The
  COMMAND `runtime:preflight --verify` also runs the A1 local verification first, and that is not read-only: its live probes create and remove one temporary `cache` row and one temporary
  `cache_locks` row (see "Actual writes" above). `--issue` runs the same local checks, then writes the challenge rows and the job.
- **Order of the two reads.** For each context the verifier reads the conflict marker BEFORE the observation. A concurrent `--issue` deletes a challenge's observations and conflict markers in one transaction;
  with the marker read first, a marker that vanished because of that issuance implies the observation vanished too, so the context is reported missing rather than passing without its conflict.
  (Reading the observation first allowed a conflicted context to pass: regression-tested by interleaving an issuance between the two reads.)
- `--issue` after a failed attempt is safe: the three writes share one transaction, so a failure leaves no challenge, no observation and no job behind, and the command exits non-zero.
- Concurrent `--issue` calls are not promised to succeed together or in any order. Both rewrite the same challenge row under REPEATABLE READ, so one of them can deadlock or time out and fail, cleanly:
  its transaction rolls back, it prints a non-success result and leaves nothing behind. If both succeed, the one whose transaction committed last is the current challenge and the other's id is reported as
  superseded, which is not necessarily the later invocation. After any failure, run `--issue` again and use the id it prints.

### Limitations: a sampled-context proof

`--verify` proves that the contexts that actually answered THIS challenge agree. It does **not** prove that no other process disagrees: a second PHP pool that was not hit, a worker started
before the deployment that is still running an older release, or a cron entry that has not fired yet are only caught if one of them happened to answer (a conflict marker, a release or a
configuration mismatch). Re-run the sequence after every deployment and every change to the environment. Startup enforcement (slice A3) is a different control: it stops a worker or scheduler from starting under an INVALID configuration; it does not detect a stale worker, a stale release or a configuration drift between contexts, which only the sampling above can reveal.
A context's PHP version, SAPI and disabled functions are shown but not judged. The check does not look at mail, the session cookie or the other A1 rules in other contexts; those are properties of the
shared configuration, which the compared values stand in for.

## Fail-closed worker and scheduler startup (slice A3)

A1 is a command an operator runs; nothing forces anyone to run it. A3 makes the production worker and scheduler commands check the profile themselves, every time they start, so a
cron entry that launches them under a configuration that has drifted (an edited `.env`, a missing configuration cache, a stale `CACHE_STORE`) does not quietly keep running.

### What is guarded, exactly

`App\Support\Runtime\RuntimeStartupGuard` listens to Laravel's `CommandStarting` event, which is dispatched **before** a command's own work begins, and applies to **exactly four commands**,
compared for equality with the command's canonical name (so `queue:wo`, typed as an abbreviation, resolves to `queue:work` and is guarded; `queue:worker` or `queue:work-all` are not):

| Command | Why |
|---|---|
| `queue:work` | the database queue worker the cron entry runs |
| `queue:listen` | the other worker command; refused for the same reason |
| `schedule:run` | the scheduler tick the cron entry runs |
| `schedule:work` | the scheduler's long-running form |

Nothing else is touched. In particular `migrate`, `database:verify`, `runtime:preflight`, `config:cache`, `config:clear`, `cache:clear`, `roles:sync`, `schedule:list`, `queue:failed`,
`queue:retry`, `queue:restart` and every other command run exactly as before, **including when the profile is violated**: they are how an operator diagnoses and repairs it. There is no wildcard
and no web-request enforcement (no 503 middleware): a web request is not affected by A3.

### When it applies, and what it checks

- **Only when this process is, or is configured as, production** (see [the environment, and `--env`](#the-environment-and---env) below): when EITHER Laravel's detected environment OR the effective cached
  `app.env` is `production`, compared case-insensitively. Development, testing and any other environment that says production in neither source, including a `staging` value, are never blocked.
  (Staging rehearsals must run with `APP_ENV=production` anyway, which is also what makes the A1 profile and the database migration guard apply.)
- **`RuntimeProfile::configuration()` and nothing else**: the same configuration-only rules `runtime:preflight` enforces (production environment, debug off, UTC, key, https `APP_URL`, **cached
  configuration**, database cache/session/queue topology, `retry_after >= 300`, SMTP-only transport with no `MAIL_URL`, Secure and HttpOnly session cookie, ...). There is no second copy of
  the rules. **No database query and no cache probe** runs at startup: the guard cannot be slowed or broken by an unreachable database, and a worker's first database access happens after
  it has been allowed to start.
- Because production requires the configuration to be **cached**, what is checked is the effective configuration the process actually runs with, which is also why an `--env` override cannot change what is checked. An uncached production configuration is itself
  a violation, so the guard refuses to start under it: the operator runs `php artisan config:cache`.
- The A2 challenge is **not** checked at startup. A2 remains the explicit deployment verification gate; A3 does not make a worker depend on a challenge.

### The environment, and `--env`

Two things can name the environment, and they can differ:

- **Laravel's detected environment** (`app()->environment()`): normally the cached `app.env`, but the `--env=<name>` option of an Artisan command **replaces** it for that one run. It is what `when()` filters, seeders and
  `runningUnitTests()` look at.
- **The effective `app.env`** from the (normally cached) configuration. `--env` does **not** change it: the cached production configuration stays loaded.

The guard applies when **either** says production (case-insensitively), and `RuntimeProfile` stays the single authority on whether the configuration is valid: it still requires the **exact** value `production`. So:

| Invocation | Result |
|---|---|
| invalid cached production profile, no override | refused |
| the same with `--env=local`, `--env=staging`, `--env=PRODUCTION`, `--env=Production` | **refused** (the cached configuration still says production) |
| cached `APP_ENV=Production` or `PRODUCTION` (any case or surrounding whitespace) | the guard applies, and the profile refuses it: `app.env is 'Production', expected 'production'` |
| detected `production` while the cached `app.env` is something else | the guard applies, and the profile refuses the value |
| a real local or development profile (cached or not) | not blocked, with or without `--env=local` |
| a VALID cached production profile with `--env=local` | **starts.** The override changes the name the process reports (and therefore which `when(production)` callbacks, such as the A2 scheduler observation, run), not the validated configuration |
| `--env=testing` | see below |

The guard's applicability check runs inside its fail-closed path: if it cannot tell whether the process is production, it refuses.

**A3 does not protect a deployment that is labelled with a different environment, such as `APP_ENV=prod`.** Such a process says production in neither source, so the guard does not apply. The mandatory A1 `runtime:preflight` deployment gate rejects any `app.env` other than exactly `production`, which is what catches the mislabel.

**Production cron entries and worker commands must not pass `--env=`.** An override is not a bypass of an invalid profile, but it changes which environment-gated code runs, and a command that reports another
environment than its configuration is a deployment error. The cron lines ([Cron Execution](./cron-execution.md), WP3-B1) rely on the cached configuration alone.

**`--env=testing` is different, and is not covered by the guard.** `Application::runningUnitTests()` is true when the detected environment is `testing`, and Laravel does not re-route Symfony's command events while it is true,
so `CommandStarting` is never dispatched and **this guard cannot fire at all** (a listener condition cannot cover an event that never happens). What stops such a run is a separate fence:
`App\Support\TestDatabaseSafety`, called from `AppServiceProvider::boot()` when the environment is `testing`, throws unless the resolved database is exactly `intechral_client_portal_testing`. With the production
database configuration loaded it throws **while the providers boot, before the command's own work**, so no job is reserved and no scheduled task runs. Verified with real processes against a cached production profile, for
`queue:work`, `queue:listen`, `schedule:run` and `schedule:work` (output: `Test database safety check failed before database access ... resolved APP_ENV="testing" and DB_DATABASE="<production database>"`, non-zero exit, no
"Refusing to start", no database connection attempted). The limits of that protection, stated plainly: it is a database-NAME fence, not the runtime profile (it checks nothing about the cache, queue, mail or session
configuration); it depends on the production database not being named `intechral_client_portal_testing`, which a production or staging database must never be; and it is only as strong as that boot-time call remaining in place.

### What a refusal does

1. The command **does not start**. Laravel dispatches the event before the command runs, and the guard throws `RuntimeProfileRefused`, which propagates out of the console application to the
   kernel: **exit status 1**, no queued job is reserved or attempted, no scheduled task runs, and no A2 observation is recorded through the refused command. This is verified with real
   `php artisan` processes (a committed job in the testing database is left unreserved with zero attempts, the same job is consumed when the guard does not apply, and the refusal names the
   rule, so it is not some unrelated CLI error), not assumed from Laravel's documentation.
2. The output lists the violated rules, one per line: configuration names, driver and table names, and categorical reasons. They are the same strings `runtime:preflight` prints, which never
   contain a key, a password, a URL with credentials or a raw exception message.
3. **One** `critical` log entry is written (`Production runtime profile violated: refusing to start a worker or scheduler command.`) with the command and the violations. The exception marks itself
   as already reported, so Laravel's exception handler does not add a second, stack-traced entry for the same refusal. If the log channel itself is broken, the refusal still happens.
4. **Failing closed:** if evaluating the profile throws for any reason, that is a refusal (`the runtime profile could not be evaluated: <ExceptionClass>`, never the exception's message), not
   a pass.
5. Nothing is repaired automatically.

A cron entry that is refused prints this output (the host mails or discards it per the cron configuration) and exits non-zero on every tick until the profile is fixed.

### Correcting an invalid profile

Read the listed rules, fix the environment file, then on the host: `php artisan config:cache`, then `php artisan runtime:preflight` (it must exit 0), and, after a deployment or an environment change,
the [cross-context sequence](#operational-sequence). `runtime:preflight` works while the profile is violated precisely so that it can name what is wrong.

### Relationship to A1 and A2

| | A1 `runtime:preflight` | A2 `--issue` / `--verify` | A3 startup guard |
|---|---|---|---|
| Runs | when an operator runs it | when an operator runs the sequence | automatically, at every start of the four commands, in production |
| Checks | the profile, the database, live probes | that the four contexts agree (release, key, configuration) | the configuration-only profile |
| Touches the database | yes (probes) | yes (challenge rows) | no |
| Proves | this process is configured correctly | the sampled contexts are the same application | the command does not run under an invalid configuration |

### Tests and how CI exercises it

`tests/Unit/Runtime/RuntimeStartupGuardTest.php`: the listener for each command (and which environment source decides, review M-1) and each representative violation, production-only behaviour, the exact-match scope, fail-closed evaluation, a broken log
channel, no database queries, no secret in the exception or the log, and **real `php artisan` processes** for each guarded command (non-zero exit, the rule named, one critical entry, nothing leaked,
no refusal outside production, other commands usable; and, with real `config:cache` files, every `--env` override against an invalid cached production profile, a differently spelled cached `APP_ENV`, the `--env=testing` fence, and a valid profile and a local profile still starting). `tests/Feature/Runtime/StartupGuardIntegrationTest.php`: the same through the whole console kernel against the testing database (a refused worker
leaves its job unreserved; a refused scheduler runs no callback; A2 still works through the guard; a refused worker and scheduler record no observation), and a real worker process against a committed
job, refused under an invalid production environment (also with `--env=local` on the command line) and consuming the job otherwise. Laravel does not re-route Symfony's command events while it runs unit tests, so the in-process tests
re-route them explicitly (`Kernel::rerouteSymfonyCommandEvents()`, the call the kernel makes for a real process); the real-process tests need no such help.

The host-parity smoke runs the four commands under an invalid production configuration cache (`MAIL_MAILER=log`), also with `--env=local` and `--env=PRODUCTION`, checks each is refused by exactly that rule, that `--env=testing` stops at the test-database fence, that the queued job is still unreserved and no
observation was recorded, and then that the valid worker and scheduler start and the challenge verifies.

## Not covered (not started)

- Startup enforcement of the A2 challenge and a web-request guard (503 middleware).
- The cron wrapper, its bounded timings and the failure marker are WP3-B1 ([Cron Execution](./cron-execution.md)). Whether `retry_after` of 300 and those timings hold on the host is still unproven until the staging rehearsal and the gated survival test.
