# shellcheck shell=bash
# Command implementations for ./dev. Sourced by ./dev after lib.sh.
#
# Adding a command:
#   1. Write cmd_<name> below (':' in the command name becomes '_': db:status -> cmd_db_status).
#   2. Add a "name|usage|description" line to DEV_COMMANDS. That list drives BOTH help and dispatch.
#   3. If it can touch a database: resolve_or_die, print_target, enforce_contract - in that order,
#      before acting. Never decide the target from the command name.
#   4. Container work goes through app_exec (host UID/GID); only use app_exec_root if it truly
#      cannot run unprivileged, and keep its output off the host mount.
#   5. Add a case to scripts/dev/tests/run.sh and mention it in README.md.

# Lines starting with '#' are help section headings; the rest are "name|usage|description".
DEV_COMMANDS=(
    '#General'
    'help||Show this help (also: ./dev with no arguments)'
    'doctor||Read-only diagnostics: docker, services, DBs, pending migrations, versions, ports, file ownership'
    '#Environment'
    'up||Start the dev stack (docker compose up -d; builds only if an image is missing)'
    'down||Stop and remove containers. Volumes (databases) are NEVER removed'
    'restart||Same as down then up: recreates containers, keeps volumes, never rebuilds images'
    'shell||Interactive bash in the app container as your host UID:GID'
    '#Database (development DB "portal" only)'
    'db:status||Migration status of the development DB (read-only)'
    'db:migrate||Show pending, back up, ask for "yes", then run "php artisan migrate" (never fresh)'
    'db:backup||Dump the development DB to backups/dev/<db>-<timestamp>.sql'
    '#Tests and checks'
    'test:php|[pest args]|Pest against the TESTING DB (intechral_client_portal_testing); refuses otherwise'
    'test:e2e|[playwright args]|Playwright in portal_app. Runs against the DEVELOPMENT DB; prints before/after counts'
    'check|[--no-php]|CLI self-tests, git diff --check, Pint, npm run check, then the full Pest suite (--no-php skips Pest)'
)

command_registered() {
    local entry
    for entry in "${DEV_COMMANDS[@]}"; do
        if [[ $entry != '#'* && ${entry%%|*} == "$1" ]]; then return 0; fi
    done
    return 1
}

