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
        if [[ $n -eq 0 ]]; then printf '%b\n' "${STUB_COUNTS_BEFORE:-1\t2\t3\t4}"; else printf '%b\n' "${STUB_COUNTS_AFTER:-1\t2\t3\t4}"; fi
        exit 0 ;;
    *"playwright-core/package.json"*) echo "${STUB_PW_VERSION-1.63.0}"; exit "${STUB_PW_VERSION_RC:-0}" ;;
    *"printenv PLAYWRIGHT_BROWSERS_PATH"*) echo "${STUB_PW_PATH-/opt/ms-playwright}"; exit 0 ;;
    *"chromium.launch"*)
        if [[ ${STUB_BROWSER_RC:-0} -ne 0 ]]; then echo "${STUB_BROWSER_ERR:-browserType.launch: chromium executable is missing}"; exit 1; fi
        echo "${STUB_BROWSER_OUT:-153.0.8010.12}"; exit 0 ;;
    *"ps --all --format"*) printf '%b\n' "${STUB_PS:-app running \nnginx running \ndb running healthy\nredis running \nmailpit running healthy\nqueue running }"; exit 0 ;;
    *" down"*) exit "${STUB_DOWN_RC:-0}" ;;
    *"e2e:cleanup-tickets"*)
        n=$(cat "$STUB_LOG.cleanups" 2>/dev/null || echo 0); echo $((n + 1)) >"$STUB_LOG.cleanups"
        if [[ $n -eq 0 ]]; then exit "${STUB_SWEEP_RC:-0}"; else exit "${STUB_CLEANUP_RC:-0}"; fi ;;
    *"e2e_suite_probe"*) exit "${STUB_SUITE_RUNNING_RC:-1}" ;;
    *"e2e_ticket_dirs"*)
        n=$(cat "$STUB_LOG.dirs" 2>/dev/null || echo 0); echo $((n + 1)) >"$STUB_LOG.dirs"
        if [[ $n -eq 0 ]]; then
            [[ -z ${STUB_DIRS_BEFORE_RC:-} ]] || exit "$STUB_DIRS_BEFORE_RC"
            echo "${STUB_DIRS_BEFORE:-3}"
        else
            [[ -z ${STUB_DIRS_AFTER_RC:-} ]] || exit "$STUB_DIRS_AFTER_RC"
            echo "${STUB_DIRS_AFTER:-3}"
        fi
        exit 0 ;;
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
    rm -f "$WORK/docker.log.counts" "$WORK/docker.log.cleanups" "$WORK/docker.log.dirs"
    rm -rf "$WORK/e2e.lock"
    rm -rf "$WORK/backups"
    OUT="$(printf '%s' "$stdin_text" |
        env PATH="$WORK/bin:$PATH" STUB_LOG="$WORK/docker.log" DEV_BACKUP_DIR="$WORK/backups" DEV_E2E_LOCK="$WORK/e2e.lock" NO_COLOR=1 \
            "$ROOT/dev" "$@" 2>&1)"
    RC=$?
    LOG="$(cat "$WORK/docker.log")"
}

