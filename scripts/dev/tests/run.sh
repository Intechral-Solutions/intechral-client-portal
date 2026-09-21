#!/usr/bin/env bash
# Self-tests for ./dev. Plain Bash, no framework, no real Docker: a stub `docker` on PATH
# records every invocation and returns canned output, so nothing here can touch a database.
#
#   bash scripts/dev/tests/run.sh
#
# Also run by `./dev check`.
set -uo pipefail

ROOT="$(cd -P "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/dev-cli-tests.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

PASS=0
FAIL=0
FAILED_NAMES=''

# ── Stub docker ────────────────────────────────────────────────────────────────
mkdir -p "$WORK/bin"
cat >"$WORK/bin/docker" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"$STUB_LOG"
case "$*" in
    "info"*) exit 0 ;;
    "compose version"*) echo "v5.2.0"; exit 0 ;;
    *"ps --services --status running"*) printf '%b\n' "${STUB_RUNNING:-app\ndb\nnginx}"; exit 0 ;;
    *"php -- dev"*) cat >/dev/null; printf '%b\n' "$STUB_RESOLVE_DEV"; exit "${STUB_RESOLVE_RC:-0}" ;;
    *"php -- test"*) cat >/dev/null; printf '%b\n' "$STUB_RESOLVE_TEST"; exit "${STUB_RESOLVE_RC:-0}" ;;
    *"printenv MYSQL_DATABASE"*) echo "${STUB_SERVICE_DB-portal}"; exit 0 ;;
    *mariadb-dump*) printf '%b' "${STUB_DUMP-"-- MariaDB dump\nCREATE TABLE t (id int);\n-- Dump completed on 2026-01-01\n"}"; exit "${STUB_DUMP_RC:-0}" ;;
    *"mariadb -N"*)
        n=$(cat "$STUB_LOG.counts" 2>/dev/null || echo 0); echo $((n + 1)) >"$STUB_LOG.counts"
        if [[ $n -eq 0 ]]; then printf '%b\n' "${STUB_COUNTS_BEFORE:-1\t2\t3}"; else printf '%b\n' "${STUB_COUNTS_AFTER:-1\t2\t3}"; fi
        exit 0 ;;
    *" down"*) exit "${STUB_DOWN_RC:-0}" ;;
    *"npm run test:e2e"*) exit "${STUB_PW_RC:-0}" ;;
    *"./vendor/bin/pest"*) exit "${STUB_PEST_RC:-0}" ;;
esac
exit 0
STUB
chmod +x "$WORK/bin/docker"

DEV_OK='env=local\nconnection=mysql\ndatabase=portal\nhost=db\nport=3306\napp_url=http://localhost:4242\nconfig_cached=0\npending=2026_01_01_000001_a\npending=2026_01_01_000002_b\npending_count=2'
DEV_NONE='env=local\nconnection=mysql\ndatabase=portal\nhost=db\nport=3306\napp_url=http://localhost:4242\nconfig_cached=0\npending_count=0'
TEST_OK='env=testing\nconnection=mysql\ndatabase=intechral_client_portal_testing\nhost=db\nport=3306\napp_url=http://localhost:4242\nconfig_cached=0'

# ── Harness ────────────────────────────────────────────────────────────────────
RC=0 OUT='' LOG=''

# dev_run [stdin-text] -- <args...>: runs ./dev against the stub, capturing RC, OUT and LOG.
# Exports STUB_* variables set by the caller.
dev_run() {
    local stdin_text=''
    if [[ ${1:-} != -- ]]; then
        stdin_text="$1"
        shift
    fi
    shift # --
    : >"$WORK/docker.log"
    rm -f "$WORK/docker.log.counts"
    rm -rf "$WORK/backups"
    OUT="$(printf '%s' "$stdin_text" |
        env PATH="$WORK/bin:$PATH" STUB_LOG="$WORK/docker.log" DEV_BACKUP_DIR="$WORK/backups" NO_COLOR=1 \
            "$ROOT/dev" "$@" 2>&1)"
    RC=$?
    LOG="$(cat "$WORK/docker.log")"
}

reset_stubs() {
    unset STUB_DOWN_RC STUB_RUNNING STUB_SERVICE_DB STUB_DUMP STUB_DUMP_RC STUB_COUNTS_BEFORE STUB_COUNTS_AFTER STUB_PW_RC STUB_PEST_RC STUB_RESOLVE_RC
    export STUB_RESOLVE_DEV="$DEV_OK" STUB_RESOLVE_TEST="$TEST_OK"
}