dev_main() {
    local cmd="${1:-help}"
    [[ $# -eq 0 ]] || shift
    case "$cmd" in -h | --help) cmd=help ;; esac

    if ! command_registered "$cmd"; then
        printf '%serror:%s unknown command "%s". Run ./dev help for the list.\n' "$C_RED" "$C_RST" "$cmd" >&2
        exit 2
    fi

    "cmd_${cmd//:/_}" "$@"
}

cmd_help() {
    local entry name usage desc rest
    say "Intechral Client Portal developer CLI"
    say
    say "Usage: ./dev <command> [args...]"
    for entry in "${DEV_COMMANDS[@]}"; do
        if [[ $entry == '#'* ]]; then
            say
            head_line "${entry#\#}"
            continue
        fi
        name="${entry%%|*}"
        rest="${entry#*|}"
        usage="${rest%%|*}"
        desc="${rest#*|}"
        printf '  %-26s %s\n' "$name${usage:+ $usage}" "$desc"
    done
    cat <<'TXT'

Notes
  - Commands act on the database they RESOLVE inside the container, verified before acting.
  - Container commands run as your host UID:GID, so no root-owned files land in the repo.
    The one exception is test:e2e (Playwright's browsers live in root's home); its output
    is redirected inside the container.
  - There is deliberately no db:fresh, db:restore or volume removal in this version.
  - Backups are written to backups/dev/ (gitignored).
TXT
}

# ── Lifecycle ──────────────────────────────────────────────────────────────────
cmd_up() {
    require_docker
    dc up -d || return $?
    say
    say "Stack is up. App: http://localhost:4242   Mail: http://localhost:8025"
}

cmd_down() {
    require_docker
    dc down || return $?
    say "Containers removed. Volumes (database, redis) were kept."
}

# down, then up. Stops at the first failure and returns its exit code. Never rebuilds, never removes volumes.
# (cmd_up/cmd_down return their own failures explicitly: errexit is off inside a function used before ||.)
cmd_restart() {
    cmd_down || return $?
    cmd_up
}

cmd_shell() {
    require_docker
    require_running "$SVC_APP"
    say "Shell in '$SVC_APP' as $HOST_UID:$HOST_GID (exit to return)."
    app_exec bash
}

# ── Database ───────────────────────────────────────────────────────────────────
cmd_db_status() {
    require_docker
    require_running "$SVC_APP" "$SVC_DB"
    resolve_or_die dev
    print_target "Development"
    enforce_contract dev
    say
    app_exec php artisan migrate:status
}

cmd_db_backup() {
    require_docker
    require_running "$SVC_APP" "$SVC_DB"
    resolve_or_die dev
    print_target "Development (backup source)"
    enforce_contract dev
    backup_database "$R_DB"
    say "Backup written: $BACKUP_PATH ($(wc -c <"$BACKUP_PATH" | tr -d ' ') bytes)"
}

cmd_db_migrate() {
    require_docker
    require_running "$SVC_APP" "$SVC_DB"
    resolve_or_die dev --pending
    print_target "Development (migrate)"
    enforce_contract dev

    [[ -z $R_PENDING_ERROR ]] || die "could not read migration state: $R_PENDING_ERROR"
    if [[ -z $R_PENDING_COUNT || $R_PENDING_COUNT -eq 0 ]]; then
        say
        say "No pending migrations. Nothing to do."
        return 0
    fi

    say
    head_line "Pending migrations ($R_PENDING_COUNT)"
    while IFS= read -r line; do say "  - $line"; done <<<"$R_PENDING"
    say

    backup_database "$R_DB"
    say "Pre-migration backup: $BACKUP_PATH"
    say

    if ! confirm_yes "Apply $R_PENDING_COUNT migration(s) to database \"$R_DB\" ($R_ENV)? Type 'yes' to continue:"; then
        die "aborted; no migrations were applied. The backup was kept."
    fi

    # Pin the verified target on the command itself (process env beats .env) so what runs is
    # exactly what was checked. No force flag: only production-like environments need one.
    app_exec env "APP_ENV=$DEV_ENV" "DB_DATABASE=$DEV_DB" "DB_HOST=$DEV_DB_HOST" php artisan migrate
    say
    head_line "Migration status after migrating"
    app_exec php artisan migrate:status
}

# ── Tests ──────────────────────────────────────────────────────────────────────
# run_pest [args...] - verifies the resolved test target, then runs Pest as the host user.
run_pest() {
    require_docker
    require_running "$SVC_APP" "$SVC_DB"
    resolve_or_die test
    print_target "Testing (Pest)"
    enforce_contract test
    say
    app_exec env "APP_ENV=$TEST_ENV" "DB_DATABASE=$TEST_DB" "DB_HOST=$TEST_DB_HOST" ./vendor/bin/pest "$@"
}

cmd_test_php() {
    run_pest "$@"
}

cmd_test_e2e() {
    require_docker
    require_running "$SVC_APP" "$SVC_NGINX" "$SVC_DB"

    local before='' after='' listing=0 arg rc=0
    for arg in "$@"; do
        if [[ $arg == --list ]]; then listing=1; fi
    done

    if [[ $listing -eq 0 ]]; then
        resolve_or_die dev
        print_target "Development (E2E)"
        enforce_contract dev
        say
        say "${C_YLW}NOTE:${C_RST} the browser suite currently exercises the DEVELOPMENT database \"$R_DB\"."
        say "      Test fixtures clean up after themselves; counts are compared below."
        before="$(product_counts "$R_DB")" || die "could not read baseline counts."
        print_counts "Before" "$before"
        say
    fi

    say "Running Playwright in '$SVC_APP' (root, isolated output: $E2E_OUTPUT_DIR), base URL $E2E_BASE_URL"
    app_exec_root env "PLAYWRIGHT_BASE_URL=$E2E_BASE_URL" npm run test:e2e -- "--output=$E2E_OUTPUT_DIR" "$@" || rc=$?

    if [[ $listing -eq 0 ]]; then
        say
        after="$(product_counts "$R_DB")" || die "Playwright finished (exit $rc) but the after-counts could not be read."
        print_counts "After" "$after"
        compare_counts "$before" "$after"
    fi
    [[ $rc -eq 0 ]] || say "Failure traces (if any) stay inside the container at $E2E_OUTPUT_DIR."
    return "$rc"
}

# print_counts <label> "<projects> <tasks> <time_entries>"
print_counts() {
    local p t e
    read -r p t e <<<"$2"
    printf '%-7s projects=%s  tasks=%s  time_entries=%s\n' "$1:" "$p" "$t" "$e"
}

# compare_counts <before> <after> - informational only; never deletes anything.
compare_counts() {
    if [[ $1 == "$2" ]]; then
        say "${C_GRN}Product-data counts unchanged.${C_RST} (Session rows may change; they are not counted.)"
    else
        say "${C_YLW}WARNING: product-data counts changed. The suite may have leaked fixtures,"
        say "or data was edited while it ran. Nothing was deleted; inspect the development DB.${C_RST}"
    fi
}

# ── Check ──────────────────────────────────────────────────────────────────────
CHECK_SUMMARY=''
CHECK_FAILED=0

# check_step <label> <command...> - runs in a subshell so a fatal die() fails only this step.
check_step() {
    local label="$1" rc=0
    shift
    head_line "==> $label"
    ("$@") || rc=$?
    if [[ $rc -eq 0 ]]; then
        CHECK_SUMMARY="${CHECK_SUMMARY}  ${C_GRN}PASS${C_RST}  $label"$'\n'
    else
        CHECK_SUMMARY="${CHECK_SUMMARY}  ${C_RED}FAIL${C_RST}  $label (exit $rc)"$'\n'
        CHECK_FAILED=1
    fi
    say
}

check_git_diff() {
    git -C "$DEV_ROOT" diff --check && git -C "$DEV_ROOT" diff --cached --check
}

# check [--no-php]
#   Runs every step (no fail-fast) and prints a summary. The frontend chain is the repo's own
#   `npm run check` (wayfinder, tsc, eslint, prettier, vitest, vite build) and is run once;
#   Pint and Pest are separate because npm does not cover them. Pest is the slow step
#   (~4 min); --no-php skips it for quick frontend loops.
cmd_check() {
    local with_php=1 arg
    for arg in "$@"; do
        case "$arg" in
            --no-php) with_php=0 ;;
            *) die "unknown option for check: $arg (only --no-php is supported)" ;;
        esac
    done

    require_docker
    require_running "$SVC_APP" "$SVC_DB"

    check_step "CLI self-tests (host bash, stubbed docker)" bash "$DEV_ROOT/scripts/dev/tests/run.sh"
    check_step "git diff --check" check_git_diff
    check_step "Pint (style, no changes written)" app_probe ./vendor/bin/pint --test
    check_step "Frontend: npm run check" app_probe npm run check
    if [[ $with_php -eq 1 ]]; then
        check_step "PHP tests: Pest (testing DB)" run_pest
    else
        CHECK_SUMMARY="${CHECK_SUMMARY}  SKIP  PHP tests (--no-php)"$'\n'
    fi

    head_line "Summary"
    printf '%s' "$CHECK_SUMMARY"
    [[ $CHECK_FAILED -eq 0 ]] || exit 1
    say "All checks passed."
}