reset_stubs() {
    unset STUB_PW_VERSION STUB_PW_VERSION_RC STUB_PW_PATH STUB_BROWSER_RC STUB_BROWSER_ERR STUB_BROWSER_OUT STUB_PS STUB_DOWN_RC STUB_RUNNING STUB_SERVICE_DB STUB_DUMP STUB_DUMP_RC STUB_COUNTS_BEFORE STUB_COUNTS_AFTER STUB_PW_RC STUB_PEST_RC STUB_RESOLVE_RC STUB_SWEEP_RC STUB_CLEANUP_RC STUB_SUITE_RUNNING_RC STUB_DIRS_BEFORE STUB_DIRS_AFTER STUB_DIRS_BEFORE_RC STUB_DIRS_AFTER_RC
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
assert_out "Before: projects=1  tasks=2  time_entries=3  tickets=4"
assert_out "After:  projects=1  tasks=2  time_entries=3  tickets=4"
assert_out "counts unchanged"
assert_log "PLAYWRIGHT_BASE_URL=http://nginx"
assert_log "--output=/tmp/dev-e2e-results --grep time entries tests/Browser/time-migration.spec.ts"
E2E_LINE="$(grep 'npm run test:e2e' <<<"$LOG")"
[[ $E2E_LINE == *" -u $UIDGID "* ]] && ok || bad "Playwright did not run as the host user $UIDGID: $E2E_LINE"
[[ $E2E_LINE != *" -u 0 "* ]] && ok || bad "Playwright ran as root: $E2E_LINE"
assert_out "as $UIDGID"
assert_log "chromium.launch"
assert_log_lacks "playwright install"
assert_log_lacks "npx"

begin "test:e2e fails with a rebuild hint when the image has no working browser (and installs nothing)"
export STUB_BROWSER_RC=1
dev_run -- test:e2e tests/Browser/auth-migration.spec.ts
assert_rc 1
assert_out "Chromium cannot launch"
assert_out "docker compose build && ./dev restart"
assert_out "never installs browsers"
assert_log_lacks "npm run test:e2e"
assert_log_lacks "mariadb"
assert_log_lacks "install"

begin "test:e2e explains a missing playwright package instead of blaming the image"
export STUB_PW_VERSION='' STUB_PW_VERSION_RC=1
dev_run -- test:e2e
assert_rc 1
assert_out "playwright-core is not installed"
assert_log_lacks "npm run test:e2e"

begin "test:e2e reports changed counts without deleting anything"
export STUB_COUNTS_AFTER='1\t2\t4\t4'
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
assert_log_lacks "npm run test:e2e"

begin "test:e2e --list skips the database entirely"
dev_run -- test:e2e --list
assert_rc 0
assert_log_lacks "mariadb"
assert_log_lacks "php -- dev"
assert_log_lacks "chromium.launch"
assert_log "npm run test:e2e -- --output=/tmp/dev-e2e-results --list"

# ── E2E ticket cleanup lifecycle (EPIC-017 §25.3) ──────────────────────────────
# line_of <pattern> [n]: the n-th (default first) line number of the docker log that contains the pattern.
line_of() { grep -n -F -- "$1" <<<"$LOG" | sed -n "${2:-1}p" | cut -d: -f1; }

begin "test:e2e sweeps before the baseline, cleans up after Playwright, and counts around both"
dev_run -- test:e2e tests/Browser/auth-migration.spec.ts
assert_rc 0
CLEANUPS="$(grep -c 'e2e:cleanup-tickets' <<<"$LOG")"
[[ $CLEANUPS -eq 2 ]] && ok || bad "expected the cleanup command twice (sweep, post-run), got $CLEANUPS"
SWEEP="$(line_of e2e:cleanup-tickets 1)" BASE="$(line_of 'mariadb -N' 1)" PW="$(line_of 'npm run test:e2e')"
POST="$(line_of e2e:cleanup-tickets 2)" AFTER="$(line_of 'mariadb -N' 2)"
[[ -n $SWEEP && -n $BASE && -n $PW && -n $POST && -n $AFTER ]] && ok || bad "a lifecycle step is missing from the log: $LOG"
[[ $SWEEP -lt $BASE && $BASE -lt $PW && $PW -lt $POST && $POST -lt $AFTER ]] && ok \
    || bad "wrong order (sweep=$SWEEP baseline=$BASE playwright=$PW post=$POST after=$AFTER): $LOG"
assert_out "E2E ticket cleanup (recovery sweep, before the suite)"
assert_out "E2E ticket cleanup (after the suite)"
assert_out "Ticket count back at its baseline"
assert_out "Playwright exit: 0 | ticket cleanup exit: 0 | ticket reconciliation: ok | attachment directories: ok"
DIRS_BASE="$(line_of e2e_ticket_dirs 1)" DIRS_AFTER="$(line_of e2e_ticket_dirs 2)"
[[ -n $DIRS_BASE && -n $DIRS_AFTER ]] && ok || bad "the attachment directory counts are missing from the log: $LOG"
[[ $SWEEP -lt $DIRS_BASE && $DIRS_BASE -lt $PW && $POST -lt $DIRS_AFTER ]] && ok \
    || bad "the directory baseline must follow the sweep and precede Playwright; the after-count must follow the post-run cleanup (sweep=$SWEEP dirs=$DIRS_BASE playwright=$PW post=$POST after=$DIRS_AFTER)"
assert_out "Attachment directories back at their baseline"

begin "test:e2e runs the cleanup as the web-server user on the verified dev target, with no ticket arguments"
dev_run -- test:e2e
CLEAN_LINE="$(grep 'e2e:cleanup-tickets' <<<"$LOG" | head -1)"
[[ $CLEAN_LINE == *" -u portal "* ]] && ok || bad "the cleanup did not run as the web-server user (it owns the attachment files): $CLEAN_LINE"
[[ $CLEAN_LINE != *" -u 0 "* ]] && ok || bad "the cleanup ran as root: $CLEAN_LINE"
[[ $CLEAN_LINE == *"APP_ENV=local"* && $CLEAN_LINE == *"DB_DATABASE=portal"* ]] && ok || bad "the cleanup target is not pinned to the dev contract: $CLEAN_LINE"
[[ $CLEAN_LINE == *"php artisan e2e:cleanup-tickets --no-interaction" ]] && ok || bad "unexpected arguments on the cleanup command: $CLEAN_LINE"
assert_log_lacks "tickets:"

begin "test:e2e does not start while another suite is running in the container, and sweeps nothing"
export STUB_SUITE_RUNNING_RC=0
dev_run -- test:e2e
assert_rc 1
assert_out "already running"
assert_log_lacks "e2e:cleanup-tickets"
assert_log_lacks "npm run test:e2e"
assert_log_lacks "mariadb"

begin "test:e2e refuses a second ./dev holding the lock, and does not touch the database"
sleep 30 &
HOLDER=$!
# dev_run clears the lock directory, so seed it here and run ./dev directly.
OUT="$(mkdir -p "$WORK/e2e.lock" && echo "$HOLDER" >"$WORK/e2e.lock/pid" &&
    env PATH="$WORK/bin:$PATH" STUB_LOG="$WORK/docker.log" DEV_BACKUP_DIR="$WORK/backups" DEV_E2E_LOCK="$WORK/e2e.lock" NO_COLOR=1 \
        STUB_RESOLVE_DEV="$DEV_OK" STUB_RESOLVE_TEST="$TEST_OK" "$ROOT/dev" test:e2e 2>&1)"
