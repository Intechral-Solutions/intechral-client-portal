# Runtime Preflight: the Production Shape of Release 0.9 (R0.9 WP3-A, slice A1)

**Authority:** the Release 0.9 shared-hosting runtime gate (database cache, sessions and queue; no Redis; no persistent worker; two five-minute cron
tasks) and the WP3-A design gate rulings of 2026-10-19. **Companions:** [Database Profile](./database-profile.md) (what `database:verify` checks),
[Role Governance](./role-governance.md#permission-cache-security-on-the-database-store-r09-wp2) (why the cache topology matters),
[Notification Delivery](./notification-delivery.md) (why `retry_after` and the mail transport matter).

`php artisan runtime:preflight [--json]` states the production shape the application is built for and refuses anything else. It is **slice A1**: one
process, one context. It does **not** yet prove that the web, queue and scheduler contexts agree with each other (slice A2), and it does not guard
the worker and scheduler commands at startup (slice A3). Neither exists yet; nothing here claims them.

Run it **after `migrate`**, with `APP_ENV=production` and the configuration cached. It writes two disposable rows (below) and nothing else, and exits
non-zero on any violation. A check that cannot run is a violation. Development and CI are expected to fail it: it is the production profile, and
`.env.example` is the redis-defaulted development configuration.

## What it checks

**Configuration** (`App\Support\Runtime\RuntimeProfile`; configuration only, no I/O):

| Area | Requirement |
|---|---|
| Application | `app.env` is `production`; `app.debug` is `false`; `app.timezone` is `UTC`; `app.key` is set and valid for the cipher; `app.url` is an `https` URL that is not the local machine (queued mail builds its links from it) |
| Configuration cache | `php artisan config:cache` has been run in this context (so every context reads the same resolved configuration, and `env()` is no longer consulted) |
| Database | `database.default` is `mysql` or `mariadb`; no `read`/`write` split on it (the permission-cache epoch and the queue read through the primary only) |
| Cache | `cache.default` is `database`; `permission.cache.store` is `default`; no `DB_CACHE_CONNECTION` or `DB_CACHE_LOCK_CONNECTION`; table `cache` and lock table `cache_locks`; `cache.prefix` is non-empty |
| Queue | `queue.default` is `database`; no `DB_QUEUE_CONNECTION`; table `jobs`; `retry_after` is an integer of at least **300** (provisional: to be confirmed by the WP3-B worker timing validation); failed jobs in `failed_jobs` through `database-uuids` on the default connection |
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

After [the database-profile order](./database-profile.md#5-deployment-order) (guarded `migrate`, then the mandatory post-migration `database:verify`):

1. `php artisan config:cache`
2. `php artisan runtime:preflight`: must exit 0. A non-zero exit aborts the release.

Re-run both after any change to the environment file, in **every context that has its own configuration** (web, the queue-drain cron command, the scheduler cron command); slice A2 will
check the contexts against each other, and until then comparing the diagnostics blocks by eye is the operator's job.

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

## Not covered (later slices, not started)

- **A2:** web, queue and scheduler contexts using the same release identity, configuration, `APP_KEY` (by an HMAC proof, never the key) and challenge.
- **A3:** startup enforcement for the production queue and scheduler worker commands.
- Whether `retry_after` of 300 is enough on the host: WP3-B timing validation.