# ── Doctor (read-only: it never starts, migrates, chowns or writes application data) ──
DOCTOR_FAILS=0
DOCTOR_WARNS=0
DOCTOR_APP_UP=0
DOCTOR_DB_UP=0

d_section() {
    say
    head_line "$1"
}
d_ok() { printf '  %s[ ok ]%s %s\n' "$C_GRN" "$C_RST" "$*"; }
d_info() { printf '  [info] %s\n' "$*"; }
d_warn() {
    DOCTOR_WARNS=$((DOCTOR_WARNS + 1))
    printf '  %s[warn]%s %s\n' "$C_YLW" "$C_RST" "$*"
}
d_fail() {
    DOCTOR_FAILS=$((DOCTOR_FAILS + 1))
    printf '  %s[FAIL]%s %s\n' "$C_RED" "$C_RST" "$*"
}

doctor_docker() {
    local version
    if ! command -v docker >/dev/null 2>&1; then
        d_fail "docker is not installed or not on PATH"
        return 1
    fi
    if ! version="$(docker version --format '{{.Server.Version}}' 2>/dev/null </dev/null)"; then
        d_fail "docker CLI found, but the daemon is not reachable"
        return 1
    fi
    d_ok "docker engine $version"
    if version="$(docker compose version --short 2>/dev/null </dev/null)"; then
        d_ok "docker compose $version"
    else
        d_fail "'docker compose' v2 plugin is not available"
        return 1
    fi
}

