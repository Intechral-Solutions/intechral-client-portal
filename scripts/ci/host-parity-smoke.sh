#!/usr/bin/env bash
# Host-parity smoke test (Release 0.9, WP3-DB follow-up): `migrate` and `database:verify` against a MariaDB 11.4.13 that has the
# defaults the Namecheap host reported on 2026-10-19 (MyISAM default engine, latin1, non-strict sql_mode, Eastern-time SYSTEM
# zone), under APP_ENV=production so the migration guard is exercised. It is a smoke test, not a second test suite: about 30 seconds.
# R0.9 WP3-A slice A1 adds the runtime preflight (`runtime:preflight`) on the same server: positive path and targeted negative controls.
# docs/architecture/database-profile.md, docs/architecture/runtime-preflight.md, docs/testing/ci.md. Run it locally from the repository root with Docker and PHP 8.3+
# (vendor installed, src/.env present): `bash scripts/ci/host-parity-smoke.sh`.
#
# Safety, so it can run on a developer machine that has a dev stack:
#  - it creates ONE container with a name unique to this invocation, publishes it on an ephemeral loopback port, and removes only the
#    container whose ID `docker run` returned to it, together with that container's own anonymous data volume (`rm -f -v`); it never
#    removes, stops or reuses any container or volume it did not create here;
#  - every Laravel command is pinned to that container (connection, host, port, database, test-only credentials; any inherited
#    DB_URL, DB_SOCKET or cache/queue/session connection override is neutralised) and the pin is verified through the application
#    BEFORE anything is migrated, so `migrate` cannot reach another database;
#  - all SQL it runs by hand goes through `docker exec` into its own container;
#  - the configuration cache `runtime:preflight` requires is written to a temporary file of this invocation (APP_CONFIG_CACHE, a path under
#    a directory `mktemp -d` returned here), never to src/bootstrap/cache/config.php: a developer's own config cache is neither read (it
#    would override the pins above, and the pin check refuses it), overwritten, removed nor left behind. The script asserts that file is untouched.
set -euo pipefail

IMAGE="mariadb:11.4.13"
NAME="hostparity-smoke-$$-${RANDOM}"
CID=""
CFGDIR=""
WEBPID=""
LOG="$(mktemp)"

cd "$(dirname "$0")/../../src"

# Only a container this invocation created (CID is set from `docker run`'s own output) is ever removed.
cleanup() { [ -n "$WEBPID" ] && kill "$WEBPID" >/dev/null 2>&1 || true; [ -n "$CID" ] && docker rm -f -v "$CID" >/dev/null 2>&1 || true; [ -n "$CFGDIR" ] && rm -rf -- "$CFGDIR"; rm -f "$LOG"; }
trap cleanup EXIT
fail() { echo "::error::host-parity smoke: $*"; [ -s "$LOG" ] && sed 's/^/    | /' "$LOG" | tail -n 25; exit 1; }
sql() { docker exec -i "$CID" mariadb -h127.0.0.1 -uroot -proot -N "$@"; }

CID="$(docker run -d --name "$NAME" --label host-parity-smoke=1 -p 127.0.0.1::3306 -e TZ=America/New_York \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=portal -e MARIADB_USER=portal -e MARIADB_PASSWORD=portal \
  "$IMAGE" \
  --character-set-server=latin1 --collation-server=latin1_swedish_ci --default-storage-engine=MyISAM \
  --sql-mode=NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION --max-user-connections=50)"
PORT="$(docker port "$CID" 3306/tcp | head -n 1)"; PORT="${PORT##*:}"
[ -n "$PORT" ] || fail "could not determine the published port of ${NAME}"

# TCP, not the socket: the image's entrypoint runs a socket-only bootstrap server first, and only the final one listens on TCP.
for _ in $(seq 1 60); do sql -e 'select 1' >/dev/null 2>&1 && break; sleep 2; done
sql -e 'select 1' >/dev/null 2>&1 || fail "the MariaDB container did not become ready"