RC=$?
kill "$HOLDER" 2>/dev/null
wait "$HOLDER" 2>/dev/null
assert_rc 1
assert_out "another ./dev test:e2e (pid $HOLDER) is already running"
assert_out_lacks "Before:"

begin "test:e2e clears a stale lock left by a process that is gone, and releases its own lock"
OUT="$(mkdir -p "$WORK/e2e.lock" && echo 999999 >"$WORK/e2e.lock/pid" &&
    env PATH="$WORK/bin:$PATH" STUB_LOG="$WORK/docker.log" DEV_BACKUP_DIR="$WORK/backups" DEV_E2E_LOCK="$WORK/e2e.lock" NO_COLOR=1 \
        STUB_RESOLVE_DEV="$DEV_OK" STUB_RESOLVE_TEST="$TEST_OK" "$ROOT/dev" test:e2e 2>&1)"
RC=$?
assert_rc 0
[[ ! -e $WORK/e2e.lock ]] && ok || bad "the lock directory was not released"

begin "test:e2e releases its lock when a step dies"
export STUB_SWEEP_RC=1
dev_run -- test:e2e
[[ ! -e $WORK/e2e.lock ]] && ok || bad "the lock directory survived a failed run"

begin "test:e2e stops before the suite when the recovery sweep fails"
export STUB_SWEEP_RC=1
dev_run -- test:e2e
assert_rc 1
assert_out "the recovery sweep failed"
assert_log_lacks "npm run test:e2e"
assert_log_lacks "mariadb"

begin "test:e2e keeps a Playwright failure when the cleanup succeeds"
export STUB_PW_RC=3
dev_run -- test:e2e
assert_rc 3
assert_out "Playwright exit: 3 | ticket cleanup exit: 0 | ticket reconciliation: ok"
assert_out "Failure traces"
CLEANUPS="$(grep -c 'e2e:cleanup-tickets' <<<"$LOG")"
[[ $CLEANUPS -eq 2 ]] && ok || bad "the post-run cleanup must still run after a failed suite, got $CLEANUPS cleanup call(s)"

begin "test:e2e reports a cleanup failure even when Playwright passed"
export STUB_CLEANUP_RC=1
dev_run -- test:e2e
assert_rc 1
assert_out "Playwright exit: 0 | ticket cleanup exit: 1"
assert_out "The ticket cleanup failed"
assert_out "E2E tickets may remain"