doctor_services() {
    local listing svc line state health required
    listing="$(dc ps --all --format '{{.Service}} {{.State}} {{.Health}}' </dev/null 2>/dev/null)" || listing=''
    for svc in "$SVC_APP" "$SVC_NGINX" "$SVC_DB" redis mailpit queue; do
        state='' health=''
        line="$(grep -E "^$svc " <<<"$listing" || true)"
        [[ -z $line ]] || read -r _ state health <<<"$line"
        required=0
        case "$svc" in "$SVC_APP" | "$SVC_NGINX" | "$SVC_DB") required=1 ;; esac

        if [[ $state == running ]]; then
            d_ok "$svc running${health:+ ($health)}"
            if [[ $svc == "$SVC_APP" ]]; then DOCTOR_APP_UP=1; fi
            if [[ $svc == "$SVC_DB" ]]; then DOCTOR_DB_UP=1; fi
        elif [[ $required -eq 1 ]]; then
            d_fail "$svc is ${state:-not created}. Start the stack with: ./dev up"
        else
            d_warn "$svc is ${state:-not created}"
        fi
    done
}

# doctor_target <label> <dev|test> - resolves and reports one Laravel target.
doctor_target() {
    local label="$1" mode="$2" violations line
    if ! resolve_env "$mode" --pending; then
        d_fail "$label: could not resolve the environment: $(tail -n 1 <<<"$R_OUTPUT")"
        return 0
    fi
    d_info "$label resolves to: APP_ENV=$R_ENV, database=$R_DB, host=$R_HOST:$R_PORT"
    violations="$("${mode}_contract_violations")"
    if [[ -z $violations ]]; then
        d_ok "$label matches its contract"
    else
        while IFS= read -r line; do d_fail "$label: $line"; done <<<"$violations"
    fi

    if [[ -n $R_PENDING_ERROR ]]; then
        d_fail "$label: could not read migrations: $R_PENDING_ERROR"
    elif [[ ${R_PENDING_COUNT:-0} -eq 0 ]]; then
        d_ok "$label: no pending migrations"
    elif [[ $mode == dev ]]; then
        d_warn "$label: $R_PENDING_COUNT pending migration(s) (apply with ./dev db:migrate)"
        while IFS= read -r line; do d_info "  $line"; done <<<"$R_PENDING"
    else
        d_info "$label: $R_PENDING_COUNT pending migration(s) (informational: RefreshDatabase migrates it per test run)"
    fi
}

doctor_databases() {
    d_info "expected development DB: $DEV_DB (APP_ENV=$DEV_ENV, host $DEV_DB_HOST)"
    d_info "expected testing DB:     $TEST_DB (APP_ENV=$TEST_ENV, host $TEST_DB_HOST)"
    if [[ $DOCTOR_APP_UP -eq 0 || $DOCTOR_DB_UP -eq 0 ]]; then
        d_warn "app/db not both running; skipping Laravel resolution and migration checks"
        return 0
    fi
    doctor_target "development" dev
    DOCTOR_APP_URL="$R_APP_URL"
    doctor_target "testing" test
}

