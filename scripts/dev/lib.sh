# shellcheck shell=bash
# Shared helpers for ./dev. Sourced by ./dev and by scripts/dev/tests/run.sh.
#
# Stays Bash 3.2 compatible (macOS default): no associative arrays, no mapfile.
# Anything that can act on a database goes through resolve_env + a *_contract_violations
# check; the command name is never trusted to say which database is being touched.
#
# Some globals here are read only by commands.sh or tests/run.sh, which shellcheck cannot see when it
# checks this file on its own; each such declaration carries its own SC2034 ("appears unused") disable.

[[ -z ${DEV_LIB_LOADED:-} ]] || return 0
DEV_LIB_LOADED=1

: "${DEV_ROOT:?DEV_ROOT must be set before sourcing lib.sh}"

# ── Project contract (mirrors docker-compose.yml, src/.env, src/.env.testing) ──
# shellcheck disable=SC2034
readonly SVC_APP=app SVC_DB=db SVC_NGINX=nginx
readonly DEV_ENV=local DEV_DB=portal DEV_DB_HOST=db
# The user php-fpm runs as (.docker/php/Dockerfile, php-fpm www.conf). It owns the private attachment files, so
# the E2E ticket cleanup runs as it: another user may not even be able to see a 0700 `tickets/` directory.
readonly WEB_USER=portal
readonly TEST_ENV=testing TEST_DB=intechral_client_portal_testing TEST_DB_HOST=db
# shellcheck disable=SC2034
readonly E2E_BASE_URL=http://nginx           # Playwright inside portal_app reaches the app via nginx
# shellcheck disable=SC2034
readonly E2E_OUTPUT_DIR=/tmp/dev-e2e-results # container-local: keeps test artifacts off the host mount
# shellcheck disable=SC2034
readonly E2E_BROWSERS_PATH=/opt/ms-playwright # baked into the image (ENV in .docker/php/Dockerfile)
readonly REBUILD_HINT='docker compose build && ./dev restart'
# shellcheck disable=SC2034
readonly APP_DIR=/var/www/app
# The first three fields keep their order (compare_counts); `tickets` joins them for the E2E ticket cleanup
# reconciliation (EPIC-017 §25.3).
readonly COUNT_SQL='SELECT (SELECT COUNT(*) FROM projects), (SELECT COUNT(*) FROM tasks), (SELECT COUNT(*) FROM time_entries), (SELECT COUNT(*) FROM tickets)'

# Generated/host-mounted paths that must stay owned by the host user (checked by doctor).
# shellcheck disable=SC2034
readonly GENERATED_DIRS="src/node_modules src/public/build src/test-results src/storage src/bootstrap/cache src/resources/js/actions src/resources/js/routes src/resources/js/wayfinder src/vendor"

HOST_UID="$(id -u)"
HOST_GID="$(id -g)"

# ── Output ─────────────────────────────────────────────────────────────────────
# shellcheck disable=SC2034
if [[ -t 1 && -z ${NO_COLOR:-} ]]; then
    C_RED=$'\033[31m' C_GRN=$'\033[32m' C_YLW=$'\033[33m' C_BLD=$'\033[1m' C_RST=$'\033[0m'
else
    C_RED='' C_GRN='' C_YLW='' C_BLD='' C_RST=''
fi

say() { printf '%s\n' "$*"; }
head_line() { printf '%s%s%s\n' "$C_BLD" "$*" "$C_RST"; }
die() {
    printf '%serror:%s %s\n' "$C_RED" "$C_RST" "$*" >&2
    exit 1
}

# ── Docker / Compose ───────────────────────────────────────────────────────────
dc() { docker compose --project-directory "$DEV_ROOT" "$@"; }

require_docker() {
    command -v docker >/dev/null 2>&1 || die "docker is not installed or not on PATH."
    docker info >/dev/null 2>&1 || die "the Docker daemon is not reachable. Is Docker running?"
    docker compose version >/dev/null 2>&1 || die "'docker compose' (v2 plugin) is not available."
}

# require_running <service>...
require_running() {
    local running svc
    running="$(dc ps --services --status running </dev/null 2>/dev/null)" || die "could not list compose services."
    for svc in "$@"; do
        grep -qx "$svc" <<<"$running" || die "service '$svc' is not running. Start the stack with: ./dev up"
    done
}

# Run inside the app container as the HOST user, so generated files are not root-owned.
# HOME=/tmp because the host UID may have no passwd entry in the image. TTY only when interactive.
app_exec() {
    local -a tty=()
    [[ -t 0 && -t 1 ]] || tty=(-T)
    dc exec ${tty[@]+"${tty[@]}"} -u "$HOST_UID:$HOST_GID" -e HOME=/tmp -e NPM_CONFIG_UPDATE_NOTIFIER=false "$SVC_APP" "$@"
}