CURRENT=''
ok() { PASS=$((PASS + 1)); }
bad() {
    FAIL=$((FAIL + 1))
    FAILED_NAMES="${FAILED_NAMES}  - $CURRENT: $1"$'\n'
    printf 'FAIL  %s: %s\n' "$CURRENT" "$1"
}
begin() {
    CURRENT="$1"
    reset_stubs
}
assert_rc() { [[ $RC -eq $1 ]] && ok || bad "expected exit $1, got $RC. Output: $OUT"; }
assert_rc_nonzero() { [[ $RC -ne 0 ]] && ok || bad "expected a non-zero exit, got 0. Output: $OUT"; }
assert_out() { [[ $OUT == *"$1"* ]] && ok || bad "output lacks '$1'. Output: $OUT"; }
assert_out_lacks() { [[ $OUT != *"$1"* ]] && ok || bad "output unexpectedly contains '$1'"; }
assert_log() { [[ $LOG == *"$1"* ]] && ok || bad "docker was not called with '$1'. Log: $LOG"; }
assert_log_lacks() { [[ $LOG != *"$1"* ]] && ok || bad "docker was unexpectedly called with '$1'"; }

UIDGID="$(id -u):$(id -g)"

# ── Static checks ──────────────────────────────────────────────────────────────
begin "syntax"
for f in "$ROOT/dev" "$ROOT"/scripts/dev/*.sh "$ROOT"/scripts/dev/tests/run.sh; do
    if bash -n "$f" 2>"$WORK/syntax.err"; then ok; else bad "bash -n failed for $f: $(cat "$WORK/syntax.err")"; fi
done

begin "shellcheck (only if installed)"
if command -v shellcheck >/dev/null 2>&1; then
    if shellcheck -x -S warning "$ROOT/dev" "$ROOT"/scripts/dev/*.sh >"$WORK/sc.out" 2>&1; then ok; else bad "$(cat "$WORK/sc.out")"; fi
else
    echo "skip  shellcheck not installed"
fi

begin "no unsafe database primitives"
# Live scripts (this test file legitimately names the strings it forbids).
if grep -R -n -E 'migrate:fresh|db:wipe|down -v|--volumes|--force' "$ROOT/dev" "$ROOT"/scripts/dev/*.sh "$ROOT"/scripts/dev/*.php >"$WORK/grep.out" 2>&1; then
    bad "unsafe primitive found: $(cat "$WORK/grep.out")"
else ok; fi
if grep -q '"fresh"' "$ROOT/package.json"; then bad "root package.json still defines a fresh script"; else ok; fi

# ── help / dispatch ────────────────────────────────────────────────────────────
begin "no args shows help"
dev_run --
assert_rc 0
assert_out "Usage: ./dev <command>"
assert_out "db:migrate"
assert_out "test:php"

begin "help and --help"
dev_run -- help
assert_rc 0
assert_out "doctor"
dev_run -- --help
assert_rc 0
assert_out "Usage: ./dev"

begin "help does not need docker"
dev_run -- help
assert_log_lacks "compose"

begin "unknown command fails"
dev_run -- frobnicate
assert_rc 2
assert_out 'unknown command "frobnicate"'

begin "db:fresh / db:restore do not exist"
dev_run -- db:fresh
assert_rc 2
dev_run -- db:restore
assert_rc 2
dev_run -- 'cmd_up'
assert_rc 2

# ── contract checks (pure) ─────────────────────────────────────────────────────
begin "dev contract: rejects each deviation"
check_dev() { # <label> <env assignments>
    local v
    v="$( ( DEV_ROOT="$ROOT"; source "$ROOT/scripts/dev/lib.sh"; eval "$2"; dev_contract_violations ) )"
    [[ -n $v ]] && ok || bad "$1 was accepted"
}
base='R_ENV=local; R_CONNECTION=mysql; R_DB=portal; R_HOST=db; R_SERVICE_DB=portal'
V="$( ( DEV_ROOT="$ROOT"; source "$ROOT/scripts/dev/lib.sh"; eval "$base"; dev_contract_violations ) )"
[[ -z $V ]] && ok || bad "the real dev target was rejected: $V"
check_dev "production env" "$base; R_ENV=production"
check_dev "testing env" "$base; R_ENV=testing"
check_dev "test database" "$base; R_DB=intechral_client_portal_testing; R_SERVICE_DB=intechral_client_portal_testing"
check_dev "other database" "$base; R_DB=other; R_SERVICE_DB=other"
check_dev "remote host" "$base; R_HOST=db.example.com"
check_dev "sqlite" "$base; R_CONNECTION=sqlite"
check_dev "compose db mismatch" "$base; R_SERVICE_DB=somethingelse"
check_dev "empty resolution" "R_ENV=; R_CONNECTION=; R_DB=; R_HOST=; R_SERVICE_DB="

begin "test contract: only exactly the test DB"
tbase='R_ENV=testing; R_CONNECTION=mysql; R_DB=intechral_client_portal_testing; R_HOST=db; R_CONFIG_CACHED=0'
V="$( ( DEV_ROOT="$ROOT"; source "$ROOT/scripts/dev/lib.sh"; eval "$tbase"; test_contract_violations ) )"
[[ -z $V ]] && ok || bad "valid test target rejected: $V"
check_test() {
    local v
    v="$( ( DEV_ROOT="$ROOT"; source "$ROOT/scripts/dev/lib.sh"; eval "$2"; test_contract_violations ) )"
    [[ -n $v ]] && ok || bad "$1 was accepted"
}
check_test "dev database" "$tbase; R_DB=portal"
check_test "database with test prefix" "$tbase; R_DB=intechral_client_portal_testing_2"
check_test "database with test suffix trick" "$tbase; R_DB=xintechral_client_portal_testing"
check_test "local env" "$tbase; R_ENV=local"
check_test "remote host" "$tbase; R_HOST=elsewhere"
check_test "cached config" "$tbase; R_CONFIG_CACHED=1"
check_test "empty resolution" "R_ENV=; R_DB=; R_HOST=; R_CONFIG_CACHED="

# ── db commands ────────────────────────────────────────────────────────────────
begin "db:status shows target and runs migrate:status"
dev_run -- db:status
assert_rc 0
assert_out "Environment:   local"
assert_out "Database:      portal"
assert_log "php artisan migrate:status"
assert_log "-u $UIDGID"

begin "db:status refuses a non-dev target"
export STUB_RESOLVE_DEV='env=production\nconnection=mysql\ndatabase=portal\nhost=db\nport=3306\nconfig_cached=0'
dev_run -- db:status
assert_rc 1
assert_out "REFUSING"
assert_log_lacks "migrate:status"

begin "db:backup writes a validated, timestamped file"
dev_run -- db:backup
assert_rc 0
FILES=("$WORK"/backups/portal-????-??-??_??????.sql)
[[ -s ${FILES[0]} ]] && ok || bad "no backup file matching portal-<date>_<time>.sql in $WORK/backups"
assert_out "Backup written:"
assert_log "mariadb-dump"
assert_out_lacks "MYSQL_PASSWORD="

begin "db:backup fails on an empty or truncated dump and leaves no file"
export STUB_DUMP=''
dev_run -- db:backup
assert_rc_nonzero
assert_out "empty"
[[ -z "$(ls "$WORK/backups" 2>/dev/null)" ]] && ok || bad "a file was left behind after an empty dump"
export STUB_DUMP='-- MariaDB dump\nCREATE TABLE t (id int);\n'
dev_run -- db:backup
assert_rc_nonzero
assert_out "truncated"
[[ -z "$(ls "$WORK/backups" 2>/dev/null)" ]] && ok || bad "a file was left behind after a truncated dump"
export STUB_DUMP_RC=2 STUB_DUMP='-- partial\n'
dev_run -- db:backup
assert_rc_nonzero
assert_out "dump failed"
[[ -z "$(ls "$WORK/backups" 2>/dev/null)" ]] && ok || bad "a file was left behind after a failed dump"

begin "db:backup refuses a non-dev target"
export STUB_RESOLVE_DEV='env=local\nconnection=mysql\ndatabase=intechral_client_portal_testing\nhost=db\nport=3306\nconfig_cached=0'
dev_run -- db:backup
assert_rc 1
assert_log_lacks "mariadb-dump"

begin "db:migrate refuses wrong environment before any backup or migration"
export STUB_RESOLVE_DEV='env=production\nconnection=mysql\ndatabase=portal\nhost=db\nport=3306\nconfig_cached=0\npending=x\npending_count=1'
dev_run 'yes
' -- db:migrate
assert_rc 1
assert_out "REFUSING"
assert_log_lacks "mariadb-dump"
assert_log_lacks "artisan migrate"

begin "db:migrate refuses the testing database"
export STUB_RESOLVE_DEV='env=local\nconnection=mysql\ndatabase=intechral_client_portal_testing\nhost=db\nport=3306\nconfig_cached=0\npending=x\npending_count=1'
export STUB_SERVICE_DB=intechral_client_portal_testing
dev_run 'yes
' -- db:migrate
assert_rc 1
assert_out "REFUSING"
assert_log_lacks "mariadb-dump"
assert_log_lacks "artisan migrate"

begin "db:migrate refuses when the compose db service was made for another database"
export STUB_SERVICE_DB=other
dev_run 'yes
' -- db:migrate
assert_rc 1
assert_log_lacks "artisan migrate"

begin "db:migrate refuses when resolution fails or is empty"
export STUB_RESOLVE_DEV='' STUB_RESOLVE_RC=1
dev_run 'yes
' -- db:migrate
assert_rc 1
assert_log_lacks "artisan migrate"
export STUB_RESOLVE_DEV='' STUB_RESOLVE_RC=0
dev_run 'yes
' -- db:migrate
assert_rc 1
assert_out "REFUSING"
assert_log_lacks "artisan migrate"

begin "db:migrate with nothing pending exits 0 without backup or migration"
export STUB_RESOLVE_DEV="$DEV_NONE"
dev_run 'yes
' -- db:migrate
assert_rc 0
assert_out "No pending migrations"
assert_log_lacks "mariadb-dump"
assert_log_lacks "artisan migrate "

begin "db:migrate backs up first, then migrates only after 'yes'"
dev_run 'yes
' -- db:migrate
assert_rc 0
assert_out "Pending migrations (2)"
assert_out "2026_01_01_000001_a"
assert_out "Pre-migration backup:"
assert_log "mariadb-dump"
assert_log "APP_ENV=local DB_DATABASE=portal DB_HOST=db php artisan migrate"
assert_log_lacks "--force"
# order: dump happens before the migrate call
DUMP_LINE="$(grep -n 'mariadb-dump' <<<"$LOG" | head -1 | cut -d: -f1)"
MIG_LINE="$(grep -n 'php artisan migrate$' <<<"$LOG" | head -1 | cut -d: -f1)"
[[ -n $DUMP_LINE && -n $MIG_LINE && $DUMP_LINE -lt $MIG_LINE ]] && ok || bad "backup did not precede migrate (dump=$DUMP_LINE migrate=$MIG_LINE)"

begin "db:migrate declined or non-interactive does not migrate"
dev_run 'no
' -- db:migrate
assert_rc 1
assert_out "aborted"
assert_log "mariadb-dump"
assert_log_lacks "php artisan migrate$"
dev_run '' -- db:migrate
assert_rc 1
assert_log_lacks "php artisan migrate"

# ── tests ──────────────────────────────────────────────────────────────────────
begin "test:php runs pest as the host user and forwards args"
dev_run -- test:php --filter=ProjectAuthorizationMatrixTest tests/Feature/Projects
assert_rc 0
assert_out "Database:      intechral_client_portal_testing"
assert_log "-u $UIDGID"
assert_log "APP_ENV=testing DB_DATABASE=intechral_client_portal_testing DB_HOST=db ./vendor/bin/pest --filter=ProjectAuthorizationMatrixTest tests/Feature/Projects"

begin "test:php propagates pest's exit code"
export STUB_PEST_RC=1
dev_run -- test:php
assert_rc 1

begin "test:php refuses the dev database and never runs pest"
export STUB_RESOLVE_TEST='env=testing\nconnection=mysql\ndatabase=portal\nhost=db\nport=3306\nconfig_cached=0'
dev_run -- test:php --filter=Foo
assert_rc 1
assert_out "REFUSING"
assert_log_lacks "pest"

begin "test:php refuses a non-testing environment"
export STUB_RESOLVE_TEST='env=local\nconnection=mysql\ndatabase=intechral_client_portal_testing\nhost=db\nport=3306\nconfig_cached=0'
dev_run -- test:php
assert_rc 1
assert_log_lacks "pest"

begin "test:php fails closed when the test resolver aborts (TestDatabaseSafety)"
export STUB_RESOLVE_TEST='RuntimeException: Test database safety check failed' STUB_RESOLVE_RC=1
dev_run -- test:php
assert_rc 1
assert_log_lacks "pest"

begin "test:e2e states it uses the dev DB, prints counts, forwards args, runs isolated"
dev_run -- test:e2e --grep "time entries" tests/Browser/time-migration.spec.ts
assert_rc 0
assert_out "DEVELOPMENT database"
assert_out "Before: projects=1  tasks=2  time_entries=3"
assert_out "After:  projects=1  tasks=2  time_entries=3"
assert_out "counts unchanged"
assert_log "-u 0"
assert_log "PLAYWRIGHT_BASE_URL=http://nginx"
assert_log "--output=/tmp/dev-e2e-results --grep time entries tests/Browser/time-migration.spec.ts"

begin "test:e2e reports changed counts without deleting anything"
export STUB_COUNTS_AFTER='1\t2\t4'
dev_run -- test:e2e
assert_rc 0
assert_out "WARNING: product-data counts changed"
assert_out "Nothing was deleted"
assert_log_lacks "DELETE"

begin "test:e2e propagates a playwright failure and still prints after-counts"
export STUB_PW_RC=1
dev_run -- test:e2e
assert_rc 1
assert_out "After:"

begin "test:e2e refuses a non-dev target"
export STUB_RESOLVE_DEV='env=local\nconnection=mysql\ndatabase=other\nhost=db\nport=3306\nconfig_cached=0'
dev_run -- test:e2e
assert_rc 1
assert_out "REFUSING"
assert_log_lacks "playwright"
assert_log_lacks "test:e2e"

begin "test:e2e --list skips the database entirely"
dev_run -- test:e2e --list
assert_rc 0
assert_log_lacks "mariadb"
assert_log_lacks "php -- dev"
assert_log "npm run test:e2e -- --output=/tmp/dev-e2e-results --list"

# ── lifecycle ──────────────────────────────────────────────────────────────────
begin "shell uses the host UID:GID, never root"
dev_run -- shell
assert_rc 0
assert_log "-u $UIDGID"
assert_log "app bash"
assert_log_lacks "-u 0"

begin "shell needs a running app container"
export STUB_RUNNING='db'
dev_run -- shell
assert_rc 1
assert_out "./dev up"

begin "up and down propagate docker failures"
export STUB_DOWN_RC=5
dev_run -- down
assert_rc 5
assert_out_lacks "Containers removed"

begin "up starts detached; down never removes volumes"
dev_run -- up
assert_rc 0
assert_log " up -d"
dev_run -- down
assert_rc 0
assert_log " down"
assert_log_lacks "-v"
assert_log_lacks "--volumes"

begin "restart is registered, documented and runs down then up without volumes or builds"
dev_run -- help
assert_out "restart"
dev_run -- restart
assert_rc 0
assert_log " down"
assert_log " up -d"
assert_log_lacks "-v"
assert_log_lacks "--volumes"
assert_log_lacks "build"
DOWN_LINE="$(grep -n ' down$' <<<"$LOG" | head -1 | cut -d: -f1)"
UP_LINE="$(grep -n ' up -d$' <<<"$LOG" | head -1 | cut -d: -f1)"
[[ -n $DOWN_LINE && -n $UP_LINE && $DOWN_LINE -lt $UP_LINE ]] && ok || bad "down did not precede up (down=$DOWN_LINE up=$UP_LINE)"

begin "restart stops at a failed down and preserves its exit code"
export STUB_DOWN_RC=17
dev_run -- restart
assert_rc 17
assert_log " down"
assert_log_lacks " up -d"

begin "package.json restart calls a registered ./dev command"
RESTART_CMD="$(sed -n 's/^ *"restart": "\(.*\)",\{0,1\}$/\1/p' "$ROOT/package.json")"
[[ $RESTART_CMD == "./dev restart" ]] && ok || bad "restart script is '$RESTART_CMD', expected './dev restart'"
for word in $RESTART_CMD; do
    case "$word" in ./dev | '&&') ;; *) dev_run -- "$word"; assert_rc 0 ;; esac
done

begin "check rejects unknown options before running anything"
dev_run -- check --bogus
assert_rc 1
assert_out "unknown option"
assert_log_lacks "npm run check"

begin "missing docker is reported and fails"
# A PATH with the basic utilities ./dev needs, but no docker.
mkdir -p "$WORK/nodocker"
for tool in bash env dirname id date grep tail mv rm mkdir cat wc tr sed head; do
    path="$(command -v "$tool" 2>/dev/null || true)"
    if [[ -n $path && $path == /* ]]; then ln -sf "$path" "$WORK/nodocker/$tool"; fi
done
OUT="$(env PATH="$WORK/nodocker" DEV_BACKUP_DIR="$WORK/backups" "$ROOT/dev" db:status 2>&1)"
RC=$?
LOG=''
assert_rc 1
assert_out "docker is not installed"

# ── Result ─────────────────────────────────────────────────────────────────────
echo
if [[ $FAIL -eq 0 ]]; then
    echo "CLI self-tests: $PASS assertions passed."
    exit 0
fi
echo "CLI self-tests: $FAIL failed, $PASS passed."
printf '%s' "$FAILED_NAMES"
exit 1