doctor_versions() {
    local out
    if command -v php >/dev/null 2>&1; then
        d_ok "host php $(php -r 'echo PHP_VERSION;' 2>/dev/null) (not required by ./dev)"
    else
        d_info "host php not installed (not required)"
    fi
    if command -v node >/dev/null 2>&1; then
        d_ok "host node $(node -v 2>/dev/null), npm $(npm -v 2>/dev/null || echo '?') (not required)"
    else
        d_info "host node not installed (not required)"
    fi

    if [[ $DOCTOR_APP_UP -eq 0 ]]; then
        d_warn "app container not running; skipping container tool versions"
        return 0
    fi
    d_ok "container php $(app_probe php -r 'echo PHP_VERSION;' 2>/dev/null || echo '?')"
    d_ok "container node $(app_probe node -v 2>/dev/null || echo '?'), npm $(app_probe npm -v 2>/dev/null || echo '?')"

    # Root probe: Playwright's browsers are installed under root's home.
    out="$(app_exec_root sh -c "cd $APP_DIR && ./node_modules/.bin/playwright --version 2>&1; ls -d /root/.cache/ms-playwright/chromium-* 2>/dev/null | tr '\\n' ' '" </dev/null 2>/dev/null)" || out=''
    if [[ $out == *Version* ]]; then
        d_ok "playwright: $(head -n 1 <<<"$out" | sed 's/^Version //') (container, run as root)"
        if [[ -n $(sed -n '2p' <<<"$out" | tr -d ' ') ]]; then
            d_ok "playwright browsers: $(sed -n '2p' <<<"$out" | xargs -n1 basename | tr '\n' ' ')"
        else
            d_warn "no Playwright chromium build found under /root/.cache/ms-playwright"
        fi
    else
        d_warn "playwright not available in the container (run npm install inside it)"
    fi
}

doctor_http() {
    local url="${DOCTOR_APP_URL:-http://localhost:4242}" code
    d_info "APP_URL (Laravel): $url"

    if [[ $DOCTOR_APP_UP -eq 1 ]]; then
        code="$(app_probe curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$E2E_BASE_URL/" 2>/dev/null || true)"
        if [[ $code =~ ^[23] ]]; then
            d_ok "container -> $E2E_BASE_URL/ answered HTTP $code (E2E base URL)"
        else
            d_warn "container -> $E2E_BASE_URL/ answered '${code:-no response}'"
        fi
    fi

    if command -v curl >/dev/null 2>&1; then
        code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$url" 2>/dev/null || true)"
        if [[ $code =~ ^[23] ]]; then
            d_ok "host -> $url answered HTTP $code"
        else
            d_warn "host -> $url answered '${code:-no response}'"
        fi
    else
        d_info "host curl not installed; skipping external URL probe"
    fi

    local pair port name
    for pair in "4242 nginx" "3306 mariadb" "6379 redis" "8025 mailpit-web" "1025 mailpit-smtp"; do
        port="${pair%% *}" name="${pair#* }"
        if (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; then
            d_ok "port $port open ($name)"
        else
            d_warn "port $port not reachable ($name)"
        fi
    done
}

doctor_ownership() {
    local rel path count hits='' hint_paths=''
    if [[ $HOST_UID -eq 0 ]]; then
        d_info "running as root on the host; ownership check not applicable"
        return 0
    fi
    for rel in $GENERATED_DIRS; do
        path="$DEV_ROOT/$rel"
        [[ -e $path ]] || continue
        count="$(find "$path" -user root 2>/dev/null | wc -l | tr -d ' ')"
        if [[ $count -gt 0 ]]; then
            d_warn "$rel: $count root-owned file(s)/dir(s)"
            hint_paths="$hint_paths $APP_DIR/${rel#src/}"
            hits=1
        else
            d_ok "$rel: no root-owned files"
        fi
    done
    if [[ -n $hits ]]; then
        d_info "one-time repair (not run by ./dev; from the repo root):"
        d_info "  docker compose exec -u 0 app chown -R $HOST_UID:$HOST_GID$hint_paths"
    fi
}

cmd_doctor() {
    DOCTOR_FAILS=0 DOCTOR_WARNS=0 DOCTOR_APP_UP=0 DOCTOR_DB_UP=0 DOCTOR_APP_URL=''
    head_line "Intechral Client Portal: doctor (read-only)"
    say "  Repository: $DEV_ROOT"
    say "  Host user:  $(id -un) ($HOST_UID:$HOST_GID)"

    d_section "Docker"
    if doctor_docker; then
        d_section "Services"
        doctor_services
        d_section "Databases and migrations"
        doctor_databases
        d_section "Tool versions"
        doctor_versions
        d_section "URLs and ports"
        doctor_http
    fi
    d_section "File ownership (generated, host-mounted paths)"
    doctor_ownership

    say
    if [[ $DOCTOR_FAILS -gt 0 ]]; then
        say "${C_RED}$DOCTOR_FAILS failure(s)${C_RST}, $DOCTOR_WARNS warning(s)."
        return 1
    fi
    say "${C_GRN}No failures${C_RST}, $DOCTOR_WARNS warning(s)."
}