begin "test:e2e reports both a Playwright failure and a cleanup failure, and returns the Playwright status"
export STUB_PW_RC=2 STUB_CLEANUP_RC=1
dev_run -- test:e2e
assert_rc 2
assert_out "Playwright exit: 2 | ticket cleanup exit: 1"
assert_out "The ticket cleanup failed"

begin "test:e2e fails when the ticket count does not return to the baseline, and deletes nothing to fix it"
export STUB_COUNTS_AFTER='1\t2\t3\t6'
dev_run -- test:e2e
assert_rc 1
assert_out "Ticket count did not return to its baseline: before=4 after=6"
assert_out "Nothing was deleted to reconcile it"
assert_out "ticket reconciliation: FAILED"
assert_log_lacks "DELETE"

begin "test:e2e reconciles against the post-sweep baseline, so existing non-E2E tickets are neither required to be zero nor touched"
export STUB_COUNTS_BEFORE='1\t2\t3\t57' STUB_COUNTS_AFTER='1\t2\t3\t57'
dev_run -- test:e2e
assert_rc 0
assert_out "tickets=57"
assert_out "Ticket count back at its baseline"

begin "test:e2e treats an unchanged ticket count with other counts changed as warning-only, as before"
export STUB_COUNTS_AFTER='1\t2\t4\t4'
dev_run -- test:e2e
assert_rc 0
assert_out "WARNING: product-data counts changed"
assert_out "Ticket count back at its baseline"

begin "test:e2e --list runs no cleanup, no sweep and no lock"
dev_run -- test:e2e --list
assert_log_lacks "e2e:cleanup-tickets"
assert_log_lacks "e2e_suite_probe"
[[ ! -e $WORK/e2e.lock ]] && ok || bad "--list took the lock"

begin "test:e2e refuses a non-dev target before any cleanup"
export STUB_RESOLVE_DEV='env=local\nconnection=mysql\ndatabase=other\nhost=db\nport=3306\nconfig_cached=0'
dev_run -- test:e2e
assert_rc 1
assert_log_lacks "e2e:cleanup-tickets"

begin "the ticket count is part of the shared count query, after the three existing fields"
# shellcheck disable=SC2016
EXPECTED='SELECT (SELECT COUNT(*) FROM projects), (SELECT COUNT(*) FROM tasks), (SELECT COUNT(*) FROM time_entries), (SELECT COUNT(*) FROM tickets)'
grep -q -F "$EXPECTED" "$ROOT/scripts/dev/lib.sh" && ok || bad "COUNT_SQL does not read projects, tasks, time_entries, tickets in that order"

begin "no per-test or per-worker path runs the untargeted ticket cleanup"
# The command is allowed in exactly one browser-suite file: the once-per-suite globalTeardown. Anything
# else (a fixture, a spec, a support helper) would run it while other workers are mid-test.
USERS="$(grep -rl 'e2e:cleanup-tickets' "$ROOT/src/tests/Browser" "$ROOT/src/playwright.config.ts" 2>/dev/null | sed "s#^$ROOT/##" | sort)"
[[ $USERS == "src/tests/Browser/support/global-teardown.ts" ]] && ok || bad "unexpected users of the cleanup command in the browser suite: $USERS"
grep -q "globalTeardown: './tests/Browser/support/global-teardown.ts'" "$ROOT/src/playwright.config.ts" && ok \
    || bad "playwright.config.ts does not register the suite-level globalTeardown"
grep -q "workers: 3" "$ROOT/src/playwright.config.ts" && ok || bad "the three-worker session-isolation cap changed"
if grep -q 'spawn\|execSync\|exec(' "$ROOT/src/tests/Browser/support/e2e-fixtures.ts"; then bad "the per-test E2eCleanup fixture runs a process"; else ok; fi

