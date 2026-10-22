#!/bin/sh
# Bounded cron execution of the Release 0.9 queue worker and scheduler (R0.9 WP3-B1). Design and operation: docs/architecture/cron-execution.md.
#
#   portal-cron.sh queue      one bounded drain of the database queue (`queue:work --stop-when-empty`)
#   portal-cron.sh schedule   one scheduler tick (`schedule:run`)
#
# Cron calls it through the stable entry point (the symlink `current`) and gives it the shared root; nothing else:
#
#   */5    * * * *  PORTAL_ROOT=/home/ACCOUNT/portal /bin/sh /home/ACCOUNT/portal/current/scripts/cron/portal-cron.sh schedule
#   2-59/5 * * * *  PORTAL_ROOT=/home/ACCOUNT/portal /bin/sh /home/ACCOUNT/portal/current/scripts/cron/portal-cron.sh queue
#
# No redirect on the cron line: cPanel mails whatever a cron job prints, and this script prints only on a (rate-limited) failure.
#
# What it does, in order:
#  1. Re-executes itself once through its own PHYSICAL path (stage 1 below), so everything that follows comes from ONE release.
#  2. Takes an independent, nonblocking flock per task in $PORTAL_ROOT/shared/run. A busy lock is a recorded, silent skip, unless the holder is
#     implausibly old (then a failure).
#  3. Runs the explicit PHP CLI under `timeout -k`, from the physical release, with the pinned queue/scheduler arguments.
#  4. Classifies the outcome (exit status, the application's failure marker), appends START/END and the bounded output to
#     $PORTAL_ROOT/shared/logs, and prints a short categorical notice (so cron mails it) only for a new, changed or long-repeating failure, and once on recovery.
#
# POSIX sh only. umask 0077. No daemon, no background process, no --env override. The timings below are IMPLEMENTATION DEFAULTS that satisfy the
# relationships tested in tests/Unit/Cron, not proven limits of the Namecheap host.

set -u
umask 0077

# --- Stage 1: one resolution of the entry point. -------------------------------------------------------------------------------------------------
# Cron starts `sh .../current/scripts/cron/portal-cron.sh`: the shell opens whichever release `current` points at THAT instant. Looking at `current` again
# later (to find the application) could point at a newer release, mixing this script with another release's files. So the script resolves its own pathname
# exactly once, here, then replaces itself with a copy of `sh` reading the file at that PHYSICAL path. Everything after this point, including its own text,
# belongs to that one release. The first few lines of this prologue still come from whichever release the entry point showed first; they only resolve and
# re-execute, so they carry no release-specific behaviour (docs/architecture/cron-execution.md, "Release safety").
#
# `exec` keeps the process ID, so the marker that says "already re-executed" is the shell's own PID: a value inherited from the environment (a stale or
# hostile PORTAL_CRON_STAGE, whatever it holds) is not this shell's PID and cannot skip the resolution. Stage 2 then checks the one thing the marker only
# implies, that this script really was started by its physical path, and refuses to run otherwise (so even a coincidentally equal value cannot mix releases).
if [ "${PORTAL_CRON_STAGE:-}" != "$$" ]; then
    self=$(readlink -f -- "$0" 2>/dev/null) && [ -n "$self" ] || { echo "portal-cron: cannot resolve its own location" >&2; exit 70; }
    PORTAL_CRON_STAGE=$$ exec /bin/sh "$self" "$@"
    exit 70
fi
unset PORTAL_CRON_STAGE

die() { # exit code, message
    echo "portal-cron: $2" >&2
    exit "$1"
}

[ $# -eq 1 ] || die 64 "usage: portal-cron.sh queue|schedule"
TASK=$1
case $TASK in
    queue) SLOT_BASE=2 ;;
    schedule) SLOT_BASE=0 ;;
    *) die 64 "usage: portal-cron.sh queue|schedule" ;;
esac

# --- Timings: IMPLEMENTATION DEFAULTS (seconds). -------------------------------------------------------------------------------------------------
QUEUE_MAX_TIME=120       # worker stops claiming new jobs after this (checked BETWEEN jobs; it does not interrupt a running job)
QUEUE_JOB_TIMEOUT=120    # worker --timeout: a per-job alarm; Laravel then kills the worker with SIGKILL
QUEUE_TERM=255           # GNU timeout: SIGTERM to the whole worker, measured from the start of the worker process
SCHED_TERM=240           # the same for schedule:run
KILL_AFTER=15            # then SIGKILL this much later, so the worker is gone by QUEUE_TERM + KILL_AFTER = 270 s
RETRY_AFTER_FLOOR=300    # DB_QUEUE_RETRY_AFTER; RuntimeProfile::MIN_RETRY_AFTER enforces at least this (tests/Unit/Cron pins the relation)
STALE_GRACE=30           # a lock holder older than TERM + KILL_AFTER + this is not a worker that is merely still finishing
ALERT_REPEAT=86400       # an identical failure is mailed again after this long
LOG_MAX_BYTES=524288     # rotate a task log above this (one older generation is kept)
OUTPUT_MAX_BYTES=65536   # captured output kept per run