# The smoke is only worth anything on the hostile defaults: prove the server has them, so a changed image or flag cannot make it quietly friendly.
read -r engine charset zone version <<<"$(sql -e 'select @@default_storage_engine, @@character_set_server, @@system_time_zone, version()')"
echo "server: ${version}  default_storage_engine=${engine}  character_set_server=${charset}  system_time_zone=${zone}"
[ "$engine" = "MyISAM" ] && [ "$charset" = "latin1" ] && [[ "$zone" =~ ^E[DS]T$ ]] || fail "the server does not have the host-parity defaults"
case "$version" in 11.4.13*) ;; *) fail "expected MariaDB 11.4.13, got ${version}" ;; esac

# Pin the application to the disposable container. `export VAR=` (empty, not unset) is deliberate: a variable that already exists in the
# environment is never overwritten by src/.env, so an inherited DB_URL or DB_SOCKET (or a .env value) cannot redirect the connection.
export APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr CACHE_STORE=database SESSION_DRIVER=database QUEUE_CONNECTION=database
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT="$PORT" DB_DATABASE=portal DB_USERNAME=portal DB_PASSWORD=portal
export DB_URL= DB_SOCKET= DB_CHARSET=utf8mb4 DB_COLLATION=utf8mb4_unicode_ci
export DB_CACHE_CONNECTION= DB_CACHE_LOCK_CONNECTION= DB_QUEUE_CONNECTION= SESSION_CONNECTION=
# An isolated configuration cache path. It does not exist yet, so every command below runs on live configuration (a developer's cached
# configuration is ignored, not read); only the preflight steps create it, and it is removed with its directory on exit.
CFGDIR="$(mktemp -d)"
export APP_CONFIG_CACHE="${CFGDIR}/config.php"
DEV_CACHE="bootstrap/cache/config.php"
dev_cache_state() { if [ -e "$DEV_CACHE" ]; then stat -c '%i:%s:%Y' "$DEV_CACHE"; else echo absent; fi; }
DEV_CACHE_BEFORE="$(dev_cache_state)"
reset() { sql -e 'drop database if exists portal; create database portal'; }
artisan() { php artisan "$@" >"$LOG" 2>&1; }

# Verify the pin through the application itself, before anything is migrated: the resolved configuration points at the container, and the
# server the Laravel connection actually reaches is the hostile one (a dev database would answer InnoDB and a UTC or SYSTEM-UTC zone).
php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $c = config("database.connections.mysql");
  echo implode("|", [config("database.default"), $c["host"], $c["port"], $c["database"], (string) ($c["url"] ?? ""), (string) ($c["unix_socket"] ?? ""),
    config("cache.stores.database.connection") ?? "", config("queue.connections.database.connection") ?? "", config("session.connection") ?? "",
    Illuminate\Support\Facades\DB::scalar("select @@default_storage_engine"), Illuminate\Support\Facades\DB::scalar("select @@system_time_zone"), $app->configurationIsCached() ? "cached" : "live"]), "\n";' >"$LOG" 2>&1 || fail "the application could not connect to the disposable container"
pinned="$(tail -n 1 "$LOG")"
case "$pinned" in
  "mysql|127.0.0.1|${PORT}|portal||||||MyISAM|EDT|live" | "mysql|127.0.0.1|${PORT}|portal||||||MyISAM|EST|live") echo "pinned: the application reaches only ${NAME} (127.0.0.1:${PORT})" ;;
  *) fail "the application is not pinned to the disposable container (resolved: ${pinned})" ;;
esac

# 1. Negative control: a table outside the profile already exists, so the production guard must refuse the migration.
reset; sql portal -e 'create table legacy_probe (id int) engine = MyISAM'
if artisan migrate --force; then fail "migrate succeeded on a database holding a MyISAM table (the guard did not refuse)"; fi
grep -q 'table legacy_probe uses engine MyISAM' "$LOG" || fail "migrate failed, but not with the guard's message"
echo "ok  guard refuses a database that already holds a MyISAM table"