begin "test:e2e hands the ticket cleanup to itself: Playwright is told, and the cleanup runs after it as the web-server user"
dev_run -- test:e2e tests/Browser/auth-migration.spec.ts
assert_rc 0
PW_LINE="$(grep 'npm run test:e2e' <<<"$LOG" | head -1)"
[[ $PW_LINE == *"E2E_TICKET_CLEANUP_OWNER=dev"* ]] && ok || bad "Playwright was not told the wrapper owns the cleanup: $PW_LINE"
# Playwright itself runs as the host user (the browser and its output need that); only the cleanup is portal.
[[ $PW_LINE == *"-u $(id -u):$(id -g)"* ]] && ok || bad "Playwright must keep running as the host user: $PW_LINE"
[[ $PW_LINE != *" -u portal "* ]] && ok || bad "Playwright must not run as the web-server user: $PW_LINE"
POST_LINE="$(grep 'e2e:cleanup-tickets' <<<"$LOG" | sed -n 2p)"
[[ $POST_LINE == *" -u portal "* ]] && ok || bad "the post-suite cleanup did not run as the web-server user: $POST_LINE"
[[ $(line_of 'npm run test:e2e') -lt $(line_of e2e:cleanup-tickets 2) ]] && ok || bad "the post-suite cleanup must come after Playwright: $LOG"

begin "test:e2e reads the attachment directory counts as the web-server user, never as the host user"
dev_run -- test:e2e
DIR_LINES="$(grep 'e2e_ticket_dirs' <<<"$LOG")"
[[ $(grep -c . <<<"$DIR_LINES") -eq 2 ]] && ok || bad "expected two attachment directory reads, got: $DIR_LINES"
[[ $(grep -c ' -u portal ' <<<"$DIR_LINES") -eq 2 ]] && ok || bad "the directory counts must run as the web-server user (the directory is 0700): $DIR_LINES"

begin "test:e2e fails when the attachment directory count does not return to its baseline, even though the ticket count did"
export STUB_DIRS_BEFORE=3 STUB_DIRS_AFTER=4
dev_run -- test:e2e
assert_rc 1
assert_out "Ticket count back at its baseline"
assert_out "Attachment directories did not return to their baseline: before=3 after=4"
assert_out "Nothing was deleted to reconcile it"
assert_out "attachment directories: FAILED"
assert_log_lacks "rm -"

begin "test:e2e reconciles directories against the post-sweep baseline, so existing ticket directories are neither required to be zero nor touched"
export STUB_DIRS_BEFORE=41 STUB_DIRS_AFTER=41
dev_run -- test:e2e
assert_rc 0
assert_out "Attachment directories back at their baseline (41)"

begin "test:e2e treats an unreadable attachment directory after the suite as a failure, never as zero"
export STUB_DIRS_AFTER_RC=1
dev_run -- test:e2e
assert_rc 1
assert_out "Attachment directories could not be read after the suite"
assert_out "attachment directories: FAILED"

begin "test:e2e does not start Playwright when the attachment directory baseline cannot be read"
export STUB_DIRS_BEFORE_RC=1
dev_run -- test:e2e
assert_rc 1
assert_out "could not read the attachment directory baseline"
assert_log_lacks "npm run test:e2e"

begin "test:e2e keeps a Playwright failure when the directory reconciliation also fails"
export STUB_PW_RC=3 STUB_DIRS_AFTER=9
dev_run -- test:e2e
assert_rc 3
assert_out "Playwright exit: 3 | ticket cleanup exit: 0 | ticket reconciliation: ok | attachment directories: FAILED"

begin "test:e2e --list reads no attachment directories"
dev_run -- test:e2e --list
assert_log_lacks "e2e_ticket_dirs"

# The teardown hook itself, run for real under Node with a stub `php` first on PATH: it must do nothing under the
# wrapper, do the cleanup (and fail loudly) when Playwright was started by hand. Needs Node able to run TypeScript.
begin "globalTeardown: defers to ./dev under the wrapper, and is responsible and fatal when run directly"
if command -v node >/dev/null 2>&1 && node --experimental-strip-types -e '0' >/dev/null 2>&1 && node --experimental-strip-types -e 'import("node:process")' >/dev/null 2>&1; then
    TD="$WORK/teardown"
    mkdir -p "$TD"
    cat >"$TD/php" <<'PHP'