# Tests (and only tests) may shorten these. In production an override is a configuration error, never silently honoured.
override() { # NAME
    eval "_value=\${PORTAL_CRON_$1:-}"
    [ -n "$_value" ] || return 0
    [ "${PORTAL_CRON_TEST:-}" = 1 ] || die 64 "PORTAL_CRON_$1 is a test-only override"
    case $_value in *[!0-9]*) die 64 "PORTAL_CRON_$1 must be a whole number of seconds or bytes" ;; esac
    eval "$1=\$_value"
}
for _name in QUEUE_MAX_TIME QUEUE_JOB_TIMEOUT QUEUE_TERM SCHED_TERM KILL_AFTER RETRY_AFTER_FLOOR STALE_GRACE ALERT_REPEAT LOG_MAX_BYTES OUTPUT_MAX_BYTES; do
    override "$_name"
done

# The relationships that make the bound mean something. A worker's reservations all start after it does, so if it is gone by TERM + KILL_AFTER and
# retry_after is longer, none of its reservations can expire while it still runs. MAX_TIME + JOB_TIMEOUT < TERM keeps the normal exit graceful.
[ "$KILL_AFTER" -gt 0 ] && [ "$QUEUE_TERM" -gt 0 ] && [ "$SCHED_TERM" -gt 0 ] || die 64 "timings must be positive"
[ $((QUEUE_MAX_TIME + QUEUE_JOB_TIMEOUT)) -lt "$QUEUE_TERM" ] || die 64 "QUEUE_MAX_TIME + QUEUE_JOB_TIMEOUT must be below QUEUE_TERM"
[ $((QUEUE_TERM + KILL_AFTER)) -lt "$RETRY_AFTER_FLOOR" ] || die 64 "QUEUE_TERM + KILL_AFTER must be below the retry_after floor"
[ $((SCHED_TERM + KILL_AFTER)) -lt "$RETRY_AFTER_FLOOR" ] || die 64 "SCHED_TERM + KILL_AFTER must be below the retry_after floor"

# --- Layout. -------------------------------------------------------------------------------------------------------------------------------------
self=$(readlink -f -- "$0" 2>/dev/null) || die 70 "cannot resolve its own location"
[ "$self" = "$0" ] || die 70 "not started through its physical path (stage 1 was skipped)"
case $self in
    */scripts/cron/portal-cron.sh) REL=${self%/scripts/cron/portal-cron.sh} ;;
    *) die 70 "must be run from <release>/scripts/cron/portal-cron.sh" ;;
