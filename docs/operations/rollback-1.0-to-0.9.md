# Rolling the Release 1.0 code back to Release 0.9 (RE-C1)

**Status:** reviewed procedure and tooling, **not yet executed on any host**. Written for the operator and the owner. Nothing here is run on staging or
production without the owner's approval, and nothing here changes the immutable Release 0.9 build. Remediated after the independent review of 2026-11-06 under the owner's
rulings in [section 4](#4-owner-rulings-and-the-two-supported-ways-back), and updated on 2026-11-08 for MO-2A1 (a fourth migration, a fifth foreign key and the administrator-approval refusal).

**One sentence.** After Release 1.0's migrations have run, an unmodified Release 0.9 build cannot pass its own `database:verify` or `runtime:preflight`, **by design and permanently**,
but it **can** still carry the real A2 cross-context proof; this document replaces "the old gates pass" with an exact, fail-closed assessment made by the 1.0 build, an exact
comparison of what the old verifier prints, and a real A2, and it says what each of them does and does not prove.

## 1. The incompatibility, reproduced

Reproduced on 2026-11-06 with the **real** builds, on disposable databases only (a separate throwaway MariaDB 11.4.13 on a private network; the shared test database, staging and
production were not touched): Release 0.9 is commit `32ec62f5c034b6394950203941e170d830b4669c` (the staging activation commit; [gate G3](#5-release-gates-before-the-first-authoritative-upgrade) confirms it against the
deployed release's `RELEASE`); Release 1.0 is `main` at `fec5c81`. Exactly three migrations separated them at that measurement; **MO-2A1 adds a fourth** (`2026_11_07_090000_add_organization_admin_approval`) and the fourth was measured the same way on 2026-11-08 (below). `composer.lock` is identical.

| Step | Code | Database | Result |
|---|---|---|---|
| 1 | Release 0.9 | empty, then 0.9 `migrate` | `database:verify`: **passed** |
| 2 | Release 1.0 | the same database, 1.0 `migrate` (4 migrations) | 1.0 `database:verify`: **passed** |
| 3 | Release 0.9 | that migrated database | `database:verify`: **FAILED**, exit 1 |

Step 3, verbatim, **with MO-2A1** (captured on 2026-11-08 from the real Release 0.9 archive, `32ec62f`, whose SHA-256 was checked against its recorded digest, on a disposable MariaDB 11.4.13 migrated by the final MO-2A1 tree; exit 1; the 1.0 build's `database:rollback-assessment --expected` prints the same five lines, and a diff of the two was empty):

```
unexpected foreign key: organization_members.admin_approved_by->users.id (RESTRICT/RESTRICT)
unexpected foreign key: organizations.managed_by_organization_id->organizations.id (RESTRICT/RESTRICT)
unexpected foreign key: ticket_due_date_changes.ticket_id,reply_id->ticket_replies.ticket_id,id (CASCADE/RESTRICT)
unexpected foreign key: ticket_due_date_changes.ticket_id->tickets.id (CASCADE/RESTRICT)
unexpected foreign key: ticket_due_date_changes.user_id->users.id (SET NULL/RESTRICT)
```

Release 0.9's `runtime:preflight` reports the **same five lines** (it runs the same comparison first). Its `--issue` option refuses to issue a challenge while any local violation exists,
so **`runtime:preflight --issue` cannot be used**. That is the only thing that is unavailable. The challenge itself is issued by the public `RuntimeChallenges::issue()`, which does not
consult the database profile; the observers (web, queue, scheduler) record against whatever challenge is active; and `runtime:preflight --verify` still reports all four contexts.
So **a real A2 can be run on an unmodified 0.9 build** by issuing the challenge through that method ([section 6](#6-the-procedure)), and the five lines are then the *only* accepted violations.
(Measured on the real builds in the independent review: with the 1.0 schema, all four contexts `ok` and the violations exactly the declared lines (four then, five with MO-2A1, re-run on 2026-11-08: see section 10); a web or worker still on 1.0, a scheduler with a
different `APP_KEY`, a fifth real foreign key, and a challenge issued from the 1.0 build were each detected.)

**Why a tolerance in Release 1.0's manifest cannot help.** `DatabaseProfile` compares the live foreign keys with the manifest committed **in the build that runs the
check**. After a rollback that build is Release 0.9, which reads Release 0.9's `foreign-keys.json`. Nothing shipped in 1.0 reaches it. **`DatabaseProfile` is unchanged**, and it still
rejects a missing foreign key, a changed action, an unexpected key and pending migrations exactly as before.

## 2. Exactly what the Release 1.0 migrations add

From a schema diff of the two real databases above (information_schema), and equal to the rehearsal candidates' declared structure
(`database/rehearsal/candidates.php`), which the migration rehearsal verifies against reality:

| Kind | Objects |
|---|---|
| New table | `ticket_due_date_changes` (7 columns) |
| New columns | `tickets.due_override_at` (nullable timestamp); `organizations.managed_by_organization_id` (nullable); `organizations.can_manage_customers` (not null, **default 0**); `organization_members.admin_approved_at` and `organization_members.admin_approved_by` (both nullable, default null; MO-2A1). Each is nullable or defaulted, so a Release 0.9 INSERT that omits it succeeds |
| New foreign keys (**the only thing the 0.9 verifier sees**) | the five lines above (`organization_members.admin_approved_by->users.id` is MO-2A1's, `RESTRICT/RESTRICT`: a Release 0.9 delete of an approving user is refused with error 1451 and loses nothing) |
| New indexes | `ticket_due_date_changes`: primary, unique `(reply_id)`, `(ticket_id, created_at)`, the keys backing its foreign keys; `organizations(managed_by_organization_id)`; `organization_members(admin_approved_by)` (backing its foreign key) |
| Tightenings of existing tables | unique `crm_companies(organization_id)` (it replaces the plain index the existing foreign key used; that foreign key is unchanged); unique `ticket_replies(ticket_id, id)` |
| New CHECK | `organizations_partner_not_managed_check` (`can_manage_customers = 0 or managed_by_organization_id is null`; Release 0.9 writes neither column, and their defaults satisfy it) |

Nothing is dropped, renamed, retyped or backfilled, and no foreign key of Release 0.9 is missing or changed (a test enforces it). The two tightenings cannot be violated by
Release 0.9 code: its only writer of `crm_companies.organization_id` always creates a **new** organization, and `ticket_replies.id` is already unique. Each is recorded with that reasoning in the declaration.

## 3. What the old build does against the expanded schema

Application behaviour: Release 0.9 keeps serving. It ignores every new column and table, and none of the new foreign keys can be violated by its writes (ticket delete
cascades to the new table; user delete sets `user_id` null). Behaviour changes the owner should know:

- **Due-date overrides stop applying.** Release 0.9 computes overdue from `sla_due_at`; the operator's `due_override_at` and its history rows are kept, ignored, and apply again after the re-upgrade.
- **Managed relationships grant nothing, and are not protected.** In Release 1.0 a managed relationship is administrative only: it grants no access to anything. Release 0.9 has no company-delete guard, so a company of a partner or managed organization **can** be deleted during the window, leaving a relationship without its company. **Company deletion is therefore frozen for the whole window** (an instruction to operators; no software enforces it in 0.9, [section 7](#7-accepted-rollback-and-expected-exit-status)), and the re-upgrade is gated on `organizations:preflight`, which fails on such a relationship ([section 8](#8-rolling-forward-again-re-upgrade)). A 0.9 delete of a partner organization that still manages others is refused by the `RESTRICT` key: an error, not data loss.
- **Administrator approvals are unknown to Release 0.9 and must not exist during the window.** Release 0.9 can demote an administrator without clearing an approval and re-promote the row later, which revives the approval with no audit entry. MO-2A1 therefore makes `database:rollback-assessment` **refuse while either approval column holds any value** ([section 9](#9-later-packages-integration-requirements), R2): revoke every approval first, one membership at a time, on the organization page.
- **Suspended accounts stay suspended** (Release 0.9 already enforces `disabled_at`).
- **No `queue:retry`, no `migrate`** under 0.9 (see [section 6](#6-the-procedure), step 11).

## 4. Owner rulings and the two supported ways back

Owner rulings (2026-11-06), which this document implements:

| # | Ruling |
|---|---|
| 1 | Code-only rollback to Release 0.9 may be supported as an **exceptional, reviewed** operation. |
| 2 | Real A2 is preserved by issuing the challenge through the existing `RuntimeChallenges::issue()`, bypassing only the incompatible `--issue` precondition. |
| 3 | Route-fingerprint probes are removed entirely. |
| 4 | A **verified pre-upgrade backup** and a **disposable host-copy rehearsal** are required before the first authoritative upgrade. |
| 5 | A rollback with managed-organization relationships is permitted **while those relationships grant no data access**, provided company deletion is frozen throughout the window and the re-upgrade preflight is mandatory. |
| 6 | After any rollback through older membership code, **all delegated administrator approvals are untrusted**: revoke, then explicitly re-approve, before delegated administration is enabled again. |
| 7 | Once MO-2B delegation has been enabled, code-only rollback to 0.9 is **prohibited** until separately reviewed. |
| 8 | No release tags are created yet. |

**Not an ordinary rollback.** [Ordinary rollback](./staging-first-install.md#later-releases) assumes the returned-to release's own checks pass, including `database:verify`; they cannot here.
There are two supported ways back, and nothing else:

| | Mode 1: code-only (data kept) | Mode 2: restore the **pre-upgrade backup** |
|---|---|---|
| Data written since the upgrade | kept | **discarded**: everything written after the pre-upgrade backup was taken is lost |
| Schema | still Release 1.0's | exactly Release 0.9's |
| The old gates | `database:verify` and `runtime:preflight` fail with exactly the five lines; A2 is run with an issued challenge | pass unchanged, including A2 |
| Source of truth | this procedure (sections 6 and 7) | the verified backup set taken **before the first 1.0 migration**, restored by the existing procedure ([Backup and recovery](./backup-and-recovery.md)) |
| Status | a **reviewed exception**, bounded by the prohibitions below | the existing "exceptional recovery" |

**A backup taken just before the code rollback is not a Mode 2 source.** It contains the 1.0 schema (52 migrations), and restoring it gives back a database the 0.9 gates still reject. Only the
set taken before the 1.0 migrations ran contains the 0.9 schema. If it was never taken and verified, Mode 2 does not exist ([gate G1](#5-release-gates-before-the-first-authoritative-upgrade)).

**Mode 1 is prohibited (use fix-forward or Mode 2 instead; separate review needed) when any of these holds:**

- delegated administration (MO-2B) has **ever** been enabled, or any later release lets a relationship or an approval grant access to anything ([section 9](#9-later-packages-integration-requirements));
- the target directory is not the reviewed release (commit, manifest, newest migration), or has been removed;
- `database:rollback-assessment` fails for any reason, including a migration, foreign key, column, CHECK or queued class that no person has reviewed;
- a job of a class Release 0.9 lacks cannot be drained or deleted;
- the 0.9 verifier prints any line other than the saved prediction, or A2 acceptance fails ([section 7](#7-accepted-rollback-and-expected-exit-status));
- no verified pre-upgrade backup exists (gate G1), or no rehearsal was done (gate G2);
- step 0 (`prereq_ok`) does not pass, or the [freeze](#the-company-deletion-freeze) cannot be made credible (an uncontrolled account with no owner decision);
- any administrator approval remains: either `organization_members.admin_approved_at` or `admin_approved_by` holds a value, complete or half-written (R2; the assessment refuses), or the revocation was not performed through the audited operation.

Fix-forward remains the default for a defective release. Mode 1 exists for the case where the new code must be stopped but the data must not be lost.

## 5. Release gates before the first authoritative upgrade

These close **before** Release 1.0's migrations run on a host whose data matters. They are also listed in the [release notes](../product/release-1.0-notes.md#gates-that-must-be-closed-before-release-10-upgrades-an-authoritative-environment).

| Gate | Requirement |
|---|---|
| **G1** | A **verified pre-upgrade backup set** exists: taken immediately before the first 1.0 migration, with the Release 0.9 schema, verified and restorable per [Backup and recovery](./backup-and-recovery.md) (`backup:create`, `backup:verify`, and a restore drill). Its identifiers are recorded in the evidence. Without it Mode 2 does not exist. |
| **G2** | This procedure (sections 6 to 8) is **rehearsed once on a disposable host-copy** (a copy of the host configuration and a restored copy of the data, never the authoritative environment), including the A2 acceptance and the re-upgrade. |
| **G3** | The retained Release 0.9 directory's `RELEASE` records `commit=32ec62f5c034b6394950203941e170d830b4669c`, the reviewed declaration's commit. (The staging activation record is where this value came from; the directory is the authority.) The procedure re-checks it at rollback time; this gate confirms it first. |
| **G4** | The Release 0.9 directory is **retained** (never removed by cleanup) for as long as Mode 1 is wanted. |

## 6. The procedure

Written for staging with the runbook helpers (`setrel`, `art`, `cc`, `db_ok`, `wrap`, `lock_free`, `cron_runs`, `link_ok`, `wk_content_ok`, `htaccess_ok`, `sel_check`, `acme_probe`,
`$PHP`); production adapts them. `NEW` is the Release 1.0 release id being rolled back from, `OLD09` the Release 0.9 id being rolled back to. `art` follows `setrel`: **say which release each
command runs in**. Every step must pass; a failure is a stop, and until the swap (step 8) a stop is resolved by `art up` on the unchanged release 1.0.

**Evidence directory (records, never committed).** Create it **first**, private and fresh; every `> "$EV/..."` below fails if it does not exist, and a failure here is a stop:

```bash
EV="$PORTAL_ROOT/shared/evidence/rollback-$(date -u +%Y%m%dT%H%M%SZ)"; S="$EV/rollback-expected-0.9.txt"
( umask 077 && mkdir -p -- "$PORTAL_ROOT/shared/evidence" && mkdir -m 700 -- "$EV" ) \
  && [[ -d "$EV" && ! -L "$EV" && "$(stat -c '%a %U' -- "$EV")" == "700 $(id -un)" ]] \
  && ! git -C "$EV" rev-parse --is-inside-work-tree >/dev/null 2>&1 \
  && echo "evidence directory ready: $EV" || { echo "the evidence directory could not be created private (700, yours, outside any Git tree): STOP" >&2; }
```

`mkdir` without `-p` on `$EV` fails if the name already exists, so evidence of an earlier attempt is never reused or overwritten. The directory holds addresses and paths: it is never committed, pasted into a ticket or printed in a report ([Staging first install](./staging-first-install.md) keeps its own evidence the same way).

Three helper functions, defined once in the host session:

```bash
dbv_lines() {   # the violations of the SELECTED release's database:verify, one per line, sorted bytewise (the exit status is deliberately ignored; an unreadable answer yields one line that never matches)
  art database:verify --json | "$PHP" -r '$j = json_decode(stream_get_contents(STDIN), true); echo implode("\n", $j["violations"] ?? ["(unreadable)"]), "\n";' | LC_ALL=C sort; }

drain_queue() ( # drain the database queue from the SELECTED release, holding the queue lock so a cron tick cannot run beside it; --force because a worker does nothing under `art down` otherwise
  set -u; root_ok || exit 1
  exec 9>>"$PORTAL_ROOT/shared/run/queue.lock" && flock -n -x 9 || { echo "the queue lock is held: STOP" >&2; exit 1; }
  art queue:work database --stop-when-empty --force --max-time=240 --timeout=120 --tries=3 --backoff=60 --sleep=1 --no-ansi --no-interaction; )

a2_accept() {   # a2_accept <verify.json> <saved-expected-lines>: ACCEPT (exit 0); RETRY-ELIGIBLE (exit 2: ONLY a missing/conflicting observation or an expired challenge, nothing else wrong); REJECT (exit 1: anything else)
  "$PHP" -r '
    $j = json_decode((string) file_get_contents($argv[1]), true);
    $exp = array_values(array_filter(array_map("rtrim", file($argv[2])), "strlen")); sort($exp);
    $v = $j["violations"] ?? []; sort($v); $hard = []; $soft = [];
    if (! is_array($j)) { fwrite(STDOUT, "REJECT: the verification answer is unreadable\n"); exit(1); }
    $cs = $j["challenge_status"] ?? null;
    if ($cs === "expired" || $cs === "missing") $soft[] = "the challenge is $cs"; elseif ($cs !== "active") $hard[] = "the challenge is not active";
    if ($cs === "active") foreach (["cli", "web", "queue", "scheduler"] as $c) {   // no contexts are reported for an expired or missing challenge
      $r = $j["contexts"][$c] ?? []; $st = $r["status"] ?? "absent";
      if ($st === "missing" || $st === "conflict") $soft[] = "context $c: $st";
      elseif ($st !== "ok" || ($r["differences"] ?? []) !== []) $hard[] = "context $c: $st";
    }
    $web = $j["contexts"]["web"] ?? [];
    if (($web["status"] ?? null) === "ok" && strpos((string) ($web["php"] ?? ""), "8.5.") !== 0) $hard[] = "the web context is not PHP 8.5.x";
    // The answer lists the old verifier lines AND the verification messages. Every predicted line must be there; any other line is only
    // allowed when it is the message of an observation already counted above as missing, conflicting or expired.
    foreach (array_diff($exp, $v) as $l) $hard[] = "a predicted violation is absent: $l";
    foreach (array_diff($v, $exp) as $l) {
      if (! $soft || ! preg_match("/^(cli|web|queue|scheduler): (no observation was recorded|it was observed more than once)|^the challenge has expired|^no challenge is stored/", $l)) $hard[] = "an unexpected violation: $l";
    }
    if ($hard) { echo "REJECT: ".implode("; ", array_merge($hard, $soft))."\n"; exit(1); }
    if ($soft) { echo "RETRY-ELIGIBLE: ".implode("; ", $soft)."\n"; exit(2); }
    echo "ACCEPT\n";
  ' "$1" "$2"; }
```

**Before maintenance (Release 1.0 still serving; no downtime).**

0. **Prerequisites. Refuse to proceed if any is missing.** Record each value (never from memory) and run `prereq_ok`; it prints `PREREQUISITES OK` or every reason it refuses, and **nothing below starts until it passes**:
   - `BACKUP_ID` and `BACKUP_SHA256`: the identifier and the **set digest** of the **verified pre-upgrade backup** (gate G1), the set taken before the first 1.0 migration; the digest was recorded independently of the set when it was made ([Backup and recovery](./backup-and-recovery.md)).
   - `REHEARSAL_EVIDENCE`: the path of the **completed rehearsal** record (gate G2): a file that exists, written after the disposable host-copy rehearsal of sections 6 to 8, including A2 acceptance and the re-upgrade.
   - `DELEGATION_NEVER_ENABLED=yes`: the owner's written confirmation that delegated administration (MO-2B) has **never** been enabled on this environment ([section 4](#4-owner-rulings-and-the-two-supported-ways-back)). Once the marker of requirement R4 exists, the assessment also refuses on it; until then this confirmation is the only control.
   - `OLD09`: the approved rollback target is present, is the reviewed release and is retained (gates G3 and G4); the assessment re-checks the same directory at step 6.
   - **Once the approval schema exists (MO-2A1 merged):** every administrator approval has been **revoked through the audited operation of requirement R2** by an authorized operator. The assessment (step 6) refuses while any approval timestamp remains; the revocation, not a timestamp comparison, is the control.
   - `MAX_WINDOW_HOURS`: the **owner-defined maximum rollback-window duration** ([the freeze](#the-company-deletion-freeze)). No default exists; unset means no rollback.
   - `FREEZE_RECORD`: the path of the freeze record (the inventory, the acknowledgements, the owner decisions on uncontrolled accounts, the maximum duration: requirements 1, 2, 3 and 5 of [the freeze](#the-company-deletion-freeze)).

   ```bash
   prereq_ok() (   # prints PREREQUISITES OK, or every missing/invalid prerequisite (exit 1)
     bad=0; no() { echo "REFUSED: $*" >&2; bad=1; }
     [[ "${BACKUP_ID:-}" =~ ^[A-Za-z0-9._:-]{3,}$ ]] || no "BACKUP_ID (the verified pre-upgrade backup identifier) is not recorded"
     [[ "${BACKUP_SHA256:-}" =~ ^[0-9a-f]{64}$ ]] || no "BACKUP_SHA256 (the set digest) is not a recorded 64-hex digest"
     [[ -n "${REHEARSAL_EVIDENCE:-}" && -s "${REHEARSAL_EVIDENCE:-/nonexistent}" ]] || no "REHEARSAL_EVIDENCE is not an existing, non-empty rehearsal record"
     [[ "${DELEGATION_NEVER_ENABLED:-}" == yes ]] || no "the owner has not confirmed that delegated administration was never enabled"
     [[ "${OLD09:-}" =~ ^[A-Za-z0-9._-]+$ && -d "$PORTAL_ROOT/releases/${OLD09:-}" && ! -L "$PORTAL_ROOT/releases/${OLD09:-}" ]] || no "OLD09 is not an existing release directory"
     [[ "$(sed -n 's/^commit=//p' "$PORTAL_ROOT/releases/${OLD09:-x}/RELEASE" 2>/dev/null | head -n 1)" == 32ec62f5c034b6394950203941e170d830b4669c ]] || no "OLD09 is not the reviewed Release 0.9 commit"
     [[ "${MAX_WINDOW_HOURS:-}" =~ ^[1-9][0-9]{0,2}$ ]] || no "MAX_WINDOW_HOURS (owner-defined maximum window) is not recorded"
     [[ -n "${FREEZE_RECORD:-}" && -s "${FREEZE_RECORD:-/nonexistent}" ]] || no "FREEZE_RECORD is not an existing, non-empty freeze record"
     (( bad == 0 )) && echo "PREREQUISITES OK" || exit 1; )
   ```
   `prereq_ok` checks that the values are present and well-formed. It cannot judge them: the digest is compared with the independently kept record by a person, and the owner, not the shell, decides that a rehearsal was adequate.

1. **Verify the rollback target.** `setrel OLD09`, then `sed -n 1,2p "$PORTAL_ROOT/releases/$OLD09/RELEASE"`: the `commit=` is `32ec62f5c034b6394950203941e170d830b4669c`. `link_ok && wk_content_ok`. (The assessment in step 4 re-checks the commit, the foreign-key manifest and the newest migration of this exact directory; this is the early look.)
2. **Prepare its configuration while it is unselected.** `cc` (never `art config:cache` directly), then `htaccess_ok` for it: it must still be exactly what was published, handler block included. If `shared/.env` changed since that release was built, `runtime:preflight` in it (below) shows it.
3. **Dry-run the assessment, to fail early.** `setrel NEW`, then `art database:rollback-assessment --target=0.9 --target-dir="$PORTAL_ROOT/releases/$OLD09"`. It is read-only. A failure here is a stop with the application still up. It is **repeated** in step 6 after the producers are stopped, and only that second result counts.

**Maintenance window.**

4. **Stop the producers**, only after `prereq_ok` has passed and the operators have been told the window is opening ([freeze](#the-company-deletion-freeze), requirement 2). `setrel NEW`, then `art down`. Web requests answer 503; the cron wrapper keeps running but is inert (the worker takes no job, the scheduler runs nothing). Nothing may be in flight: `cron_runs` prints nothing, and `lock_free "$PORTAL_ROOT/shared/run/queue.lock" && lock_free "$PORTAL_ROOT/shared/run/schedule.lock"` succeeds. If a wrapper is still running, wait for it (at most about 270 seconds) and look again; never kill it.
5. **Drain the queue with the current, compatible release.** Still `setrel NEW`: `drain_queue`. A plain worker does nothing under maintenance mode (it exits 0 and leaves the job); `--force` is what drains. Repeat until it finds nothing. Jobs that cannot run yet (a retry backoff in the future) may remain **only if** they are of a Release 0.9 class; the assessment checks that.
6. **Assess on Release 1.0, after steps 4 and 5.**
   ```bash
   art database:verify                                                                   # this build's strict verifier: passes
   art runtime:preflight                                                                 # passes
   art database:rollback-assessment --target=0.9 --target-dir="$PORTAL_ROOT/releases/$OLD09" --json > "$EV/assessment.json"; echo "exit=$?"   # must be 0
   if art database:rollback-assessment --target=0.9 --target-dir="$PORTAL_ROOT/releases/$OLD09" --expected > "$S.tmp"; then mv -n -- "$S.tmp" "$S" && sha256sum "$S"; else echo "STOP: assessment failed"; fi
   art organizations:preflight --json > "$EV/organizations-preflight-before.json"        # the relationship baseline; its counts are in the assessment facts too
   ```
   `database:rollback-assessment` fails closed unless **all** of these hold:
   - the target directory is the reviewed release: `RELEASE` records exactly the reviewed commit, `database/profile/foreign-keys.json` is byte-for-byte the pinned manifest, and its newest migration is the declared last migration;
   - this build's strict verifier passes; **the ran-migration set is exactly this build's migration files** (nothing pending, nothing unknown) and the files after Release 0.9 are exactly the declared four;
   - every Release 0.9 foreign key still exists with the same actions, and the keys only this build has equal the reviewed list, key for key and action for action; the predicted old-verifier output equals the output recorded from the real 0.9 build;
   - every declared added column exists and is nullable or defaulted (a `NOT NULL` column without a default would make 0.9 inserts fail), and the live CHECK constraints are exactly Release 0.9's plus the declared one;
   - the default queue is the **database** queue, no job is reserved (in flight), and no waiting job is of a class, or runs a command, that Release 0.9 lacks; unreadable payloads are refused.

   Its **facts** (recorded in `assessment.json`) include the queue counts, the failed-job count, and the managed-organization relationship counts. Read its **warnings**: if any exist, they are not
   optional. In particular, **failed jobs of a class Release 0.9 lacks must never be retried under it**: no `queue:retry` for them, and no `queue:retry all`, while any exist. It passing is **not** a claim
   that the whole schema was verified, and **not** a claim that the old verifier passes. If any condition fails: `art up` and stop.
7. **Pre-check the old verifier's exact expected output, before the swap.** From the unselected Release 0.9 directory (read-only):
   ```bash
   setrel OLD09 && db_ok
   art database:verify --json >/dev/null; echo "exit=$? (1 expected: the old verifier cannot pass)"
   diff <(dbv_lines) <(LC_ALL=C sort "$S") && echo "EXACT: the old verifier prints the saved prediction and nothing else"
   art runtime:preflight --json | "$PHP" -r '$j = json_decode(stream_get_contents(STDIN), true); echo implode("\n", $j["violations"] ?? ["(unreadable)"]), "\n";' | LC_ALL=C sort | diff - <(LC_ALL=C sort "$S") && echo "EXACT"
   ```
   Both diffs must print nothing but the `EXACT` line (the `runtime:preflight` one only because step 2 cached the configuration with `cc`). **Any other line is a stop** (`setrel NEW; art up`): a missing key, a changed action, a configuration line, a pending migration.
   **Backup of the 1.0 state, with the producers stopped.** `backup:create` and `backup:verify` from the Release 1.0 selection ([Backup and recovery](./backup-and-recovery.md)); record the identifier and the set digest in the evidence. It is **mandatory when the assessment facts show a managed or partner organization** (freeze requirement 6), because it is the only way back to a deleted company, and optional otherwise. It is **not** a Mode 2 source and **does not replace gate G1**.
8. **Swap**, exactly as the runbook: `( link_ok && wk_content_ok && cd -- "$PORTAL_ROOT" && ln -s "releases/$OLD09" current.new && mv -T -- current.new current )`; then `setrel OLD09`, `art queue:restart`, `art permission:cache-reset`.
9. **Bring the application up and wait.** `htaccess_ok`, `art up`, then wait at least `realpath_cache_ttl` (stop if a context still serves the previous release after twice the recorded value).

**Real A2 from the actual Release 0.9 build.**

10. **Issue the challenge from 0.9, collect the four observations, verify.** With `setrel OLD09` (a challenge issued from the 1.0 build fails the `cli` row):
    ```bash
    CH=$(art tinker --no-ansi --execute='$c=\App\Support\Runtime\RuntimeChallenges::issue(); echo "CH=",$c["id"],PHP_EOL;' | sed -n 's/^CH=//p' | tail -n 1); echo "challenge=$CH"
    curl -fsS -o /dev/null -w "up http=%{http_code}\n" "https://$STAGING_HOST/up" | tee "$EV/up-status.txt"   # the web observation AND the health check: must print up http=200
    grep -qx 'up http=200' "$EV/up-status.txt" || echo "UP NOT 200: REJECT (see below)"                        # any other status (curl -f prints nothing and exits 22) is a stop
    wrap queue; wrap schedule                                                       # the queue and scheduler observations (or wait for the cron ticks)
    art runtime:preflight --verify --challenge="$CH" --json > "$EV/a2-verify.json"; echo "verify exit=$? (1 expected)"
    a2_accept "$EV/a2-verify.json" "$S"; echo "a2_accept exit=$?"                  # 0 ACCEPT; 2 RETRY-ELIGIBLE; 1 REJECT
    ```
    `RuntimeChallenges::issue()` is an existing public method of the 0.9 build (the runbook's `db_ok` already uses `tinker`); no file of the 0.9 build is changed. This bypasses only the `--issue` refusal. The exact-violation comparison in `a2_accept` replaces what that refusal protected.
    **`/up` through the real host over HTTPS must return HTTP 200. This is a stop condition, separate from A2.** `a2_accept` checks release identity, the four contexts and the violations; it never checks an HTTP status, and the web observation is recorded before the health page renders, so a broken web application can still ACCEPT. If `/up` returns anything but 200 (a 500, a redirect to an error page, a failed TLS or connection), the rollback is **REJECTED** even when A2 prints `ACCEPT` with all four contexts `ok` and exactly the expected violations: do not reissue for it, go to the re-upgrade below. Keep `up-status.txt` with the evidence; do not conflate A2 acceptance with application health.
    `--verify` is expected to **exit 1** and print `"passed": false`, because of the five lines. The challenge lasts 30 minutes (`RuntimeChallenges::TTL`). Reissuing is allowed only as set out in the failure rules below.
11. **While Release 0.9 serves on the 1.0 schema:** no `queue:retry` (above), no `art migrate` (the 0.9 build does not know the four migrations), no company or organization deletion, and no schema change.
12. **Finish.** `sel_check rollback-OLD09` must print the selector identical to the active approved baseline; `acme_probe release`; `htaccess_ok`; `cert_check` as in the runbook. Cron `START release=OLD09` lines in the wrapper log are secondary evidence of which directory the shell resolved; they are not part of acceptance and prove nothing about PHP.
    Record the evidence ([section 7](#7-accepted-rollback-and-expected-exit-status)). The rollback window is now open: the freeze applies until the re-upgrade.

**If step 10 does not end in ACCEPT.** Release 0.9 has now served on the 1.0 schema, so a failure is **never** resolved by "just selecting 1.0 again and carrying on": the return goes through the re-upgrade procedure ([section 8](#8-rolling-forward-again-re-upgrade)), including its mandatory preflight, so the window stays accounted for. The two outcomes differ:

- **`REJECT` (exit 1) is final for this attempt, with no reissue.** A real-host HTTPS `/up` that does not return 200 (step 10), even when A2 itself accepted. A context `mismatch` or absent, a differing release identity (a `web` or `cli` row that reports another release or PHP), a PHP version other than 8.5.x, a violation line that is not in the saved prediction (or a predicted one that is missing), or any challenge state other than `active`, `expired` or `missing`. **A mismatched release identity is never accepted.** Never "accept" it by editing the declaration or the saved prediction. Go to the re-upgrade, then consider Mode 2.
- **`RETRY-ELIGIBLE` (exit 2)** means every context that is not `ok` is only `missing` (no observation arrived) or `conflict` (a pool answered inconsistently), or the challenge expired, and **nothing else is wrong**. These can be transient (a cron tick not yet run, a pool still draining). The challenge may then be **reissued, bounded**:
  - at most **two reissues** (three challenges in all), each issued with `setrel OLD09` as in step 10 and each followed by a fresh collection of all four observations;
  - **all within 60 minutes of `art up` (step 9)**. After that window, or after the third challenge, the result is final: treat it as `REJECT`;
  - each attempt's `a2-verify.json` is kept under a distinct name; a later `ACCEPT` does not hide an earlier `REJECT` (a `REJECT` ends the attempt even if a reissue would pass);
  - a `conflict` that is still a `conflict` on the second reissue is a configuration fault (two pools disagree), not a transient: `REJECT`.

If the attempt ends without ACCEPT: `setrel NEW`, take the producers down if they are not (`art down`), and follow [section 8](#8-rolling-forward-again-re-upgrade) from step 1. Do not leave Release 0.9 serving an unaccepted rollback.

## 7. Accepted rollback and expected exit status

**The old verifier does not pass, and this procedure never says it does.** After the swap, with Release 0.9 serving the 1.0 schema:

| Command (Release 0.9) | Expected exit | Expected output |
|---|---|---|
| `database:verify` | **1** (non-zero) | exactly the saved prediction, line for line |
| `runtime:preflight` | **1** | the same lines and **no other** (production configuration cached) |
| `runtime:preflight --verify --challenge=<id>` | **1** | `challenge_status` `active`; `cli`, `web`, `queue`, `scheduler` each `ok` with no differences; `web.php` 8.5.x; `violations` exactly the saved prediction |

A rollback is **accepted** only when **all** of these hold, and `a2_accept` prints `ACCEPT`:

1. the assessment (step 6) passed after the producers were stopped and the queue drained, and the old-verifier pre-check (step 7) was exact;
2. the post-swap `database:verify` lines equal the saved prediction (`diff <(dbv_lines) <(LC_ALL=C sort "$S")` prints nothing);
3. A2 acceptance: the challenge was issued from the Release 0.9 build; all four contexts (cli, web, queue, scheduler) are `ok`; the web context reports PHP 8.5.x; the violations equal the prediction, **no more and no fewer**;
4. `sel_check`, `acme_probe release` and `htaccess_ok` pass;
5. the real host's HTTPS `/up` returned **HTTP 200** (step 10). This is separate from A2: an `ACCEPT` with any other status is a **REJECT**.

Anything else is **not** a successful rollback. A configuration line, a missing context, a fifth foreign-key line or an extra violation means: stop and return to Release 1.0 or use Mode 2.

### The company-deletion freeze

**Release 0.9 does not enforce this, and nothing in this procedure claims it does.** The 0.9 application has no company-delete guard and cannot be changed. During the window a user holding `crm.manage` or `crm.admin` can delete the CRM company of a partner or managed organization, and 0.9 will let them. The freeze is a **procedural control by people**, made credible by six requirements. Requirements 1, 2, 3 and 5 are met and written into `FREEZE_RECORD` (a file inside the evidence directory, never committed) **before step 1**, which is why step 0 checks that it exists; requirements 4 and 6 are carried out during the window and are recorded in the same evidence directory:

1. **A complete inventory of the accounts that could delete a company**: every account holding `crm.manage` or `crm.admin`, directly or through a role, with its state (active or suspended). Run it on Release 1.0 (read-only), keep the output, and name it in `FREEZE_RECORD`:
   ```bash
   setrel NEW
   art tinker --no-ansi --execute='foreach (\App\Models\User::query()->orderBy("id")->get() as $u) { if ($u->can("crm.manage") || $u->can("crm.admin")) { echo $u->id, "\t", $u->email, "\t", $u->isSuspended() ? "suspended" : "active", PHP_EOL; } }' > "$EV/freeze-inventory.txt"
   ```
   An empty inventory is itself a finding: stop and check the command ran against the intended release.
2. **Explicit acknowledgement** from every **active** account in the inventory, before the window: they have been told the window's start, its maximum duration and that they must not delete any company or organization until told it has closed. Who acknowledged, how and when is recorded by account id.
3. **An account that cannot be controlled** (no acknowledgement, unreachable, or not trusted to comply) is an **owner decision**, recorded: either an approved **suspension** of that account before the window, or **denial of the rollback** (use fix-forward or Mode 2). Suspension goes through the existing account-lifecycle feature (the administrative user screen, `AccountLifecycleService::suspend`) and its own safeguards apply unchanged: you cannot suspend your own account, and you cannot suspend the last active account administrator. If the uncontrolled account is one of those, **the rollback is denied**. A suspended account is reactivated through the same feature after the re-upgrade, on the owner's decision. This runbook prescribes the mechanism; it suspends no one.
4. **Read-only monitoring.** `organizations:preflight` is read-only. While the window is open, run it from the Release 1.0 directory (`setrel NEW; art organizations:preflight --json`) at least **every 4 hours** and **immediately before the re-upgrade**, keep each report, and compare it with `organizations-preflight-before.json`. A failure naming a relationship or a company means a company was deleted in the window: tell the owner at once and follow [section 8](#8-rolling-forward-again-re-upgrade), step 3. A failure in the **administrator-approval** section (an approval on a non-administrator, an incomplete record, or a membership changed after its approval) means older code changed membership during the window: follow [section 8](#8-rolling-forward-again-re-upgrade), step 3 as well, and treat every approval as untrusted (step 6). Monitoring detects; it never prevents.
5. **A maximum window duration**, `MAX_WINDOW_HOURS`, defined by the owner before the window opens. When it elapses the window is closed by re-upgrading ([section 8](#8-rolling-forward-again-re-upgrade)) or extended by a **new, recorded owner decision**; it is never extended by default or by silence.
6. **An additional recoverable backup when relationships exist.** If the assessment facts show any managed or partner organization (`facts.managed_organizations + facts.partner_organizations > 0`), a fresh verified backup of the **1.0 state** is taken with the producers stopped (step 7), because a company deleted in the window cannot be restored from any other source. It is **mandatory** then, optional otherwise, and in neither case a Mode 2 source or a replacement for gate G1.

The freeze is lifted only when the re-upgrade's `organizations:preflight` has exited 0 ([section 8](#8-rolling-forward-again-re-upgrade)). A company deleted during the window is **detected, not prevented**, and is repaired through the application.

## 8. Rolling forward again (re-upgrade)

Required after **any** time Release 0.9 code served the 1.0 schema, whether the window lasted ten minutes or ten days.

1. **Select the target and prepare it unselected.** The retained Release 1.0 directory, or a newer release built for the purpose (then also the standard release gates). `link_ok && wk_content_ok`, `cc`, `htaccess_ok`; its `RELEASE` commit is the one you intend.
2. **Stop the producers** (`art down` from the Release 0.9 selection) and drain the queue with the Release 0.9 build (`setrel OLD09`, `drain_queue`). Every job there was queued by 0.9, so every class is known to 1.0.
3. **The mandatory preflight, with the 1.0 build against the live database:** `setrel NEW`, `art organizations:preflight --json > "$EV/organizations-preflight-after.json"`; it **must exit 0**. It reports as failures the relationships Release 0.9 can break: a partner or managed organization **without exactly one CRM company** (a company deleted in the window), a managed organization whose partner is not a partner, a partner that is itself managed, a self-managed organization, and duplicate company links. Compare with the "before" report and with the monitoring reports of the window.

   **If the failure is in the administrator-approval section** (approved non-administrator, incomplete record, membership changed after its approval), it is not a deleted company: older code changed membership during the window. The preflight names the organization and user ids and never prints an email. Do not edit rows. Revoke each approval through the Release 1.0 service as an authorized, active operator (the organization page after `art up`, or `OrganizationMembership::revokeApproval` through `tinker` from the Release 1.0 selection with an explicit actor), which needs no confirmation for a record that never counted and is audited with the actual previous approver and time, then re-run the preflight until it exits 0. Approve again only the memberships the owner has reviewed (step 6).

   **Repair, while the application is down.** The web interface answers 503 under `art down`, and **Release 0.9 cannot repair** (it has no relationship code). The repair is done by **Release 1.0's own `OrganizationRelationships` service**, run through `tinker` from the **Release 1.0 selection**, as a named, explicitly authorized operator. The service re-checks the authorization itself (`portal.operator` **and** `crm.manage`), takes the row locks, enforces every relationship rule and writes the activity-log entry with that operator as the actor, exactly as the web screen does. **No raw SQL** (it would bypass the rules, the locks and the audit trail).

   ```bash
   rel_repair() {   # rel_repair <operator-user-id> unlinkCustomer|removePartner <organization-id>: an audited service call as an authorized, active operator
     local op=$1 action=$2 org=$3
     [[ "$op" =~ ^[0-9]+$ && "$org" =~ ^[0-9]+$ && "$action" =~ ^(unlinkCustomer|removePartner)$ ]] || { echo "usage: rel_repair <operator id> unlinkCustomer|removePartner <organization id>: STOP" >&2; return 1; }
     art tinker --no-ansi --execute='
       echo "release=", base_path(), PHP_EOL;                                  // the selected release: must be the Release 1.0 directory
       if (! class_exists(\App\Services\OrganizationRelationships::class)) { echo "REFUSED: this is not the Release 1.0 build", PHP_EOL; return; }
       $actor = \App\Models\User::findOrFail('"$op"');
       if ($actor->isSuspended() || ! $actor->can("portal.operator") || ! $actor->can("crm.manage")) { echo "REFUSED: not an active operator with crm.manage", PHP_EOL; return; }
       $org = \App\Models\Organization::withoutGlobalScopes()->findOrFail('"$org"');
       echo "changed=", var_export(app(\App\Services\OrganizationRelationships::class)->'"$action"'($org, $actor), true), PHP_EOL;' | tee -a "$EV/relationship-repairs.log"
   }
   ```
   - **Release context.** `setrel NEW` first; the `release=` line it prints must be a path inside the Release 1.0 directory (`$PORTAL_ROOT/releases/$NEW`) and not inside `$OLD09`. If it names Release 0.9 the class does not exist and the helper refuses. The helper's `class_exists` check is the guard, not a courtesy. (Rehearsed on a disposable MariaDB 11.4.13 under `art down`: an active operator unlinked a customer and removed a partner designation with activity-log entries naming the operator; a plain account was refused; a repeat unlink was a no-op; malformed arguments were refused before any code ran.)
   - **Authorization.** `<operator id>` is a named person the owner has authorized for this repair, an **active** account holding `portal.operator` and `crm.manage` (not a shared or break-glass account). The service call fails closed with an authorization error for anyone else.
   - **What a repair can do.** `unlinkCustomer` removes a customer's managing partner; `removePartner` removes a partner designation (refused while the partner still has customers: unlink them first). These **remove the relationship; they do not restore the deleted company**. Restoring one is a data recovery (the extra backup of freeze requirement 6), a separate owner decision.
   - **Audit.** Each change is an activity-log entry with the operator as the actor and the previous and new values; `$EV/relationship-repairs.log` is the operator's own record next to it. Re-run `organizations:preflight` until it exits 0.

4. `db_ok && art migrate --force` must report **nothing to migrate** (a pending migration here means a restore from a backup happened, which is a different procedure). Then `art database:verify`, `art runtime:preflight`, `art roles:sync` (dry run: in sync).
5. Swap to Release 1.0 as in the runbook, `art queue:restart`, `art permission:cache-reset`, `art up`, wait, and run the **standard A2** (`--issue`, `curl /up`, `wrap queue`, `wrap schedule`, `--verify`). With 1.0 serving, the five-line exception disappears: the gates pass unchanged and **any** violation is a stop. `sel_check`, `acme_probe release`, `htaccess_ok`.
6. **Approvals (when MO-2A1 or later is in the build).** Older membership code can demote an administrator without clearing the approval, and the approval can then be revived by a re-promotion with no trace, so **no approval is trusted** after a rollback. On re-upgrade, **delegated authority does not resume**: every administrator needs **renewed operator review and an explicit new approval** before delegated administration is enabled. This is the owner's ruling and it is enforced twice, before the rollback ([section 9](#9-later-packages-integration-requirements), R2) and after it (here). `organizations:preflight` fails on an approval left on a non-administrator, on a half-written approval, and on a membership whose `updated_at` is later than its `admin_approved_at` (MO-2A1 stamps both with one instant, so its own writes never trip it). The timestamp comparison is a diagnostic, and a hit is a reason to look closer, but a clean result is **not** proof that no approval was revived.
7. Lift the freeze. Due-date overrides apply again from the data that was kept. Record the evidence.

## 9. Later packages: integration requirements

This branch does not edit, and does not depend on, the MO-2A1 work. These are the requirements **that package and MO-2B must meet**, and the changes **this branch must make when MO-2A1 merges**.

**MO-2A1 (integrated, 2026-11-08).** MO-2A1 was reviewed (SAFE AFTER TARGETED REMEDIATION), remediated, and **rebased onto the merged RE-C1 and HD-P1 `main`** (`8cbda05`), so the order is MO-2A1 onto RE-C1, in the same change as the migration (R5). What landed together:

- the foreign key `organization_members.admin_approved_by->users.id` (`RESTRICT/RESTRICT`) in `additive_foreign_keys` of `database/profile/rollback/0.9.json`;
- the migration `2026_11_07_090000_add_organization_admin_approval` in `migrations_after_target` (its pinned candidate is in `database/rehearsal/candidates.php`; the migration file is unchanged, so its pin is);
- its two columns in `additive_columns`: `organization_members.admin_approved_at` and `organization_members.admin_approved_by`, both nullable, default `null` (it has no CHECK and no unique key, so `additive_check_constraints` and `tightenings` are unchanged);
- the **approval check** in `database:rollback-assessment`: it **refuses while any `organization_members.admin_approved_at` or `admin_approved_by` holds a value**, complete or half-written, on any membership (facts `approvals_complete` and `approvals_incomplete`), with negative and passing tests ([R2](#r-requirements));
- the old verifier prints **five** lines, in the order recorded in `expected_target_verifier_output`, captured from the real Release 0.9 archive on 2026-11-08 and diffed against `--expected`: identical.

<a id="r-requirements"></a>**Requirements on the MO-2A1 / MO-2B preflight, approval and activation gate (for whoever implements them; none is implemented by this branch).** Owner ruling (2026-11-06): before a code-only rollback through older membership code, **all existing organization administrator approvals are revoked explicitly**; the rollback assessment refuses while any approval value remains in either column; timestamps are never relied on to prove that no approval was revived; on re-upgrade, administrators need renewed operator review and explicit approval before delegated authority resumes.

| # | Requirement |
|---|---|
| R1 | `organizations:preflight` reports as a **failure** an approved membership whose role is no longer `admin`, and as a **failure** an approved row whose `updated_at` is later than `admin_approved_at`. Both are **diagnostics**: a clean result is never evidence that no approval was revived (older code can demote and re-promote without touching the approval), and no procedure may use it as such. Implemented by MO-2A1: `organizations:preflight` fails on an approved or half-approved row that is not an administrator, on a half-written record, and on `updated_at` later than `admin_approved_at`; `approve()` stamps both with one instant and a test pins it. |
| R2 | An **explicit revocation operation** clears every administrator approval **before** a code-only rollback. It (a) requires an **explicitly authorized, active operator** (the same `portal.operator` and `crm.manage` policy as the relationship operations, never a bare console call without an actor); (b) is **audited**: one activity-log entry per approval cleared, with the operator, the membership and the previous approver and time, written in the same transaction as the change; (c) is **idempotent**: a second run clears nothing and reports zero; (d) never approves anything. **`database:rollback-assessment` refuses while any approval value remains** (implemented, above), so the order is: revoke, assess, then roll back. MO-2A1 meets this with the **per-membership** revocation on the organization page (`OrganizationMembership::revokeApproval`), which the owner ruled sufficient for MO-2A1: no bulk command exists, so many approvals mean many revocations, each audited. The approval **timestamps are not the proof**: revocation is the control, and the assessment's refusal is its check. |
| R3 | Delegated administration (MO-2B) ships **disabled by default**, and enabling it requires `organizations:preflight` to pass **and** the activation marker of R4. After any rollback through 0.9 code, delegated authority **does not resume** until each administrator has had **renewed operator review and an explicit new approval** through the normal approval path (the re-upgrade, [section 8](#8-rolling-forward-again-re-upgrade), step 6). |
| R4 | **Persistent activation marker for MO-2B.** (a) **Stored durably in the database** (a table or a setting row that survives a restore, not a configuration flag, a cache entry or a file). (b) **Created only through an authorized, audited enable operation** (the actor, time and the preflight result are recorded). (c) **Never cleared by disabling the configuration flag**: turning delegation off leaves the marker in place; clearing it is a separate, reviewed operation that this procedure does not provide. (d) **Required, together with the flag, for any delegated authority**: the flag alone grants nothing and the marker alone grants nothing. (e) **Permanently disqualifies code-only rollback to Release 0.9** until a separate review says otherwise: `database:rollback-assessment` refuses while it exists (a new check with a negative control test, in the package that adds the marker). Until that package exists the prohibition ([section 4](#4-owner-rulings-and-the-two-supported-ways-back)) is procedural, enforced by the step 0 confirmation `DELEGATION_NEVER_ENABLED`. |
| R5 | A later package that adds a foreign key, a column, a CHECK, a queued class or a migration updates the declaration in the **same change**; the tests fail until it does. A queued class gets its jobs drained (step 5), and `queued_classes_added_since_target` records it. |

**Not built here.** The marker (R4) and delegated administration (R3) belong to MO-2B. R1 and R2 are met by MO-2A1.

**Mandatory MO-2B security requirement (owner ruling 2026-11-08; recorded, not built).** A change to an account's **operator eligibility or capabilities** (granting or removing `portal.operator`, the `operator` role, or a role that carries either) must **never silently restore, create or preserve delegated authority**. Today the operator exclusion makes an approved membership inert while the account is an operator, and removing eligibility makes the same approval count again with no audit entry; that is harmless only because an approval grants nothing in MO-2A1. Before MO-2B honours any approval it must close this, for example by clearing or invalidating the account's approvals, with an audit entry, in the same transaction as the eligibility change, or by requiring renewed approval, and a test must show that grant-then-remove does not restore authority. MO-2A1 deliberately does not build a role-governance framework for it.

**Organization invitations (MO-2A2, not built).** The older build has no invitation table, route or notification class: pending invitations cannot be accepted and are inert. Their queued jobs must be drained (step 5; the assessment refuses any class Release 0.9 lacks, including one queued through `ShouldQueueAfterCommit`, which a test pins by reflection), and a later `queue:retry` of a failed one under the old build would fail on the missing class (hence the prohibition).

When a later release becomes the baseline, add `database/profile/rollback/<release>.json` for it rather than editing this one.

## 10. Evidence

| Check | Result |
|---|---|
| Real 0.9 `database:verify` on a 0.9 schema | passed |
| Real 1.0 `database:verify` on the migrated schema | passed |
| Real 0.9 `database:verify` on the migrated schema | exit 1, the five lines in section 1 (re-captured with MO-2A1 on 2026-11-08 from the real archive); diffed against `database:rollback-assessment --expected`: **identical**; `runtime:preflight` prints the same five |
| `database:rollback-assessment --target-dir=<real 0.9 tree>` on the migrated schema | passed with no administrator approval present; **refused** with a complete approval, with an approval time only, and with an approver only (`--expected` exits 1 and prints nothing); passes again once cleared; on the same database before the 1.0 migrations it fails (pending migrations) |
| Real 0.9 `RuntimeChallenges::issue()` through `tinker`, observers and `runtime:preflight --verify --challenge` on the migrated schema | a challenge is issued, `challenge_status` `active`, JSON shape as `a2_accept` reads it (independent review: all four contexts `ok` with the production-like configuration, with each negative control detected; re-run on 2026-11-08 on the real archive with the five lines: `a2_accept` printed **ACCEPT**, contexts cli, web, queue and scheduler `ok` on PHP 8.5.11) |
| Harness limit of the 2026-11-08 A2 rehearsal | it ran under `php -S` against a **read-only release archive that has no `storage/` directory** (on a host that is a symlink to shared storage). Runtime context identity was established (`ACCEPT`, four contexts `ok`), but the harness's HTML `/up` returned **HTTP 500** (the view cache path is missing; the JSON `/up` returned 200 and the HTML `/up` returns 200 once `storage/` exists). This does **not** prove a healthy deployed web application. The real-host G2 rehearsal must verify that HTTPS `/up` returns 200 (step 10, section 7) |
| Queued classes | identical in 0.9 and 1.0 (six), found by reflection (including `ShouldQueueAfterCommit`); pinned by a test |
| Permission catalogue and built-in roles | unchanged since 0.9; `roles:sync` has nothing to apply |
| `TicketPolicy` | one operator ability added; nothing closed that a rollback would reopen |
| Real 0.9 test suite on the 1.0 schema (independent review) | **231 failed, 4,289 passed, 20 skipped**, classified below; the same affected files pass on a clean 0.9 schema |

**Classification of the 231 failures of the real Release 0.9 suite on the 1.0 schema.** None is an application, data-integrity or authorization defect.

| Failures | Tests | Cause |
|---|---|---|
| 71 | `CrossContextTest` (63), `RuntimePreflightTest` (5), `MigrationStateVerificationTest` (2), `StartupGuardIntegrationTest` (1) | tests that need a clean local verifier or `--issue`; they fail on the declared lines (four then, five with MO-2A1). **Real operational incompatibility**, section 1, handled by this procedure |
| 3 | `DatabaseProfileTest` (2), `PreservationSchemaTest` (1) | pin the 0.9 manifest; meant to fail on a schema that has more |
| 2 | `RequesterTicketPropsTest`, `TaskListPageTest` | a 0.9 **test fixture** inserts two companies for one organization, which the new unique key refuses; no 0.9 application code does this |
| 155 | `tests/Unit/Backup`, `tests/Unit/Rehearsal` | a **test-method artifact**: the copied migration files are refused by 0.9's migration-set check before any code under test runs. The 0.9 backup tooling was run directly on the 1.0 schema separately (`backup:create`, `verify`, open, `drill`: passed; `backup:restore` was not run) |

**Coverage limit:** the 0.9 suite does not exercise 0.9 against rows that use the new columns, and no 1.0 data was populated in the backup run.

**What the tests of this branch prove** (`tests/Unit/Database/RollbackCompatibilityTest.php`, `tests/Feature/Database/RollbackAssessmentCommandTest.php`; negative controls throughout): an unexpected foreign key (also created for real in a table),
a reviewed key missing (also dropped for real), a changed action, a 0.9 key missing or changed in the database or the build, an unreviewed key that the build and database agree on, a declaration with an invented key, a wrong action, a wildcard or
a non-addition, a stale recorded output, a tampered pinned manifest, a **pending** migration, an unknown ran migration, a migration the declaration does not cover, a declared column that is `NOT NULL` without a default (in the declaration and live), a CHECK constraint nobody reviewed
or one that disappeared, a target directory with the wrong commit, manifest or newest migration or that is this build, a non-database queue, a reserved job, an unknown or unreadable queued class, a display name that disagrees with its command, and an unknown target. Thirteen deliberate mutations of the assessment rules were each caught.

## 11. Keeping the declaration true

The declaration is part of the build (`database/profile/` ships in the archive: `rollback/0.9.json` and the pinned `releases/0.9/foreign-keys.json`, both on the [archive check list](./release-build.md#6-verify-before-upload)), so the release that is
rolled back **from** carries its own reviewed record. A change that adds, removes or alters a foreign key, a column, a CHECK, a queued class or a migration after the target **fails a test until a person updates
`database/profile/rollback/0.9.json`** and the recorded output. The contract is deliberately narrow: it does not compare every column, index or row of the schema, and it does not detect schema changes made outside migrations except on the objects it names.

## 12. Decisions

| Item | State |
|---|---|
| D1 Code-only rollback | **Decided** (ruling 1): an exceptional, reviewed operation, with the prohibitions in section 4. |
| D2 A2 substitutes | **Withdrawn.** The substitutes (cron lines, route probes) are removed; real A2 is used (ruling 2). |
| D3 Rehearsal | **Decided** (ruling 4): gate G2. |
| D4 Tags | **Deferred** (ruling 8): no tag is created yet. Proposed later: `v0.9.0` on `32ec62f`, `v1.0.0` on the release commit. |
| D5 Confirm `32ec62f` | Gate G3, plus the check at rollback time (step 1 and the assessment). The owner confirms against the deployed `RELEASE`. |