# 2. The positive path: migrate completes on the hostile server and the verifier passes.
reset
artisan migrate --force || fail "migrate failed on the host-parity server"
artisan database:verify --json || fail "database:verify failed after migrate"
grep -q '"passed": true' "$LOG" || fail "database:verify did not report passed"
non_innodb="$(sql -e "select count(*) from information_schema.tables where table_schema='portal' and engine <> 'InnoDB'")"
tables="$(sql -e "select count(*) from information_schema.tables where table_schema='portal'")"
[ "$non_innodb" = "0" ] && [ "$tables" -gt 0 ] || fail "expected only InnoDB tables, found ${non_innodb} other of ${tables}"
echo "ok  migrate and database:verify pass on the host-parity server (${tables} InnoDB tables)"

# 2b. Runtime preflight (R0.9 WP3-A, slice A1) on the same server: a production-shaped environment, the configuration cached at the ISOLATED path
# (APP_CONFIG_CACHE above), the live cache and lock probes, and targeted negative controls. Fake, non-deliverable hosts: nothing is sent.
export APP_URL=https://portal.smoke.invalid CACHE_PREFIX=smoke_prod_ MAIL_MAILER=smtp MAIL_HOST=smtp.smoke.invalid MAIL_PORT=587 MAIL_TIMEOUT=20
export MAIL_FROM_ADDRESS=portal@smoke.invalid HELPDESK_NOTIFY_ADDRESS=helpdesk@smoke.invalid DB_QUEUE_RETRY_AFTER=300
# Review F1 and F2: the session cookie must be Secure (stated explicitly: Laravel's default is null, which the profile refuses), and an inherited
# MAIL_URL is neutralised (empty, not unset, so src/.env cannot supply one): a non-empty transport URL is itself a violation.
export SESSION_SECURE_COOKIE=true MAIL_URL=
cache_config() { rm -f "$APP_CONFIG_CACHE"; env "$@" php artisan config:cache >"$LOG" 2>&1 || fail "config:cache failed at the isolated path"; [ -f "$APP_CONFIG_CACHE" ] || fail "config:cache did not write the isolated cache file"; }
preflight() { env "$@" php artisan runtime:preflight --json >"$LOG" 2>&1; }
expect_preflight_failure() { # label, message, VAR=value... (the environment the configuration is cached with)
  local label="$1" message="$2"; shift 2
  cache_config "$@"
  if preflight "$@"; then fail "runtime:preflight passed with ${label}"; fi
  grep -qF -- "$message" "$LOG" || fail "runtime:preflight failed with ${label}, but not with: ${message}"
  echo "ok  runtime:preflight refuses ${label}"
}

rm -f "$APP_CONFIG_CACHE"
if preflight; then fail "runtime:preflight passed without cached configuration"; fi
grep -qF 'configuration is not cached' "$LOG" || fail "runtime:preflight failed without cached configuration, but not with the cache message"
echo "ok  runtime:preflight refuses uncached configuration"