# Non-interactive probe: never reads or holds the caller's stdin.
app_probe() { app_exec "$@" </dev/null; }

# Launching the image's Chromium exactly as E2E will (host UID/GID, project-locked playwright-core).
# Proves the browser, its OS libraries and their permissions all work. Read-only: never installs anything.
readonly E2E_LAUNCH_JS="const { chromium } = require('playwright-core'); chromium.launch().then(async (browser) => { console.log(browser.version()); await browser.close(); }, (error) => { console.error(String(error.message).split('\\n')[0]); process.exit(1); });"

# playwright_version - the playwright-core version installed in src/node_modules (empty if absent).
playwright_version() { app_probe node -p "require('playwright-core/package.json').version" 2>/dev/null; }

# playwright_launch_probe - prints the Chromium version on success, or the first error line (returns non-zero).
playwright_launch_probe() { app_probe node -e "$E2E_LAUNCH_JS" 2>&1; }

# require_e2e_browser - E2E expects a correct image; it never installs browsers.
require_e2e_browser() {
    local out
    [[ -n "$(playwright_version || true)" ]] || die "playwright-core is not installed in src/node_modules. Run: ./dev shell, then npm install"
    if ! out="$(playwright_launch_probe)"; then
        die "Chromium cannot launch in '$SVC_APP' as $HOST_UID:$HOST_GID: ${out:-no output}
       The development image is expected to include Playwright's browser. Rebuild it and recreate the containers:
         $REBUILD_HINT
       (./dev never installs browsers itself.)"
    fi
}

# ── Resolving what Laravel really targets ──────────────────────────────────────
R_ENV='' R_CONNECTION='' R_DB='' R_HOST='' R_PORT='' R_APP_URL='' R_CONFIG_CACHED=''
R_SERVICE_DB='' R_PENDING='' R_PENDING_COUNT='' R_PENDING_ERROR='' R_OUTPUT=''

# parse_resolved <resolver output>  - unknown lines are ignored; missing keys stay empty (=> refused).
parse_resolved() {
    local line
    R_ENV='' R_CONNECTION='' R_DB='' R_HOST='' R_PORT='' R_APP_URL='' R_CONFIG_CACHED=''
    R_PENDING='' R_PENDING_COUNT='' R_PENDING_ERROR=''
    while IFS= read -r line; do
        # shellcheck disable=SC2034
        case "$line" in
            env=*) R_ENV="${line#env=}" ;;
            connection=*) R_CONNECTION="${line#connection=}" ;;
            database=*) R_DB="${line#database=}" ;;
            host=*) R_HOST="${line#host=}" ;;
            port=*) R_PORT="${line#port=}" ;;
            app_url=*) R_APP_URL="${line#app_url=}" ;;
            config_cached=*) R_CONFIG_CACHED="${line#config_cached=}" ;;
            pending=*) R_PENDING="${R_PENDING}${R_PENDING:+$'\n'}${line#pending=}" ;;
            pending_count=*) R_PENDING_COUNT="${line#pending_count=}" ;;
            pending_error=*) R_PENDING_ERROR="${line#pending_error=}" ;;
        esac
    done <<<"$1"
}

# resolve_env <dev|test> [--pending]
# Boots Laravel in the app container (as the host user) and captures the resolved target.
# Returns non-zero when the resolver itself fails (output kept in R_OUTPUT for diagnosis).
resolve_env() {
    local mode="$1" rc=0
    shift
    R_OUTPUT="$(dc exec -T -u "$HOST_UID:$HOST_GID" -e HOME=/tmp "$SVC_APP" php -- "$mode" "$@" \
        <"$DEV_ROOT/scripts/dev/resolve-env.php" 2>&1)" || rc=$?
    parse_resolved "$R_OUTPUT"

    R_SERVICE_DB=''
    if [[ $mode == dev ]]; then
        # What the compose db service itself was created with (independent of Laravel's .env).
        R_SERVICE_DB="$(dc exec -T "$SVC_DB" printenv MYSQL_DATABASE </dev/null 2>/dev/null)" || R_SERVICE_DB=''
    fi
    return "$rc"
}

