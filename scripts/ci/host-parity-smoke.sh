#!/usr/bin/env bash
# Host-parity smoke test (Release 0.9, WP3-DB follow-up): `migrate` and `database:verify` against a MariaDB 11.4.13 that has the
# defaults the Namecheap host reported on 2026-10-19 (MyISAM default engine, latin1, non-strict sql_mode, Eastern-time SYSTEM
# zone), under APP_ENV=production so the migration guard is exercised. It is a smoke test, not a second test suite: about 30 seconds.
# docs/architecture/database-profile.md, docs/testing/ci.md. Run it locally from the repository root with Docker and PHP 8.3+
# (vendor installed, src/.env present): `bash scripts/ci/host-parity-smoke.sh`.
#
# Safety, so it can run on a developer machine that has a dev stack:
#  - it creates ONE container with a name unique to this invocation, publishes it on an ephemeral loopback port, and removes only the
#    container whose ID `docker run` returned to it, together with that container's own anonymous data volume (`rm -f -v`); it never
#    removes, stops or reuses any container or volume it did not create here;
#  - every Laravel command is pinned to that container (connection, host, port, database, test-only credentials; any inherited
#    DB_URL, DB_SOCKET or cache/queue/session connection override is neutralised) and the pin is verified through the application
#    BEFORE anything is migrated, so `migrate` cannot reach another database;
#  - all SQL it runs by hand goes through `docker exec` into its own container.
set -euo pipefail

IMAGE="mariadb:11.4.13"
NAME="hostparity-smoke-$$-${RANDOM}"
CID=""
LOG="$(mktemp)"

cd "$(dirname "$0")/../../src"

# Only a container this invocation created (CID is set from `docker run`'s own output) is ever removed.
cleanup() { [ -n "$CID" ] && docker rm -f -v "$CID" >/dev/null 2>&1 || true; rm -f "$LOG"; }
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
reset() { sql -e 'drop database if exists portal; create database portal'; }
artisan() { php artisan "$@" >"$LOG" 2>&1; }

# Verify the pin through the application itself, before anything is migrated: the resolved configuration points at the container, and the
# server the Laravel connection actually reaches is the hostile one (a dev database would answer InnoDB and a UTC or SYSTEM-UTC zone).
php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $c = config("database.connections.mysql");
  echo implode("|", [config("database.default"), $c["host"], $c["port"], $c["database"], (string) ($c["url"] ?? ""), (string) ($c["unix_socket"] ?? ""),
    config("cache.stores.database.connection") ?? "", config("queue.connections.database.connection") ?? "", config("session.connection") ?? "",
    Illuminate\Support\Facades\DB::scalar("select @@default_storage_engine"), Illuminate\Support\Facades\DB::scalar("select @@system_time_zone")]), "\n";' >"$LOG" 2>&1 || fail "the application could not connect to the disposable container"
pinned="$(tail -n 1 "$LOG")"
case "$pinned" in
  "mysql|127.0.0.1|${PORT}|portal||||||MyISAM|EDT" | "mysql|127.0.0.1|${PORT}|portal||||||MyISAM|EST") echo "pinned: the application reaches only ${NAME} (127.0.0.1:${PORT})" ;;
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

# 3. Negative control: the verifier must report a table outside the profile and exit non-zero.
sql portal -e 'create table stray_probe (note varchar(20)) engine = MyISAM default charset = latin1'
if artisan database:verify; then fail "database:verify passed with a MyISAM latin1 table present"; fi
grep -q 'table stray_probe uses engine MyISAM' "$LOG" || fail "database:verify failed, but not with the engine violation"
echo "ok  database:verify reports a MyISAM latin1 table and exits non-zero"