cache_config
preflight || fail "runtime:preflight failed on the production profile"
grep -q '"passed": true' "$LOG" && grep -q '"probes_ran": true' "$LOG" || fail "runtime:preflight did not report passed with its probes run"
probe_rows="$(sql -e "select (select count(*) from portal.cache where \`key\` like '%runtime-preflight%') + (select count(*) from portal.cache_locks where \`key\` like '%runtime-preflight%')")"
[ "$probe_rows" = "0" ] || fail "runtime:preflight left ${probe_rows} probe row(s) behind"
echo "ok  runtime:preflight passes on the production profile with cached configuration and leaves no probe row"

expect_preflight_failure "a log mail transport" "mail.default is 'log'" MAIL_MAILER=log
# EPIC-017 O3 / RE-H13: the operator new-ticket alert destination is part of the production profile (empty, not unset, so src/.env cannot supply one).
expect_preflight_failure "no operator alert destination (HELPDESK_NOTIFY_ADDRESS)" "helpdesk.notify_address is not set" HELPDESK_NOTIFY_ADDRESS=
expect_preflight_failure "a malformed operator alert destination" "helpdesk.notify_address is not a single valid email address" HELPDESK_NOTIFY_ADDRESS=not-an-address
# F1: a MAIL_URL replaces the smtp mailer's transport, host and credentials (log://localhost selects the log driver). Refused, and never printed.
expect_preflight_failure "a MAIL_URL selecting the log driver" "mail.mailers.smtp.url is set" MAIL_URL=log://localhost
! grep -qF -e 'log://' -e 'localhost' "$LOG" || fail "runtime:preflight printed the mail URL"
expect_preflight_failure "a MAIL_URL carrying credentials" "mail.mailers.smtp.url is set" MAIL_URL=smtp://smokeuser:smokesecret@mailpit:1025
! grep -qF -e 'smokesecret' -e 'smokeuser' -e 'mailpit' "$LOG" || fail "runtime:preflight printed the mail URL credentials"
# F2: Secure is required whether it is false, unset (null) or combined with SameSite=None; SameSite=None is fine when Secure is true.
expect_preflight_failure "an insecure session cookie (SESSION_SECURE_COOKIE=false)" "session.secure must be true" SESSION_SECURE_COOKIE=false
expect_preflight_failure "an unset session cookie Secure flag" "session.secure must be true" -u SESSION_SECURE_COOKIE
expect_preflight_failure "an insecure session cookie with SameSite=None" "session.secure must be true" SESSION_SECURE_COOKIE=false SESSION_SAME_SITE=none
expect_preflight_failure "a session cookie readable by scripts" "session.http_only must be true" SESSION_HTTP_ONLY=false
cache_config SESSION_SAME_SITE=none
preflight SESSION_SAME_SITE=none || fail "runtime:preflight refused SameSite=None on a Secure cookie"
echo "ok  runtime:preflight accepts SameSite=None when the session cookie is Secure"
expect_preflight_failure "a file cache store" "cache.default is 'file'" CACHE_STORE=file
expect_preflight_failure "a queue retry_after below 300" "retry_after must be an integer of at least 300" DB_QUEUE_RETRY_AFTER=299
cache_config
sql portal -e 'rename table cache_locks to cache_locks_off'
if preflight; then fail "runtime:preflight passed with the cache locks table missing"; fi
grep -qF 'the cache locks table is missing' "$LOG" || fail "runtime:preflight failed with the lock table missing, but not with its message"
sql portal -e 'rename table cache_locks_off to cache_locks'
echo "ok  runtime:preflight refuses a missing required table"

# 2c. Cross-context runtime verification (R0.9 WP3-A, slice A2): REAL separate processes against the committed disposable database. A challenge is issued
# by the CLI; a PHP web server (SAPI cli-server) answers GET /up; a real bounded database queue worker and a scheduler run answer too (the scheduler through a
# symlink to the release, which must be the SAME release); then the CLI verifies. Negative controls: a scheduler running another configuration cache
# (a different CACHE_PREFIX), another physical release directory with identical configuration, and a superseded challenge. Every process is bounded by `timeout`,
# nothing is a daemon, and the only server is killed by the cleanup trap. The configuration caches are the isolated ones under $CFGDIR.
cache_config
challenge=""
rx_issue() { # prints the new challenge id
  php artisan runtime:preflight --issue --json >"$LOG" 2>&1 || fail "runtime:preflight --issue failed"
  grep -q '"mode": "issue"' "$LOG" || fail "runtime:preflight --issue did not report issue mode"
  challenge="$(sed -n 's/.*"id": "\([0-9a-f]\{32\}\)".*/\1/p' "$LOG" | head -n 1)"
  [ -n "$challenge" ] || fail "runtime:preflight --issue did not print a challenge id"
}
rx_verify() { env "$@" php artisan runtime:preflight --verify --challenge="$challenge" --json >"$LOG" 2>&1; }
rx_web() { # the real web context: PHP's own web server (SAPI cli-server, as `artisan serve` runs it), one GET /up, then stopped
  local port="$1" body
  ( cd public && exec php -S "127.0.0.1:${port}" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php >/dev/null 2>&1 ) &
  WEBPID=$!
  for _ in $(seq 1 30); do curl -fsS --max-time 3 -o /dev/null "http://127.0.0.1:${port}/" 2>/dev/null && break; sleep 1; done
  body="$(curl -sS --max-time 10 -w '\n%{http_code}' "http://127.0.0.1:${port}/up" 2>/dev/null)" || true
  kill "$WEBPID" >/dev/null 2>&1 || true; wait "$WEBPID" 2>/dev/null || true; WEBPID=""
  # The answer must be the application's own health page (a router mistake can answer 200 with a PHP warning, which proves nothing).
  [ "$(printf '%s' "$body" | tail -n 1)" = "200" ] && printf '%s' "$body" | grep -q 'Application up' && ! printf '%s' "$body" | grep -qi 'warning' || fail "GET /up did not return the application's health page from the PHP web server"
}
rx_worker() { timeout 90 env "$@" php artisan queue:work database --stop-when-empty --max-jobs=1 --tries=1 >"$LOG" 2>&1 || fail "the bounded queue worker failed"; }
rx_scheduler() { timeout 90 env "$@" php "${SCHED_ARTISAN:-artisan}" schedule:run >"$LOG" 2>&1 || fail "schedule:run failed"; }
WEBPORT="$(( 20000 + RANDOM % 20000 ))"

# The same release reached through a symlink (the deployment model's `current`), and a second PHYSICAL release that differs only in its location.
ln -s "$PWD" "${CFGDIR}/current"
RELEASE_B="${CFGDIR}/release-b"
mkdir -p "${RELEASE_B}/bootstrap/cache"
cp artisan composer.json "${RELEASE_B}/"; cp bootstrap/app.php bootstrap/providers.php "${RELEASE_B}/bootstrap/"
for entry in app config database public resources routes storage vendor; do ln -s "${PWD}/${entry}" "${RELEASE_B}/${entry}"; done
OTHER_CACHE="${CFGDIR}/config-other.php"

# The pass: CLI + web + queue + scheduler (through the symlink), then verify.
rx_issue
rx_web "$WEBPORT"
rx_worker
SCHED_ARTISAN="${CFGDIR}/current/artisan" rx_scheduler
rx_verify || fail "runtime:preflight --verify did not pass with every context answering"
grep -q '"passed": true' "$LOG" || fail "runtime:preflight --verify did not report passed"
for ctx in cli web queue scheduler; do
  grep -A1 "\"${ctx}\": {" "$LOG" | grep -q '"status": "ok"' || fail "context ${ctx} was not ok in the verification"
done
grep -q '"sapi": "cli-server"' "$LOG" && grep -q '"sapi": "cli"' "$LOG" || fail "the web observation was not made by the PHP web server (expected sapi cli-server beside cli)"
jobs_left="$(sql -e "select count(*) from portal.jobs")"
[ "$jobs_left" = "0" ] || fail "the observation job was not consumed by the worker (${jobs_left} left)"
echo "ok  runtime:preflight --verify passes with the CLI, a web server (cli-server), a real queue worker and the scheduler via a symlink to the release"

# Negative: a scheduler running another configuration cache (CACHE_PREFIX) must fail, by name, with the other contexts still ok.
env "APP_CONFIG_CACHE=${OTHER_CACHE}" CACHE_PREFIX=smoke_other_ php artisan config:cache >"$LOG" 2>&1 || fail "config:cache failed for the second configuration"
rx_issue
rx_web "$WEBPORT"
rx_worker
rx_scheduler "APP_CONFIG_CACHE=${OTHER_CACHE}"
if rx_verify; then fail "runtime:preflight --verify passed although the scheduler ran another CACHE_PREFIX"; fi
grep -qF 'scheduler: differs from this process in: cache.prefix' "$LOG" || fail "the verification failed, but not with the scheduler cache.prefix difference"
! grep -qF 'smoke_other_' "$LOG" || fail "the verification printed a configuration value"
echo "ok  runtime:preflight --verify refuses a scheduler running another CACHE_PREFIX"

# Negative: the SAME configuration from another physical release directory must fail on the release identity alone.
rx_issue
rx_web "$WEBPORT"
rx_worker
(cd "$RELEASE_B" && timeout 90 php artisan schedule:run >"$LOG" 2>&1) || fail "schedule:run failed from the second release"
if rx_verify; then fail "runtime:preflight --verify passed although the scheduler ran from another release directory"; fi
grep -qF 'scheduler: differs from this process in: release (a different physical release directory)' "$LOG" || fail "the verification failed, but not with the release difference"
! grep -qF "$CFGDIR" "$LOG" || fail "the verification printed a hosting path"
echo "ok  runtime:preflight --verify refuses a scheduler running from another physical release with identical configuration"

# Negative: a missing context, then a superseded challenge.
rx_issue
rx_web "$WEBPORT"
rx_worker
if rx_verify; then fail "runtime:preflight --verify passed with no scheduler observation"; fi
grep -qF 'scheduler: no observation was recorded' "$LOG" || fail "the verification failed, but not with the missing scheduler"
old_challenge="$challenge"
rx_issue
challenge="$old_challenge"
if rx_verify; then fail "runtime:preflight --verify passed for a superseded challenge"; fi
grep -qF 'superseded' "$LOG" || fail "the verification failed, but not as superseded"
echo "ok  runtime:preflight --verify refuses a missing scheduler observation and a superseded challenge"
rx_worker   # consume the last queued observation job

# The A1 probe residue check still holds and the cross-context rows are bounded (every row expires an hour after its challenge).
unbounded="$(sql -e "select count(*) from portal.cache where \`key\` like 'portal:runtime-context:%' and expiration > unix_timestamp() + 1800 + 3600 + 60")"
[ "$unbounded" = "0" ] || fail "${unbounded} cross-context row(s) outlive their challenge window plus one hour"
echo "ok  cross-context rows are bounded; the observation jobs were consumed"

# 2d. Fail-closed worker and scheduler startup (R0.9 WP3-A, slice A3), with real processes. The production profile is enforced at the start of exactly
# queue:work, queue:listen, schedule:run and schedule:work (config only; nothing queries the database). Here the SAME commands run once under an INVALID production
# profile (a configuration cache built with MAIL_MAILER=log) and must be refused by that rule and not by anything else, with the queued job left unreserved and
# the scheduler's callback not run (no observation is recorded); then under the valid profile they start and the challenge verifies. Bounded by `timeout`.
INVALID_CACHE="${CFGDIR}/config-invalid.php"
env "APP_CONFIG_CACHE=${INVALID_CACHE}" MAIL_MAILER=log php artisan config:cache >"$LOG" 2>&1 || fail "config:cache failed for the invalid profile"
rx_refused() { # the command name as the refusal prints it, then the artisan arguments
  local name="$1"; shift
  if timeout 60 env "APP_CONFIG_CACHE=${INVALID_CACHE}" php artisan "$@" >"$LOG" 2>&1; then fail "${name} started under an invalid production profile"; fi
  grep -qF "Refusing to start \`${name}\`" "$LOG" && grep -qF "mail.default is 'log'" "$LOG" || fail "${name} failed, but not with the startup refusal for the mail.default rule"
}

rx_issue
rx_web "$WEBPORT"
rx_refused queue:work queue:work database --stop-when-empty --max-jobs=1 --tries=1
rx_refused schedule:run schedule:run
rx_refused queue:listen queue:listen database
rx_refused schedule:work schedule:work
# Review M-1: `--env=` replaces the DETECTED environment but not the CACHED configuration, so it must not let an invalid production profile start (nor may a
# differently cased name). Each is refused by the same rule, with the same untouched job and schedule asserted below.
rx_refused queue:work queue:work database --stop-when-empty --max-jobs=1 --tries=1 --env=local
rx_refused queue:work queue:work database --stop-when-empty --max-jobs=1 --tries=1 --env=PRODUCTION
rx_refused schedule:run schedule:run --env=local
# `--env=testing` is different: Laravel then believes it runs unit tests and never dispatches CommandStarting, so the guard cannot fire. The test-database fence
# (App\Support\TestDatabaseSafety, in AppServiceProvider::boot) does, before any command work, because this database is not the testing database. VALID cache here.
for testing_cmd in "queue:work database --once --tries=1" "schedule:run"; do
  if timeout 60 php artisan ${testing_cmd} --env=testing >"$LOG" 2>&1; then fail "'${testing_cmd} --env=testing' started against the production-profile database"; fi
  grep -qF 'Test database safety check failed before database access' "$LOG" || fail "'${testing_cmd} --env=testing' failed, but not at the test-database fence"
  ! grep -qF 'Refusing to start' "$LOG" || fail "'${testing_cmd} --env=testing' was refused by the guard (the fence is the control under test)"
done
untouched="$(sql -e "select count(*) from portal.jobs where reserved_at is null and attempts = 0")"
[ "$untouched" = "1" ] || fail "the refused worker touched the queued job (${untouched} unreserved job(s), expected 1)"
observed="$(sql -e "select count(*) from portal.cache where \`key\` in ('portal:runtime-context:obs:${challenge}:queue', 'portal:runtime-context:obs:${challenge}:scheduler')")"
[ "$observed" = "0" ] || fail "a refused command recorded an observation"
echo "ok  queue:work, queue:listen, schedule:run and schedule:work (also with --env=local and --env=PRODUCTION) are refused under an invalid production profile by the mail.default rule, and --env=testing stops at the test-database fence; the job and the schedule are untouched"

rx_worker
rx_scheduler
rx_verify || fail "the valid worker and scheduler did not answer the challenge after the refusals"
grep -q '"passed": true' "$LOG" || fail "the verification after the refusals did not pass"
echo "ok  the same worker and scheduler start under the valid profile and the challenge verifies"

# 2e. Cron wrapper (R0.9 WP3-B1): the SAME command lines the cron entries run, through scripts/cron/portal-cron.sh, against the disposable database. No timing is tested here
# (tests/Unit/Cron does that with shortened limits); this proves the pinned worker and scheduler arguments are accepted by the installed Laravel under the production profile,
# that a successful tick is silent to cron, and that a refused start (A3) is a visible, categorised failure with the queued job untouched. Each case has its own PORTAL_ROOT so
# alert state never carries over. The wrapper's physical release is this checkout (src); nothing outside $CFGDIR is written.
cron() { local root="$1"; shift; mkdir -p "${root}/shared"; env PORTAL_ROOT="$root" PORTAL_PHP="$(command -v php)" "$@" sh scripts/cron/portal-cron.sh "${CRON_TASK}" >"$LOG" 2>&1; }
cache_config
rx_issue
rx_web "$WEBPORT"
CRON_TASK=queue cron "${CFGDIR}/portal-valid" || fail "the wrapper's queue invocation failed under the valid production profile"
[ ! -s "$LOG" ] || fail "a successful wrapper invocation printed to cron"
CRON_TASK=schedule cron "${CFGDIR}/portal-valid" || fail "the wrapper's scheduler invocation failed under the valid production profile"
[ ! -s "$LOG" ] || fail "a successful wrapper invocation printed to cron"
rx_verify || fail "the challenge did not verify after the wrapper's worker and scheduler answered it"
grep -q '"passed": true' "$LOG" || fail "the verification after the wrapper did not pass"
[ "$(sql -e "select count(*) from portal.jobs")" = "0" ] || fail "the wrapper's worker did not consume the observation job"
grep -q ' END status=ok category=ok exit=0 ' "${CFGDIR}/portal-valid/shared/logs/cron-queue.log" && grep -q ' END status=ok category=ok exit=0 ' "${CFGDIR}/portal-valid/shared/logs/cron-schedule.log" || fail "the wrapper did not record END status=ok for both tasks"
[ "$(stat -c %a "${CFGDIR}/portal-valid/shared/logs/cron-queue.log")" = "600" ] || fail "the wrapper's log is not restricted to 0600"
echo "ok  the cron wrapper runs the worker and the scheduler under the production profile: silent on success, restricted log, and the challenge verifies"

rx_issue
for CRON_TASK in queue schedule; do
  if cron "${CFGDIR}/portal-refused" "APP_CONFIG_CACHE=${INVALID_CACHE}"; then fail "the wrapper's ${CRON_TASK} invocation started under an invalid production profile"; fi
  grep -qF "FAILED task=${CRON_TASK} category=profile-refused status=1" "$LOG" || fail "the wrapper's ${CRON_TASK} refusal was not reported as profile-refused"
  ! grep -qF "mail.default" "$LOG" || fail "the wrapper's notification carried a rule's detail"
  grep -qF "mail.default is 'log'" "${CFGDIR}/portal-refused/shared/logs/cron-${CRON_TASK}.log" || fail "the refusal's rule was not kept in the wrapper's local log"
done
[ "$(sql -e "select count(*) from portal.jobs where reserved_at is null and attempts = 0")" = "1" ] || fail "a refused wrapper invocation touched the queued job"
echo "ok  the cron wrapper reports an A3 startup refusal for the worker and the scheduler as profile-refused, by category only, with the queued job untouched"
CRON_TASK=queue cron "${CFGDIR}/portal-valid" || fail "the wrapper's worker did not start again under the valid profile"

# The configuration cache this smoke needed was the isolated one; a developer's own was never touched.
rm -f "$APP_CONFIG_CACHE"
[ "$(dev_cache_state)" = "$DEV_CACHE_BEFORE" ] || fail "${DEV_CACHE} changed during the smoke (it must never be touched)"
echo "ok  ${DEV_CACHE} untouched (${DEV_CACHE_BEFORE})"

# 3. Negative control: the verifier must report a table outside the profile and exit non-zero.
sql portal -e 'create table stray_probe (note varchar(20)) engine = MyISAM default charset = latin1'
if artisan database:verify; then fail "database:verify passed with a MyISAM latin1 table present"; fi
grep -q 'table stray_probe uses engine MyISAM' "$LOG" || fail "database:verify failed, but not with the engine violation"
echo "ok  database:verify reports a MyISAM latin1 table and exits non-zero"

# 4. A database that becomes unavailable (R0.9 WP3-B1). The disposable container is stopped (the cleanup trap removes it), the configuration cache is the valid production
# profile, and the connection is refused for real. Under the production profile (database cache store) a worker that starts with the database already down fails at its first
# cache read (the queue-restart check), loudly and uncaught, so even Laravel's own status is non-zero; the wrapper must report that as category=database through the
# application's marker (the exception is reported) and not as an anonymous PHP failure, without carrying any connection detail. The case Laravel itself swallows (a pop failure
# while the worker runs, exit 0) needs a cache that does not depend on the database: tests/Unit/Cron/PortalCronApplicationTest.php runs that one with the real worker.
cache_config
docker stop "$CID" >/dev/null || fail "could not stop the disposable database container"
timeout 90 php artisan queue:work database --stop-when-empty --sleep=1 --tries=1 --no-ansi >"$LOG" 2>&1 && fail "Laravel's own worker started and exited 0 against an unavailable database under the production profile"
! grep -q '@@PORTAL-CRON-FAILURE@@' "$LOG" || fail "the application emitted its failure marker outside the wrapper"
CRON_TASK=queue
if cron "${CFGDIR}/portal-outage"; then fail "the wrapper reported success while the database was unavailable"; fi
grep -qF 'FAILED task=queue category=database status=1' "$LOG" && grep -qE 'class=Illuminate.Database.QueryException sqlstate=HY000|class=PDOException sqlstate=HY000' "$LOG" || fail "the wrapper did not report the database failure by category, class and SQLSTATE"
! grep -qF -e '127.0.0.1' -e 'Connection refused' -e 'SQLSTATE' -e 'smoke_prod_' "$LOG" || fail "the wrapper's notification carried a database detail"
grep -q ' END status=fail category=database php_status=1 exit=1 ' "${CFGDIR}/portal-outage/shared/logs/cron-queue.log" || fail "the wrapper did not record the database failure in its log"
echo "ok  with the database unavailable, the cron wrapper reports category=database (not an anonymous PHP failure) without any connection detail"
