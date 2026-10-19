#!/usr/bin/env bash
# Host-parity smoke test (Release 0.9, WP3-DB follow-up): `migrate` and `database:verify` against a MariaDB 11.4.13 that has the
# defaults the Namecheap host reported on 2026-10-19 (MyISAM default engine, latin1, non-strict sql_mode, Eastern-time SYSTEM
# zone), under APP_ENV=production so the migration guard is exercised. It is a smoke test, not a second test suite: about 30 seconds.
# docs/architecture/database-profile.md, docs/testing/ci.md. Run it locally from the repository root with Docker and PHP 8.3+
# (vendor installed, src/.env present): `bash scripts/ci/host-parity-smoke.sh`. It starts and removes one container.
set -euo pipefail

NAME="${HOSTPARITY_NAME:-hostparity-db}"
PORT="${HOSTPARITY_PORT:-3307}"
IMAGE="mariadb:11.4.13"
LOG="$(mktemp)"

cd "$(dirname "$0")/../../src"

cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; rm -f "$LOG"; }
trap cleanup EXIT
fail() { echo "::error::host-parity smoke: $*"; [ -s "$LOG" ] && sed 's/^/    | /' "$LOG" | tail -n 25; exit 1; }
sql() { docker exec -i "$NAME" mariadb -h127.0.0.1 -uroot -proot -N "$@"; }

docker rm -f "$NAME" >/dev/null 2>&1 || true
docker run -d --name "$NAME" -p "127.0.0.1:${PORT}:3306" -e TZ=America/New_York \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=portal -e MARIADB_USER=portal -e MARIADB_PASSWORD=portal \
  "$IMAGE" \
  --character-set-server=latin1 --collation-server=latin1_swedish_ci --default-storage-engine=MyISAM \
  --sql-mode=NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION --max-user-connections=50 >/dev/null

# TCP, not the socket: the image's entrypoint runs a socket-only bootstrap server first, and only the final one listens on TCP.
for _ in $(seq 1 60); do sql -e 'select 1' >/dev/null 2>&1 && break; sleep 2; done
sql -e 'select 1' >/dev/null 2>&1 || fail "the MariaDB container did not become ready"

# The smoke is only worth anything on the hostile defaults: prove the server has them, so a changed image or flag cannot make it quietly friendly.
read -r engine charset zone version <<<"$(sql -e 'select @@default_storage_engine, @@character_set_server, @@system_time_zone, version()')"
echo "server: ${version}  default_storage_engine=${engine}  character_set_server=${charset}  system_time_zone=${zone}"
[ "$engine" = "MyISAM" ] && [ "$charset" = "latin1" ] && [[ "$zone" =~ ^E[DS]T$ ]] || fail "the server does not have the host-parity defaults"
case "$version" in 11.4.13*) ;; *) fail "expected MariaDB 11.4.13, got ${version}" ;; esac

export APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr CACHE_STORE=database SESSION_DRIVER=database QUEUE_CONNECTION=database
export DB_HOST=127.0.0.1 DB_PORT="$PORT" DB_DATABASE=portal DB_USERNAME=portal DB_PASSWORD=portal
reset() { sql -e 'drop database if exists portal; create database portal'; }
artisan() { php artisan "$@" >"$LOG" 2>&1; }

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