# Pure contract checks: read R_* only, print one violation per line, never touch Docker.
# Empty output means the resolved target matches the contract.
dev_contract_violations() {
    [[ $R_ENV == "$DEV_ENV" ]] || printf 'APP_ENV resolved to "%s" (expected "%s")\n' "$R_ENV" "$DEV_ENV"
    [[ $R_CONNECTION == mysql ]] || printf 'DB driver resolved to "%s" (expected "mysql")\n' "$R_CONNECTION"
    [[ $R_DB == "$DEV_DB" ]] || printf 'database resolved to "%s" (expected "%s")\n' "$R_DB" "$DEV_DB"
    [[ $R_HOST == "$DEV_DB_HOST" ]] || printf 'database host resolved to "%s" (expected compose service "%s")\n' "$R_HOST" "$DEV_DB_HOST"
    [[ $R_SERVICE_DB == "$R_DB" ]] || printf 'compose service "%s" was created with database "%s", not "%s"\n' "$SVC_DB" "$R_SERVICE_DB" "$R_DB"
}

test_contract_violations() {
    [[ $R_ENV == "$TEST_ENV" ]] || printf 'APP_ENV resolved to "%s" (expected "%s")\n' "$R_ENV" "$TEST_ENV"
    [[ $R_CONNECTION == mysql ]] || printf 'DB driver resolved to "%s" (expected "mysql")\n' "$R_CONNECTION"
    [[ $R_DB == "$TEST_DB" ]] || printf 'database resolved to "%s" (expected exactly "%s")\n' "$R_DB" "$TEST_DB"
    [[ $R_HOST == "$TEST_DB_HOST" ]] || printf 'database host resolved to "%s" (expected compose service "%s")\n' "$R_HOST" "$TEST_DB_HOST"
    [[ $R_CONFIG_CACHED == 0 ]] || printf 'Laravel config is cached (bootstrap/cache/config.php); it would override the test environment. Run: ./dev shell, then php artisan config:clear\n'
}

# enforce_contract <dev|test> - exits (fail closed) unless the resolved target matches.
enforce_contract() {
    local kind="$1" violations line
    violations="$("${kind}_contract_violations")"
    if [[ -z $violations ]]; then return 0; fi

    {
        printf '%sREFUSING:%s the resolved target does not match the %s contract.\n' "$C_RED" "$C_RST" "$kind"
        while IFS= read -r line; do printf '  - %s\n' "$line"; done <<<"$violations"
        printf 'Nothing was changed.\n'
    } >&2
    exit 1
}

# resolve_or_die <dev|test> [--pending]
resolve_or_die() {
    local mode="$1"
    resolve_env "$@" || {
        printf '%s\n' "$R_OUTPUT" >&2
        die "could not resolve the $mode environment inside the '$SVC_APP' container."
    }
}

# print_target <label> - always shown before anything acts on a database.
print_target() {
    head_line "Target: $1"
    say "  Environment:   ${R_ENV:-?}"
    say "  Database:      ${R_DB:-?}"
    say "  Host/service:  ${R_HOST:-?}:${R_PORT:-?}"
}

# ── Backups ────────────────────────────────────────────────────────────────────
BACKUP_PATH=''

# backup_database <db>  - dumps <db> from the compose db service as the host user; sets BACKUP_PATH.
# Credentials come from the db container's own environment and are never printed.
backup_database() {
    local db="$1" dir="${DEV_BACKUP_DIR:-$DEV_ROOT/backups/dev}" final partial
    final="$dir/$db-$(date +%Y-%m-%d_%H%M%S).sql"
    partial="$final.partial"

    (umask 077 && mkdir -p "$dir") || die "cannot create backup directory: $dir"
    [[ ! -e $final ]] || die "backup already exists (wait a second and retry): $final"

    # Redirect happens in this host shell, so the file is owned by the host user (0600).
    if ! (
        umask 077
        dc exec -T "$SVC_DB" sh -c \
            'MYSQL_PWD="$MYSQL_PASSWORD" exec mariadb-dump --single-transaction --routines --triggers --events -u"$MYSQL_USER" "$1"' \
            sh "$db" </dev/null >"$partial"
    ); then
        rm -f "$partial"
        die "database dump failed; no backup was written."
    fi

    if [[ ! -s $partial ]]; then
        rm -f "$partial"
        die "database dump produced an empty file; no backup was written."
    fi
    # mariadb-dump ends a complete dump with this marker; a truncated dump lacks it.
    if ! tail -n 5 "$partial" | grep -q -- '-- Dump completed'; then
        rm -f "$partial"
        die "database dump looks truncated (no completion marker); no backup was written."
    fi

    mv "$partial" "$final"
    # shellcheck disable=SC2034
    BACKUP_PATH="$final"
}