esac
RELNAME=${REL##*/}

case ${PORTAL_ROOT:-} in
    /*) ;;
    *) die 64 "PORTAL_ROOT must be set to an absolute path" ;;
esac
[ -d "$PORTAL_ROOT/shared" ] || die 70 "$PORTAL_ROOT/shared does not exist"

PHP=${PORTAL_PHP:-/opt/alt/php85/usr/bin/php}
case $PHP in
    /*) ;;
    *) die 64 "PORTAL_PHP must be an absolute path" ;;
esac
[ -x "$PHP" ] || die 70 "the PHP CLI binary is not executable"

for _tool in flock timeout readlink date mkdir chmod head tr awk wc grep mv rm; do
    command -v "$_tool" >/dev/null 2>&1 || die 70 "required tool not found on PATH: $_tool"
done

RUN=$PORTAL_ROOT/shared/run
LOGS=$PORTAL_ROOT/shared/logs
for _dir in "$RUN" "$LOGS"; do
    [ -d "$_dir" ] || mkdir -m 700 "$_dir" || die 70 "cannot create a shared directory"
    [ -O "$_dir" ] || die 70 "a shared directory is not owned by this account"
    chmod 700 "$_dir" || die 70 "cannot restrict a shared directory"
done

LOCK=$RUN/$TASK.lock
HOLDER=$RUN/$TASK.holder
STATE=$RUN/$TASK.alert
OUT=$RUN/$TASK.out
LOG=$LOGS/cron-$TASK.log

now_utc() { date -u +%Y-%m-%dT%H:%M:%SZ; }
log() { printf '%s %s pid=%s %s\n' "$(now_utc)" "$TASK" "$$" "$*" >>"$LOG" 2>/dev/null; }

# Notice printed to stdout (cron mails it). Categorical text only: fixed words and fields this script validated itself, never application output.
notice() { printf '%s\n' "$*"; }

# --- Alerting: first failure, changed failure, a reminder every ALERT_REPEAT seconds, and one recovery notice. --------------------------------------
read_state() { # sets STATE_KIND (ok|fail), STATE_FP, STATE_TIME
    STATE_KIND=ok STATE_FP='' STATE_TIME=0
    if [ -r "$STATE" ]; then
        read -r STATE_KIND STATE_FP STATE_TIME <"$STATE" 2>/dev/null || :
    fi
    case $STATE_KIND in ok | fail) ;; *) STATE_KIND=ok ;; esac
    case $STATE_TIME in '' | *[!0-9]*) STATE_TIME=0 ;; esac
}

# failure <category> <exit status> <fingerprint detail> <human detail>
failure() {
    _category=$1 _status=$2 _fp="$1/$2/$3" _detail=$4
    read_state
    _now=$(date +%s)
    if [ "$STATE_KIND" = fail ] && [ "$STATE_FP" = "$_fp" ] && [ $((_now - STATE_TIME)) -lt "$ALERT_REPEAT" ]; then
        log "ALERT suppressed category=$_category (the same failure was mailed $((_now - STATE_TIME)) s ago)"
    else
        notice "portal-cron FAILED task=$TASK category=$_category status=$_status release=$RELNAME at=$(now_utc)${_detail:+ $_detail}"
        notice "Local detail (restricted file, may contain sensitive text): shared/logs/cron-$TASK.log. An identical failure is not mailed again for $((ALERT_REPEAT / 3600)) h."
        printf 'fail %s %s\n' "$_fp" "$_now" >"$STATE"
        log "ALERT mailed category=$_category"
    fi
}

success() {
    read_state
    if [ "$STATE_KIND" = fail ]; then
        notice "portal-cron RECOVERED task=$TASK release=$RELNAME at=$(now_utc) (previous failure category=${STATE_FP%%/*})"
        log "ALERT recovery mailed"
    fi
    [ "$STATE_KIND" = ok ] && [ -e "$STATE" ] || printf 'ok\n' >"$STATE"
}

# --- Lock: independent per task, nonblocking, never deleted or replaced. -----------------------------------------------------------------------------
true >>"$LOCK" || die 70 "cannot open the lock file"
true >>"$LOG" 2>/dev/null
chmod 600 "$LOG" "$LOCK" 2>/dev/null
exec 9>>"$LOCK"
if ! flock -n 9; then
    HOLDER_PID='' HOLDER_SINCE='' HOLDER_REL=''
    [ -r "$HOLDER" ] && { read -r HOLDER_PID HOLDER_SINCE HOLDER_REL <"$HOLDER" 2>/dev/null || :; }
    _stale_after=$(( $( [ "$TASK" = queue ] && echo "$QUEUE_TERM" || echo "$SCHED_TERM" ) + KILL_AFTER + STALE_GRACE ))
    _age=-1
    case $HOLDER_SINCE in '' | *[!0-9]*) ;; *) _age=$(($(date +%s) - HOLDER_SINCE)) ;; esac
    if [ "$_age" -gt "$_stale_after" ]; then
        # Look again before calling it stale: a new holder may have taken the lock just before writing its own record.
        sleep 1
        _again=
        [ -r "$HOLDER" ] && { read -r _again <"$HOLDER" 2>/dev/null || :; }
        case $_again in "$HOLDER_PID $HOLDER_SINCE"*) ;; *) _age=-1 ;; esac
    fi
    if [ "$_age" -gt "$_stale_after" ]; then
        log "SKIP lock held and STALE holder_pid=$HOLDER_PID holder_age_s=$_age holder_release=${HOLDER_REL:-unknown} limit_s=$_stale_after"
        failure lock-stale 75 '' "holder_age_s=$_age limit_s=$_stale_after holder_release=${HOLDER_REL:-unknown}"
        exit 75
    fi
    log "SKIP lock held holder_pid=${HOLDER_PID:-unknown} holder_age_s=$_age holder_release=${HOLDER_REL:-unknown}"
    exit 0
fi

# We hold the lock from here until every process that inherited descriptor 9 has exited, wrapper or not.
_started=$(date +%s)
printf '%s %s %s\n' "$$" "$_started" "$RELNAME" >"$HOLDER"

_size=$(wc -c <"$LOG" 2>/dev/null | tr -d ' ')
if [ "${_size:-0}" -gt "$LOG_MAX_BYTES" ]; then
    mv -f "$LOG" "$LOG.1" 2>/dev/null
    true >>"$LOG"
    chmod 600 "$LOG" "$LOG.1" 2>/dev/null
fi

# Where in its five-minute slot this tick actually started (the daily 02:00 UTC scheduler task is only due if a tick starts inside that minute).
# shellcheck disable=SC2046
set -- $(date -u '+%-M %-S')
_slot_offset=$(((($1 - SLOT_BASE + 5) % 5) * 60 + $2))
log "START release=$RELNAME slot_offset_s=$_slot_offset"

if [ ! -f "$REL/artisan" ]; then
    log "END status=fail category=layout exit=70 release=$RELNAME"
    failure layout 70 '' ''
    exit 70
fi

# --- Run. --------------------------------------------------------------------------------------------------------------------------------------
if [ "$TASK" = queue ]; then
    _term=$QUEUE_TERM
    set -- queue:work database --stop-when-empty "--max-time=$QUEUE_MAX_TIME" "--timeout=$QUEUE_JOB_TIMEOUT" --tries=3 --backoff=60 --sleep=1 --no-ansi --no-interaction
else
    _term=$SCHED_TERM
    set -- schedule:run --no-ansi --no-interaction
fi

true >"$OUT"
cd "$REL" || die 70 "cannot enter the release"
# PORTAL_CRON_MARKER reaches only this PHP process. Descriptor 9 (the lock) is inherited on purpose: a child that outlives this shell keeps the lock.
PORTAL_CRON_MARKER=1 timeout -k "$KILL_AFTER" "$_term" "$PHP" "$REL/artisan" "$@" </dev/null >"$OUT" 2>&1
_rc=$?
_duration=$(($(date +%s) - _started))

# --- Classify. Precedence: timeout, SIGKILL, refused start, database marker, scheduler marker, malformed marker, any other nonzero status. -------------------------------
_category=ok _detail='' _fpdetail=''
# A marker line counts only if it matches the exact format; the class and SQLSTATE or status in a notice are copied from a line that passed.
_db=$(grep -E '^@@PORTAL-CRON-FAILURE@@ v1 category=database class=[A-Za-z_][A-Za-z0-9_\\]{0,119} sqlstate=([0-9A-Z]{5}|none)$' "$OUT" | head -n 1)
_sched=$(grep -E '^@@PORTAL-CRON-FAILURE@@ v1 category=scheduler class=[A-Za-z_][A-Za-z0-9_\\]{0,119} exit=([0-9]{1,3}|none)$' "$OUT" | head -n 1)
if [ "$_rc" -eq 124 ]; then
    _category=timeout
    _detail="duration_s=$_duration limit_s=$_term"
elif [ "$_rc" -eq 137 ]; then
    if [ "$_duration" -ge $((_term + KILL_AFTER - 1)) ]; then
        _category=timeout
        _detail="duration_s=$_duration limit_s=$_term killed_after_term=yes"
    else
        _category=sigkill
        _detail="duration_s=$_duration"
    fi
elif [ "$_rc" -ne 0 ] && grep -qF 'Refusing to start `' "$OUT"; then
    _category=profile-refused
elif [ -n "$_db" ]; then
    _category=database
    _cls=${_db#*class=}
    _cls=${_cls%% *}
    _state=${_db##*sqlstate=}
    _detail="class=$_cls sqlstate=$_state"
    _fpdetail="$_cls:$_state"
elif [ -n "$_sched" ]; then
    _category=scheduler
    _cls=${_sched#*class=}
    _cls=${_cls%% *}
    _x=${_sched##*exit=}
    _detail="class=$_cls task_exit=$_x"
    _fpdetail="$_cls:$_x"
elif [ "$(grep -c '^@@PORTAL-CRON-FAILURE@@' "$OUT")" -gt 0 ]; then
    # The prefix is reserved for the application's own marker; a line that has it but not the exact format is an anomaly, reported without being quoted.
    _category=marker-invalid
elif [ "$_rc" -ne 0 ]; then
    _category=php-exit
fi

# Output is kept locally, bounded, with control and non-ASCII bytes removed and every line prefixed so it can never pass for a log record of this script.
# awk (not sed) does the prefixing because it ends an unterminated last line, so the END record below always starts on its own line.
# It is application output and may be sensitive: it is never sent to cron.
if [ -s "$OUT" ]; then
    head -c "$OUTPUT_MAX_BYTES" "$OUT" | tr -cd '\11\12\40-\176' | awk '{ print "| " $0 }' >>"$LOG" 2>/dev/null
    [ "$(wc -c <"$OUT" | tr -d ' ')" -gt "$OUTPUT_MAX_BYTES" ] && printf '| [output truncated at %s bytes]\n' "$OUTPUT_MAX_BYTES" >>"$LOG" 2>/dev/null
fi
rm -f "$OUT"

if [ "$_category" = ok ]; then
    log "END status=ok category=ok exit=0 duration_s=$_duration release=$RELNAME"
    success
    exit 0
fi

if [ "$_rc" -ne 0 ]; then _exit=$_rc; else _exit=90; fi
log "END status=fail category=$_category php_status=$_rc exit=$_exit duration_s=$_duration release=$RELNAME${_detail:+ $_detail}"
failure "$_category" "$_rc" "$_fpdetail" "$_detail"
exit "$_exit"