#!/usr/bin/env bash
printf 'php %s\n' "$*" >>"$TD_LOG"
echo "stub cleanup output"
exit "${TD_PHP_RC:-0}"
PHP
    chmod +x "$TD/php"
    run_teardown() { # run_teardown <env assignments...>; prints exit code, leaves output in TD_OUT
        : >"$TD/log"
        TD_OUT="$(env PATH="$TD:$PATH" TD_LOG="$TD/log" "$@" node --experimental-strip-types --no-warnings -e \
            "import('$ROOT/src/tests/Browser/support/global-teardown.ts').then(m => m.default()).catch(e => { console.error('THROWN: ' + e.message); process.exit(7); })" 2>&1)"
        TD_RC=$?
    }
    run_teardown E2E_TICKET_CLEANUP_OWNER=dev
    [[ $TD_RC -eq 0 ]] && ok || bad "the wrapper-owned teardown must succeed without cleaning (rc=$TD_RC): $TD_OUT"
    [[ ! -s $TD/log ]] && ok || bad "the wrapper-owned teardown ran php (it must leave the cleanup to ./dev): $(cat "$TD/log")"
    [[ $TD_OUT == *"Handed to ./dev test:e2e"* ]] && ok || bad "the hand-off was not announced: $TD_OUT"
    run_teardown E2E_UNRELATED=1
    [[ $TD_RC -eq 0 ]] && ok || bad "a direct run whose cleanup succeeds must succeed (rc=$TD_RC): $TD_OUT"
    [[ $(cat "$TD/log") == "php artisan e2e:cleanup-tickets --no-interaction" ]] && ok || bad "a direct run must run exactly the untargeted command: $(cat "$TD/log")"
    run_teardown E2E_UNRELATED=1 TD_PHP_RC=1
    [[ $TD_RC -eq 7 && $TD_OUT == *"THROWN: E2E ticket cleanup failed (exit 1)"* ]] && ok || bad "a direct run whose cleanup is refused (e.g. a permission mismatch) must be fatal (rc=$TD_RC): $TD_OUT"
    run_teardown E2E_TICKET_CLEANUP_OWNER=somebody-else TD_PHP_RC=1
    [[ $TD_RC -eq 7 ]] && ok || bad "only the exact hand-off value defers; anything else is a direct run (rc=$TD_RC): $TD_OUT"
else
    echo "skip  node cannot run TypeScript here; the globalTeardown behaviour test was not run"
fi

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

begin "doctor accepts an image with a launchable browser and is read-only"
dev_run -- doctor
assert_rc 0
assert_out "playwright-core 1.63.0"
assert_out "PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright"
assert_out "chromium 153.0.8010.12 launches as $UIDGID"
assert_out_lacks "[FAIL]"
assert_log_lacks "install"
assert_log_lacks "artisan migrate "
assert_log_lacks "chown"
assert_log_lacks "-u 0"

begin "doctor FAILS (not warns) when Chromium cannot launch"
export STUB_BROWSER_RC=1
dev_run -- doctor
assert_rc 1
assert_out "[FAIL] chromium cannot launch as $UIDGID"
assert_out "docker compose build && ./dev restart"

begin "doctor FAILS when the container is not from the current image"
export STUB_PW_PATH=''
dev_run -- doctor
assert_rc 1
assert_out "PLAYWRIGHT_BROWSERS_PATH is 'unset'"

begin "doctor FAILS when playwright-core is missing"
export STUB_PW_VERSION='' STUB_PW_VERSION_RC=1
dev_run -- doctor
assert_rc 1
assert_out "playwright-core not found"

begin "Dockerfile bakes a lockfile-versioned Chromium at the path the CLI expects"
DF="$ROOT/.docker/php/Dockerfile"
LIB_PATH="$(sed -n 's/^readonly E2E_BROWSERS_PATH=\([^ ]*\).*/\1/p' "$ROOT/scripts/dev/lib.sh")"
DF_PATH="$(sed -n 's/^ENV PLAYWRIGHT_BROWSERS_PATH=//p' "$DF")"
[[ -n $LIB_PATH && $LIB_PATH == "$DF_PATH" ]] && ok || bad "lib.sh browsers path '$LIB_PATH' != Dockerfile ENV '$DF_PATH'"
grep -q 'install --with-deps chromium' "$DF" && ok || bad "Dockerfile does not install chromium with its OS dependencies at build time"
grep -q 'playwright-core@${PW_VERSION}' "$DF" && ok || bad "Dockerfile does not install the lockfile-derived playwright-core version"
if grep -E 'playwright(-core)?@[0-9]' "$DF" >/dev/null; then bad "Dockerfile hard-codes a Playwright version"; else ok; fi
grep -q 'src/package-lock.json' "$DF" && ok || bad "Dockerfile does not derive the version from src/package-lock.json"

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