# ── Product-data counts (E2E leak reporting) ───────────────────────────────────
# product_counts <db>  - prints "<projects> <tasks> <time_entries> <tickets>"
product_counts() {
    dc exec -T "$SVC_DB" sh -c \
        'MYSQL_PWD="$MYSQL_PASSWORD" exec mariadb -N -B -u"$MYSQL_USER" "$1" -e "$2"' \
        sh "$1" "$COUNT_SQL" </dev/null
}

# ── One E2E suite per database (EPIC-017 §25.3) ────────────────────────────────
# The ticket cleanup is untargeted (every `E2E-` ticket of the seeded E2E customers), so a second suite on
# the same database could lose tickets it is still using. Two small checks, neither a lock service:
#   * a host lock directory (mkdir is atomic) for ./dev against ./dev, with a stale-lock check by PID;
#   * a probe of the app container's process table for a `playwright test` process, which also sees a suite
#     started by hand with `npm run test:e2e` or from another shell.
# Neither can see a suite on another machine or a process started after the check. It is the supported
# single-suite constraint, enforced as far as this reaches, not a guarantee.
# Resolved on use, not at load, so sourcing this file needs nothing beyond Bash.
e2e_lock_dir() {
    if [[ -n ${DEV_E2E_LOCK:-} ]]; then
        printf '%s\n' "$DEV_E2E_LOCK"
    else
        printf '%s/dev-e2e-suite.%s.lock\n' "${TMPDIR:-/tmp}" "$(printf '%s' "$DEV_ROOT" | cksum | cut -d' ' -f1)"
    fi
}

# Exits 0 when a `playwright test` process exists in the container. The bracket keeps this very script (whose
# own command line contains the pattern) from matching itself. `e2e_suite_probe` names it for the self-tests.
readonly E2E_SUITE_PROBE_SH='e2e_suite_probe=1; for p in /proc/[0-9]*; do [ -r "$p/cmdline" ] || continue; if tr "\0" " " <"$p/cmdline" 2>/dev/null | grep -Eq "[p]laywright(/cli\.js)? test"; then exit 0; fi; done; exit 1'

# e2e_suite_running - true when a Playwright suite is already running in the app container.
e2e_suite_running() {
    app_probe sh -c "$E2E_SUITE_PROBE_SH"
}

# acquire_e2e_lock - dies if another ./dev test:e2e holds the lock; clears a lock whose owner is gone.
acquire_e2e_lock() {
    local owner lock
    lock="$(e2e_lock_dir)"
    for _ in 1 2; do
        if mkdir "$lock" 2>/dev/null; then
            printf '%s\n' "$$" >"$lock/pid"
            return 0
        fi
        owner="$(cat "$lock/pid" 2>/dev/null || true)"
        if [[ -n $owner ]] && kill -0 "$owner" 2>/dev/null; then
            die "another ./dev test:e2e (pid $owner) is already running against this database. Two suites on one database can delete each other's tickets; wait for it to finish."
        fi
        rm -rf "$lock"
    done
    die "could not take the E2E suite lock at $lock."
}

release_e2e_lock() {
    local lock
    lock="$(e2e_lock_dir)"
    [[ "$(cat "$lock/pid" 2>/dev/null || true)" == "$$" ]] && rm -rf "$lock"
    return 0
}

# ── Attachment directories (EPIC-017 §25.3) ────────────────────────────────────
# A deleted ticket row is no proof its private files went. The ticket cleanup is compared against the number of
# entries under storage/app/private/tickets (one directory per ticket that ever had an attachment) taken before
# and after the suite. It is read as the web-server user, because that directory is 0700 and owned by it: any
# other user would see nothing and call it zero, so an unreadable directory FAILS here and is never counted as 0.
# The `e2e_ticket_dirs=1;` prefix names the script for the self-tests. $1 is the application directory.
readonly E2E_TICKET_DIRS_SH='e2e_ticket_dirs=1; cd "$1" || exit 1; p=storage/app/private; l=$(ls -A "$p") || exit 1; printf "%s\n" "$l" | grep -qx tickets || { echo 0; exit 0; }; n=$(find "$p/tickets" -mindepth 1 -maxdepth 1 -printf x) || exit 1; printf "%s" "$n" | wc -c | tr -d " "'

# ticket_dir_count - prints the number of entries under tickets/, or fails if they cannot be listed.
ticket_dir_count() {
    dc exec -T -u "$WEB_USER" -e HOME=/tmp "$SVC_APP" sh -c "$E2E_TICKET_DIRS_SH" sh "$APP_DIR" </dev/null
}

# confirm_yes <prompt> - true only for an exact "yes" on stdin (EOF/anything else declines).
confirm_yes() {
    local reply=''
    printf '%s ' "$1"
    read -r reply || true
    [[ $reply == yes ]]
}
