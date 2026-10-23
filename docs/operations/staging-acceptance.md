# Staging Acceptance Checklist (Release 0.9, WP3-B2)

**Authority:** the Release 0.9 shared-hosting runtime gate. **Companions:** [Staging First Install](./staging-first-install.md) (the procedure, the gates G1 to G12 and the `art` / `wrap` helpers used here),
[Release Build](./release-build.md), [Cron Execution](../architecture/cron-execution.md), [Runtime Preflight](../architecture/runtime-preflight.md).

This is what must be true, and what evidence proves it, before the staging rehearsal counts as passed. It is a checklist, not a test suite: every row is an observation on the host, recorded in the
[evidence record](#evidence-record).

> **Status: documentation only; no row has been recorded as passed.** The owner has reported several host facts ([Current state](./staging-first-install.md#current-state-owner-reported-2026-10-22): the CLI extensions, the PHP version of the existing portal, the staging hostname,
> alias and certificate, the cron changes). Those reports are inputs, not evidence: a row is "not run" until its own command has been run and its output recorded. The CLI rows of section A run before any resource is created by this procedure (phase 0) and the web rows
> at G1d, before the release is uploaded; the rest follow the install (gates G1 to G7, and G7a only if the account-wide cron email must change). Section I deliberately breaks and repairs the staging configuration and needs its own approval (**G8**). Section L is the **separate**
> long-duration survival test (**G11**): this document does not contain its procedure.
>
> **What a pass does not prove.** It proves the application and the cron wrapper behave as designed on this host, for the duration observed. It does not prove that a process of up to about 270 seconds is
> permitted (human Namecheap confirmation is pending: [Host support dependency](./staging-first-install.md#host-support-dependency)), and an observation window of days cannot prove that the 02:00 UTC daily task
> is reliable under every future host condition ([section J](#j-the-0200-utc-scheduler-risk)).

Conventions: the session setup in the runbook defines the guards and helpers used here (`art`, `wrap`, `cc`, `db_ok`, `lock_free`, `procs_env`, `procs_cmd`, `cron_runs`, `setrel`, and for the ACME link `wk_ok`, `wk_content_ok`, `wk_symprobe` and `acme_probe`) and the variables `PORTAL_ROOT`, `PHP`, `STAGING_HOST`, `STAGING_ALIAS`, `HOLD`, `STAGING_DB`, `REL_ID` and `REL`.
**Pass** is stated per row; anything else is a fail to investigate, not to retry silently. A row marked **(stop)** is an immediate stop, not a defect to work around.

## A. Host and PHP

The CLI rows A1 to A6 are read-only and run in [phase 0](./staging-first-install.md#phase-0-build-rulings-and-the-read-only-host-check-no-host-change-g0), **before any resource is created**. The web rows A7, A10 and A12 run at [G1d](./staging-first-install.md#phase-1-staging-resources-g1-each-sub-gate-separate), **before the release is uploaded**.
A missing required extension fails closed in both runtimes.

| # | Check | How | Pass |
|---|---|---|---|
| A1 | CLI binary and version | `"$PHP" -v \| head -1; "$PHP" -r 'echo PHP_SAPI, PHP_EOL;'` | `PHP 8.5.x`, SAPI `cli`, at `/opt/alt/php85/usr/bin/php` |
| A2 | CLI extensions and functions, directly | the phase 0 command, with `EXTS_CLI` taken from the build's `RELEASE` (`required_extensions`, plus `pdo_mysql`, `pcntl`, `posix`). Confirm `exif` is in it: `[[ ",${EXTS_CLI:?}," == *,exif,* ]] && echo "exif is checked"` | `CLI extensions and functions: PASS`; no `MISSING`. **Composer's generated platform check does not test extensions**, so this row is the only extension evidence for the CLI |
| A3 | Release loads on this PHP | `rel_ok && ( cd -- "$REL" && env -i PATH=/usr/bin:/bin "$PHP" -r 'require "vendor/autoload.php"; echo "PHP version and 64-bit check passed", PHP_EOL;' )` | the line prints. This is Composer's version and architecture check **only** |
| A4 | Signal and process functions | `"$PHP" -r 'foreach (["pcntl_async_signals","pcntl_signal","pcntl_alarm","pcntl_signal_dispatch","posix_kill","proc_open"] as $f) echo $f, function_exists($f) ? " callable" : " MISSING", PHP_EOL; echo "disable_functions=[", ini_get("disable_functions"), "]", PHP_EOL;'` | all `callable`; `disable_functions` lists none of them |
| A5 | SIGALRM and SIGTERM (2 s, bounded) | the two probes in [Cron Execution, host signal prerequisites](../architecture/cron-execution.md#staging-rehearsal-documented-not-executed) | `SIGALRM handled` after about 2 s; `SIGTERM handled`. These prove the mechanism only, never process lifetime |
| A6 | Cron-shell tools | the tool loop in phase 0, then `env -i PATH=/usr/bin:/bin sh -c 'set -- $(date -u "+%-M %-S"); echo "date ok $1 $2"; [ -O "$HOME" ] && echo "[ -O ] ok"; readlink -f -- /bin/sh'` | nothing `MISSING`; the two `ok` lines print, then the resolved path of `/bin/sh` (the host has GNU coreutils 8.30; the wrapper was only tested on newer ones) |
| A7 | **Web** extensions and PHP, directly | the G1d probe, run against the staging hostname's own handler | `RESULT=PASS`; PHP **8.5.x**; `exif`, `dom`, `fileinfo`, `iconv`, `libxml`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `zlib`, `ctype`, `curl` all `ok`; the probe file removed (`HTTP 404` afterwards). **(stop)** on any `MISSING`: enabling an extension through the account-wide selector is an owner decision |
| A8 | **Web PHP as the application ran it** | the `web` row of the A2 `--verify` table after the release is served (H2) | `php 8.5.x`. A2 does not judge PHP versions, so this is read by a person. **Repeated after every release swap and every rollback** (H3) |
| A9 | The existing portal's runtime is unchanged **(stop)** | re-read the MultiPHP Manager list (or the UAPI listing) and compare with the baseline taken at G1d, after: G1d, phase 8, every release swap, every rollback and teardown | identical for the existing portal and every other domain; any difference is an immediate stop |
| A10 | PHP handler placement and carry-over | at G1d: `$HOLD/.htaccess` absent before, and after a per-domain PHP selection either absent (handler not in a file) or one block that `htaccess_check` accepts; in phase 8: `htaccess.baseline` extracted and its SHA-256 equal to `htaccess_sha256` in `RELEASE`, the block adopted (`htaccess_adopt`), applied to the **unselected** release (`htaccess_apply`), and `htaccess_ok` after the apply, after the document-root change, after any cPanel action on the hostname, after every swap and before every rollback | the mechanism is recorded; the block is exactly one bounded cPanel PHP 8.5 handler block (an unfamiliar format is an owner review, never a relaxed check); `shared/home/handler-carry-over.log` records the release, the archive's hash, the block's hash and the result's hash with `mode=644`; the archived bytes are an exact prefix of the result; `htaccess_ok` passes every time, and a served release is never edited. Anything else in a diff **(stop)** |
| A11 | Only a per-domain setting was used | the owner confirms in cPanel that the CloudLinux "Select PHP Version" extension and option toggles were not changed, and MultiPHP Manager shows a change only on the staging hostname | confirmed; otherwise **(stop)** |
| A12 | **This web server follows the `.well-known` symlink** (G1d, before any release exists) | `wk_symprobe` (runbook G1d step 2a): a throw-away tree in `HOLD` with `dir/.well-known -> ../target`, one random token fetched over https on both names and compared exactly | `RESULT=PASS`; two `PASS` lines; no `wk-probe-*` left in `HOLD`; `HOLD/.well-known` untouched. **(stop)** on `FAIL`: no release is selected until the owner rules on another mechanism. **Not yet run; not inferred from any report.** It proves the server's symlink policy only: the served-release check is D10 |

## B. Filesystem, permissions, quota, resources

| # | Check | How | Pass |
|---|---|---|---|
| B1 | Layout and modes | `find "$PORTAL_ROOT" -maxdepth 2 -printf '%m %u %p\n' \| sed "s#$HOME/##"` | `portal-staging` and `releases` 755; `incoming`, `shared`, `shared/{home,run,logs,storage}` 700; `shared/.env` and any `shared/.env.good` 600; all owned by the account |
| B2 | Release permissions | `stat -c '%a %n' "$REL" "$REL/scripts/cron/portal-cron.sh" "$REL/public/.htaccess"; find "$REL" ! -type l -perm /022 \| wc -l` | 755, 755, 644; zero group- or world-writable **files and directories**. Symbolic links are excluded (`! -type l`) because a link's own mode is always 0777, so a scan that counted them would report the three approved links (`.env`, `storage`, `public/.well-known`) on a correct release; they are checked by path and owner in B11 |
| B3 | One user for web and cron | after one page load and one cron tick: `find "$PORTAL_ROOT/shared/storage" "$PORTAL_ROOT/shared/run" "$PORTAL_ROOT/shared/logs" ! -user "$(id -un)" \| wc -l` | 0. Files the wrapper creates are 0600 (its `umask 0077`); the web reads them as the same user |
| B4 | Nothing private is served | `for u in storage/ RELEASE composer.json vendor/autoload.php .env bootstrap/cache/config.php; do curl -s -o /dev/null -w "$u %{http_code}\n" "https://$STAGING_HOST/$u"; done` | none returns 200 (the document root is `public/`; the configuration cache is outside it) |
| B5 | **Configuration cache mode, after every build** | `cc` prints `config cache written, mode 600` and, independently, `stat -c '%a %U %n' "$REL/bootstrap/cache/config.php" "$PORTAL_ROOT/shared/.env"` shows `600 <account>` for both. Evidence is required at: phase 5, the pilot activation, every `.env` change, every rollback, and every restore after a rehearsal | `600` for both, **every time**; a cache built any other way (`art config:cache` typed directly, a different umask) is regenerated with `cc` and counts as a fail to investigate. The host's default umask is not assumed safe: the cache holds the resolved `APP_KEY` and database password |
| B6 | The runtime user can read the protected cache | the A2 `--verify` table (H2) lists `configuration_cached` as agreed in all four contexts, and the web row answers | `ok` for the web, queue and scheduler: the account's own processes read a 0600 file. Later regeneration keeps the protection: after `env_restore` (I), B5 passes again |
| B7 | Disk quota | cPanel Disk Usage: record the account quota and use (the shell `quota` reports nothing useful) | the staging footprint (about 13 MB archive, about 80 MB unpacked per release) fits with a wide margin; record the numbers |
| B8 | Inodes | `df -iP "$HOME"` before and after unpacking | headroom remains; each release adds about 10,600 inodes, three retained releases about 32,000 (the account limit was measured at 300,000) |
| B9 | Account resources | cPanel Resource Usage (CloudLinux LVE), over A2 and the first hours of cron. Shell `ulimit` does not show the effective limits | zero resource faults (entry processes, NPROC, memory, CPU) attributable to the window; record the peaks against the previously measured 2 GB, 200% CPU, 30 entry processes, NPROC 200 |
| B10 | Cron concurrency: **three different counts** | (1) *configured entries*: from the refreshed crontab, `awk '/^[[:space:]]*(#\|$)/{next} /^[A-Za-z_][A-Za-z0-9_]*=/{next} {n++} END{print n+0}' "$(ev crontab.pre-g7)"` before G7 and `"$(ev crontab.after)"` after; (2) *concurrent portal cron invocations*: `cron_runs`, sampled every few seconds across at least two full five-minute cycles (including a tick that lands on a running drain, if one can be arranged), recording the **maximum**; (3) *child processes*: `procs_env "PORTAL_ROOT=$PORTAL_ROOT" \| wc -l`, recorded for diagnosis only | (1) **1** before G7 (the existing acme.sh entry only: the budget in the runbook assumes this) and **3** after (that entry plus exactly the two staging lines); anything else, stop for the owner to recompute the budget. (2) the maximum never exceeds **4** (scheduler holder, queue holder, one contender each; with acme.sh that is the reported limit of five), normally 1 to 2; every line is `queue` or `schedule`. (3) **no threshold**: one invocation is several processes (shell, `timeout`, PHP and its children), so this count is a liveness diagnostic and is never read as a number of cron jobs. Neither count proves the host's concurrency policy: that is the pending human Namecheap answer, and "no host notice appeared" is an observation, not a proof |
| B11 | **The approved symlinks, by path and owner** | `link_ok && echo link_ok`; `find "$REL" -type l -printf '%P -> %l\n' \| sort`; `find "$REL" -type l ! -user "$(id -un)" \| wc -l` | `link_ok` passes; the listing is **exactly** `.env -> ../../shared/.env`, `public/.well-known -> ../../../../portal-staging-holding/.well-known` and `storage -> ../../shared/storage` (the archive contains no link of its own); the owner count is 0. At every selection, swap and rollback |
| B12 | **The archive and its checksum are unchanged by the controlled additions** | `( cd -- "$PORTAL_ROOT/incoming" && sha256sum -c "portal-$REL_ID.tar.gz.sha256" )`; `tar -xzOf "$PORTAL_ROOT/incoming/portal-$REL_ID.tar.gz" RELEASE \| cmp - "$REL/RELEASE" && echo "RELEASE identical"`; `find "$REL" ! -type d \| wc -l`; `grep -c "release=$REL_ID " "$PORTAL_ROOT/shared/home/wellknown-link.log"` | `OK`; `RELEASE identical`; the count equals the G3 count plus **three** (the links; the G3 count itself equals the recorded one); exactly **1** log line for the release. The only differences from the archive are those links and, if recorded in `handler-carry-over.log`, the handler block |

## C. Isolation from existing resources

| # | Check | How | Pass |
|---|---|---|---|
| C1 | Database identity and privileges | `db_ok`, then `art tinker --no-ansi --execute='foreach (DB::select("show grants") as $g) echo array_values((array)$g)[0], PHP_EOL; echo implode(",", array_map(fn ($r) => array_values((array)$r)[0], DB::select("show databases"))), PHP_EOL;'` | `db_ok` names `STAGING_DB`; grants only on it; `show databases` lists `information_schema` and the staging database only |
| C2 | No other environment's values | the runbook's `grep -E` of non-secret names; `grep -cE '^(STRIPE\|GOOGLE\|MICROSOFT\|AWS\|REDIS\|SLACK\|POSTMARK\|RESEND)_' "$PORTAL_ROOT/shared/.env"` | `CACHE_PREFIX=portal_staging_`, `SESSION_COOKIE=portal_staging_session`; the count is 0 |
| C3 | **Cookie isolation from the existing portal** | (a) `curl -sI "https://$STAGING_HOST/login" \| grep -i '^set-cookie' \| sed -E 's/=[^;]*/=<value>/'`; (b) the existing portal's **public** login page, names and attributes only, no sign-in: `curl -sI https://portal.intechral.solutions/login \| grep -i '^set-cookie' \| sed -E 's/=[^;]*/=<value>/'` | (a) name `portal_staging_session`; `secure`, `httponly`, `samesite=lax`; **no `domain=`** attribute (a host-only cookie is not sent to any other host); (b) the existing portal's cookie name differs from `portal_staging_session`. **Record** whether the existing portal's cookie has a `domain=` attribute: a parent-domain cookie would also be sent to the staging host, which cannot read it (different name and key) but which the owner should know. Do not log in to the existing portal or open its contents |
| C4 | Staging data only | `art tinker --no-ansi --execute='echo DB::table("users")->count(), " user(s)", PHP_EOL;'` | only the operator account (and accounts you created for rehearsal), none a real customer |
| C5 | Mail only to the sink | every message observed in the sink; no other recipient in `jobs`/`failed_jobs` payloads | all mail lands in the owner's mailbox |
| C6 | Protected resources unchanged | `crontab -l` from your machine against the baseline taken immediately before G7 (`crontab.before`, recaptured per phase 10, never an older capture) plus the two staging lines; the existing `portal.intechral.solutions` directory and database not opened by any step; A9; `"$HOME/.acme.sh/acme.sh" --list`; `cert_check "$(ev cert.last)"` for the four `Le_` keys and the certificate against the **most recent verified state** | identical (the `MAILTO` line included, unless G7a was approved and recorded); the acme.sh entry unchanged; the diagnostic entry **absent** (the owner removed it; it is not restored); `Le_Domain`, `Le_Alt` and `Le_Webroot` unchanged; the certificate and `Le_NextRenewTimeStr` changed only by an evidenced scheduled renewal (`cert_check` reports `UNCHANGED` or an evidenced `RENEWED`) |

## D. Web and TLS

| # | Check | How | Pass |
|---|---|---|---|
| D1 | Certificate | `for n in "$STAGING_HOST" "$STAGING_ALIAS"; do echo "== $n"; curl -sv -o /dev/null "https://$n/up" 2>&1 \| grep -E '^\*  (subject\|issuer\|start date\|expire date):'; done` as the readable record, and, from your machine, `ssh "$SSH_TARGET" 'grep -hE "^(Le_Domain\|Le_Alt\|Le_Webroot\|Le_NextRenewTimeStr)=" "$HOME"/.acme.sh/portal-staging.intechral.solutions*/portal-staging.intechral.solutions.conf' \| cert_check "$(ev cert.last)"` | valid for exactly the canonical name and the `www.` alias, issued by Let's Encrypt through **acme.sh** (not AutoSSL), a chain that validates against the system trust store for each name, at least 14 days left, both names presenting the same certificate; and **the same as the most recent verified state, or an evidenced scheduled renewal**: after G5, every swap, every rollback and teardown. `UNCHANGED` or `RENEWED` passes. A new certificate with no `RENEWAL_EVIDENCE`, dates that do not fit a scheduled run, a changed hostname set, `Le_Domain`, `Le_Alt` or `Le_Webroot` is a **STOP** (explain it from the acme.sh log; never cause it); a changed issuing organisation is a `3` and waits for the owner; a changed intermediate with the same organisation is recorded and passes. The G1a record is not the comparison: it is the first entry of the history ([rules](./staging-first-install.md#certificate-state-across-renewals)) |
| D2 | Health | `curl -fsS -o /dev/null -w '%{http_code}\n' "https://$STAGING_HOST/up"` | 200 |
| D3 | Login page | `curl -s -o /dev/null -w '%{http_code}\n' "https://$STAGING_HOST/login"` | 200 |
| D4 | A built asset | take a `file` from `"$REL/public/build/manifest.json"`, then `curl -sI "https://$STAGING_HOST/build/<file>"` | 200 (proves the web server can traverse to and read `public/build`) |
| D5 | Debug off | `curl -s "https://$STAGING_HOST/no-such-route" \| head -c 300` | a generic not-found page; no stack trace, no path, no configuration |
| D6 | HTTP behaviour and the canonical name | `for n in "$STAGING_HOST" "$STAGING_ALIAS"; do for s in http https; do curl -s -o /dev/null --max-time 20 -w "$s://$n/ -> %{http_code} %{redirect_url}\n" "$s://$n/"; done; done` | **record** all four outcomes (redirect, served or refused); none is assumed. The canonical name is `$STAGING_HOST` (it is what `APP_URL`, mail links and the host-only session cookie use); `www.` is an **alias**, and whether it redirects to the canonical name is whatever this shows. An alias that serves the application directly is recorded, not corrected here: a session cookie set on one name is not sent to the other. A redirect from http to https is desirable (the secure-only cookie means no session can be established over http). A redirect that was served from `HOLD`'s `.htaccess` or a vhost setting **before** G5 may not exist afterwards: record it again after G5. Do not use a control-panel redirect that edits a release's `public/.htaccess` |
| D7 | **Effective document root, read back** | cPanel's document-root field (saved text) and, if available, the UAPI `documentroot` value, as in phase 8 step 6; and `[ -L "$PORTAL_ROOT/current" ] && readlink "$PORTAL_ROOT/current"` | the setting keeps `portal-staging/current/public` **literally** (recorded), and `current` is a symlink to `releases/<REL_ID>`. A document root resolved to a physical release path **(stop)** |
| D8 | **What the web process really serves** | the `web` row of the A2 table (release identity is the physical directory, so a context on another release is reported as `release (a different physical release directory)`), at phase 9 and again after every swap and rollback, after waiting at least `realpath_cache_ttl` (recorded at G1d; the default is 120 s) | the web context agrees with the CLI. Still the old release after **twice** the recorded `realpath_cache_ttl` **(stop)**: the document root may be pinned |
| D9 | **No directory listing under `/build/`** (and at the public root's other directories) | `b=$(mktemp); for h in "$STAGING_HOST" "$STAGING_ALIAS"; do for u in build/ build/assets/ .well-known/ .well-known/acme-challenge/; do code=$(curl -s --max-time 20 -o "$b" -w '%{http_code}' "https://$h/$u"); echo "$h/$u -> $code, $(grep -ci 'index of' "$b") listing marker(s)"; done; done; rm -f -- "$b"` (from the operator's machine) | every `/build/` row answers **403 or 404, or a 200 with 0 listing markers** (no `Index of` in the body). The host has an account-wide automatic index, and Laravel's `Options -Indexes` sits inside `<IfModule mod_negotiation.c>`, which LiteSpeed may not honour ([Hosting environment](./staging-first-install.md#hosting-environment)). A listing that names the built assets **fails**: **record it and stop for an owner decision**, never fix it by editing a served release. A listing of `/.well-known/` or its `acme-challenge/` is recorded too: it holds only challenge tokens (D10), which are public by design |
| D10 | **The ACME challenge is answered through the served release** (G5, every swap, every rollback) | `acme_probe release` with `setrel` on the served release; before it `link_ok && wk_content_ok`; for the first install also `wk_symprobe` (A12) at G1d | `RESULT=PASS (release)`: a random token under a name no real challenge uses is fetched over **http and https on both names**, redirects (if any) stay on the two names and end on https for https, the body matches **byte for byte**. A `FAIL`, or any `STOP` from the probe, **is a failed activation**: do not report G5, the swap or the rollback as successful, do not run A2, return to `portal-staging-holding` (and then `acme_probe holding` must pass). Each redirect the probe records is also D6 evidence |
| D11 | **Renewal is observed, never forced** | after the first scheduled renewal window opens (acme.sh's default is 60 days after issue, about 2026-12-21; the configured value is in `Le_NextRenewTimeStr`): the acme.sh listing and `cert_check` from D1, run with `RENEWAL_EVIDENCE` set to where you read that acme.sh ran | **Not forced, ever** (no `--renew --force`, `--issue`, AutoSSL run or change to acme.sh for this check). When a renewal happens while a release is served, `cert_check` reports `RENEWED` (new serial and dates, next-renewal time advanced, hostnames and webroot unchanged) and D1 still shows a valid certificate for both names: that is the evidence that the link carried the challenge. If the staging window ends before the first renewal, record **"not yet occurred"**: D10 is then the only evidence, and it is a probe of the path, not of a renewal |

## E. Database

| # | Check | How | Pass |
|---|---|---|---|
| E1 | Profile | `art database:verify; echo "exit=$?"` | `exit=0` with nothing pending (engine, collation, UTC strict session, REPEATABLE-READ, foreign keys against the manifest) |
| E2 | Default collation | the phase 6 `tinker` query | `utf8mb4 / utf8mb4_unicode_ci`, **or** latin1 with the accepted-fallback ruling recorded and E1 passing |
| E3 | Engine | `art tinker --no-ansi --execute='echo json_encode(DB::select("select engine, count(*) n from information_schema.TABLES where TABLE_SCHEMA = database() and TABLE_TYPE = \"BASE TABLE\" group by engine"));'` | only `InnoDB` |
| E4 | Server unchanged | no server-wide setting was modified by any step; the application's session says `+00:00` (verified by E1) | confirmed |
| E5 | The connected database was positively identified | the `db_ok` output recorded before the migration and before any destructive database step | the name equals `STAGING_DB`, and `tables: 0` before the first migration |

## F. Mail

| # | Check | How | Pass |
|---|---|---|---|
| F1 | Delivery | as the operator, invite an address you own (it should be the sink or an alias of it); watch `jobs`, then the sink | one message, within the next one or two queue ticks, sender your `MAIL_FROM_ADDRESS` |
| F2 | Links | open the message | every link host is `https://portal-staging.intechral.solutions` |
| F3 | No stray mail | the cron email recipient after a clean day | nothing from the wrapper on success |
| F4 | Failed-job visibility | `art tinker --no-ansi --execute='echo DB::table("failed_jobs")->count();'` after the SMTP rehearsal (I9) | a `failed_jobs` row exists for the broken send. **Known gap:** nothing alerts on `failed_jobs` (the worker still exits 0); it is checked by reading, as recorded in [Cron Execution](../architecture/cron-execution.md#not-covered) |

## G. Cron scheduling and cron email

| # | Check | How | Pass |
|---|---|---|---|
| G1 | Exactly two entries added | `crontab -l` before and after, diffed from your machine | the two lines the runbook printed, verbatim, with the verified absolute `PORTAL_ROOT`; every other entry unchanged |
| G2 | Ticks happen | `tail -n 6 "$PORTAL_ROOT/shared/logs/cron-schedule.log" "$PORTAL_ROOT/shared/logs/cron-queue.log"` after 15 minutes | `START … slot_offset_s=…` then `END status=ok category=ok exit=0` for both tasks, five minutes apart (the queue two minutes after the scheduler) |
| G3 | Silent on success | the cron email recipient | no message for any successful tick |
| G4 | Files | `stat -c '%a %n' "$PORTAL_ROOT"/shared/run "$PORTAL_ROOT"/shared/logs "$PORTAL_ROOT"/shared/run/* "$PORTAL_ROOT"/shared/logs/*` | directories 700; every file 600 |
| G5 | Same release | the `release=` field of recent `START` lines | equals `REL_ID` |
| G6 | **Cron email state** | the phase 0 record (a `MAILTO` line present or not; monitored or not; which existing entries can mail, from reading the entries privately: the `awk` output is only a heuristic) and, after install, the diff against `crontab.before` (all `crontab.*` files live in the private evidence directory, outside the repository) | `MAILTO` unchanged (preferred), **or** changed under an approved G7a with the previous state recorded and the diff showing only that line. The existing entries' notification behaviour is unchanged: their lines are byte-identical |
| G7 | Notices reach someone | the A3 refusal rehearsal (I1) sends one notice per task | it arrives at the recipient the owner reads |

## H. A1, A2, A3 and the wrapper: the evidence for a pass

| # | Requirement | Evidence |
|---|---|---|
| H1 | **A1**, the production profile holds in the CLI | `art runtime:preflight` exits 0 and the diagnostics block shows `app.env production`, `cache.default database`, `queue.default database`, `queue.retry_after 300`, `session.driver database`, `mail.default smtp`, `configuration_cached true`, the staging `app.url` and `cache.prefix portal_staging_`; afterwards no `runtime-preflight` row remains in `cache` or `cache_locks` |
| H2 | **A2**, four contexts agree, and the web is on PHP 8.5.x | `art runtime:preflight --verify --challenge="$CHALLENGE"` exits 0 after `--issue`, `curl /up`, **a wrapper-launched** `queue` and `schedule` ([runbook phase 9](./staging-first-install.md#phase-9-the-wrapper-by-hand-then-a2-g6)); then **repeated with the real cron entries answering** (`--issue`, `curl /up`, wait at most ten minutes, `--verify`; inside the 30-minute window). Output: `Every context answered the challenge and agrees: CLI, web, queue and scheduler share the configuration, the release and the APP_KEY.` and four `ok` rows, **the `web` row showing `php 8.5.x`** (A8). The queue held only what you expected |
| H3 | A2 is repeated after every swap and rollback, and catches a stale release (negative control) | after each release switch and each rollback (I13): wait `realpath_cache_ttl`, then `--issue` … `--verify`. Before the web process has switched, a context still on the previous release makes `--verify` report `release (a different physical release directory)`; after the correct sequence it passes with the `web` row on the expected release **and** PHP 8.5.x |
| H4 | **A3**, refusal | see I1 and I2: each guarded command exits 1 with the rule named, no job reserved, no scheduled task run |
| H5 | **B1**, normal operation | G2 to G5 hold for the observation window; locks and logs behave (I4 to I8) |

## I. Failure and operational rehearsals (needs gate G8)

Each row breaks one thing deliberately, observes, and **restores**. G8 is approved knowing that I1 to I3 and I9 affect the **real** staging cron entries and send real notices to the account's `MAILTO` recipient. Everything else uses a **rehearsal root** that has its own
locks, holder records, alert state and logs, runs PHP stand-ins instead of a real worker, and never touches the real cron state. Set up once (after the session setup; nothing here kills a process by name or from a stored PID):

```bash
RH="$PORTAL_ROOT/rehearsal"
( set -u; root_ok || exit 1
  mkdir -p -m 0700 -- "$RH" || exit 1
  umask 077
  mkdir -p -- "$RH/shared/run" "$RH/shared/logs" || exit 1
  printf '#!/bin/sh\nexit 0\n' > "$RH/ok.sh" || exit 1                                                                                 # a PHP stand-in that succeeds
  printf '#!/bin/sh\ntrap "" TERM\ni=0; while [ $i -lt 40 ]; do sleep 1; i=$((i + 1)); done\n' > "$RH/stub.sh" || exit 1                 # ignores TERM, but ends by itself after 40 s
  printf '#!/bin/sh\necho "@@PORTAL-CRON-FAILURE@@ v1 category=scheduler class=Exception exit=1"\nexit 0\n' > "$RH/marker.sh" || exit 1   # prints one valid marker line
  chmod 700 "$RH/ok.sh" "$RH/stub.sh" "$RH/marker.sh" || exit 1 )
rh_ok() { root_ok && [[ "${RH:-}" == "$PORTAL_ROOT/rehearsal" && -d "$RH/shared/run" && ! -L "$RH" ]] || { echo "RH is not the rehearsal root: STOP" >&2; return 1; }; }
rwrap() { ( rh_ok && [[ -L "$PORTAL_ROOT/current" && ( "${1:-}" == queue || "${1:-}" == schedule ) ]] || exit 1
            task=$1; shift; exec env -i PATH=/usr/bin:/bin PORTAL_ROOT="$RH" "$@" /bin/sh "$PORTAL_ROOT/current/scripts/cron/portal-cron.sh" "$task" ); }
rtest() { rh_ok || return 1; local task=$1 stub=$2; shift 2; rwrap "$task" PORTAL_CRON_TEST=1 PORTAL_PHP="$RH/$stub" "$@"; }       # rtest queue|schedule ok.sh|stub.sh|marker.sh [PORTAL_CRON_X=value ...]
rh_pids() { rh_ok && procs_env "PORTAL_ROOT=$RH"; }                  # only your account's processes whose environment names EXACTLY this rehearsal root
rh_wait() { rh_ok || return 1; local i; for i in $(seq 1 75); do [[ -z "$(rh_pids)" ]] && return 0; sleep 1; done
            echo "rehearsal processes still running after 75 s: do NOT kill anything by name; stop and report" >&2; return 1; }
rh_kill_wrapper() {                                                   # the one deliberate kill (I8): a single validated wrapper shell of the rehearsal root
  rh_ok || return 1; local p c=0 target=
  for p in $(rh_pids); do tr '\0' ' ' 2>/dev/null < "/proc/$p/cmdline" | grep -qF 'scripts/cron/portal-cron.sh' && { target=$p; c=$((c + 1)); }; done
  [[ $c -eq 1 ]] || { echo "expected exactly one rehearsal wrapper, found $c: killing nothing" >&2; return 1; }
  rh_pids | grep -qx -- "$target" || { echo "the PID no longer validates: killing nothing" >&2; return 1; }      # re-validated immediately before the kill (PID reuse)
  kill -9 -- "$target"
}
rclean() { rh_ok && [[ -z "$(rh_pids)" ]] || { echo "rehearsal processes still running: not cleaning" >&2; return 1; }; rm -f -- "${RH:?}"/shared/run/* "${RH:?}"/shared/logs/*; }
# staging configuration breaks (I1 to I3, I9): a verified-good copy first, one value at a time, and a restore that rebuilds and re-verifies
env_backup()  { env_ok && cp -p -- "$PORTAL_ROOT/shared/.env" "$PORTAL_ROOT/shared/.env.good" && [[ "$(stat -c %a -- "$PORTAL_ROOT/shared/.env.good")" == 600 ]] && echo ".env.good saved (0600)"; }
env_set()     { env_ok && [[ "$1" =~ ^[A-Z][A-Z0-9_]*$ && "$2" =~ ^[A-Za-z0-9_.:/@-]*$ ]] && sed -i "s|^$1=.*|$1=$2|" "$PORTAL_ROOT/shared/.env" && env_ok; }
env_restore() { env_ok && [[ -f "$PORTAL_ROOT/shared/.env.good" ]] && cp -p -- "$PORTAL_ROOT/shared/.env.good" "$PORTAL_ROOT/shared/.env" && env_ok && cc && art runtime:preflight; }
```

How the process safety works: a rehearsal process is identified by an **exact environment line** (`PORTAL_ROOT=<rehearsal root>`, which only `rwrap` and its children carry), restricted to your own account's `/proc` entries; the real cron processes carry a different line,
and nothing else on the account carries either. There is no `pkill`, no match on `php` or `timeout`, and no PID is ever read from a holder record (those persist after a run and say nothing about liveness). The stand-ins end by themselves within 40 seconds, so a normal run needs no
kill at all; `rh_wait` only waits. The single kill (I8) re-validates its one target immediately before use, which also covers PID reuse. If `/proc` environments are not readable on the host, `rh_pids` finds nothing and `rh_kill_wrapper` refuses: record I6 to I8 as **deferred**
(their behaviour is covered in CI by `tests/Unit/Cron/PortalCronWrapperTest.php`) rather than improvising a kill.
Before I1 to I3 and I9: `art runtime:preflight` passes, then `env_backup`.

| # | Test | Set-up | Expected | Restore |
|---|---|---|---|---|
| I1 | **Invalid production configuration (A3), real cron ticks** | `env_set MAIL_MAILER log && cc` | the next scheduler and queue ticks are refused: logs show `END … category=profile-refused`; **exactly one** cron email per task naming the category (no host, key or secret); the next ticks log `ALERT suppressed` and send nothing; the application log has one `critical` entry; no job reserved | `env_restore` (copies the good file back, `cc` with its mode check, `runtime:preflight`); on the next tick **one** `RECOVERED` email per task |
| I2 | **Missing configuration cache** | `art config:clear` | same refusal (an uncached production configuration is itself a violation); `/up` still answers | `cc` and `art runtime:preflight` |
| I3 | **Database unavailable (staging only)** | `env_set DB_HOST 127.0.0.1 && env_set DB_PORT 1 && cc` (a closed TCP port; `localhost` would use the socket and ignore the port). The shared MariaDB server and every other database are untouched | worker and scheduler fail loudly: `category=database` or `php-exit` with a SQLSTATE, one email each, repeats suppressed | `env_restore`; `RECOVERED` |
| I4 | **Lock contention** | `( umask 077; exec flock -n -x "$RH/shared/run/queue.lock" sleep 60 ) & HOLD=$!`, `sleep 1`, then confirm `lock_free "$RH/shared/run/queue.lock"` is **false** and run `rtest queue ok.sh` | the wrapper prints nothing, exits 0, logs `SKIP lock held`, and starts nothing | none yet: leave the holder for I5, which must start within about 30 s of I4 |
| I5 | **Stale holder record** | **precondition:** `lock_free "$RH/shared/run/queue.lock"` is still false (the I4 holder has 60 s in total; if the lock is free, redo I4). Then `echo "99999 1 oldrelease" > "$RH/shared/run/queue.holder"` and `rtest queue ok.sh` | `category=lock-stale`, exit 75, one notice (printed to your terminal: this is a hand run, not cron) | `wait "$HOLD"` (the holder ends by itself at 60 s; nothing is killed), confirm `lock_free` is true, then `rclean`. If the session is abandoned, the holder still ends by itself within 60 s |
| I6 | **No overlap** | `rtest queue stub.sh PORTAL_CRON_QUEUE_TERM=60 PORTAL_CRON_KILL_AFTER=5 PORTAL_CRON_QUEUE_MAX_TIME=1 PORTAL_CRON_QUEUE_JOB_TIMEOUT=1 & BG=$!`, `sleep 2`, then `rtest queue ok.sh`. First `rh_pids \| wc -l` should be 3 or more; if it is 0, `/proc` is not usable and I6 to I8 are deferred | the second run logs `SKIP lock held` and exits 0; the first run's processes (its `timeout`, the stub and the wrapper shell) are the only ones listed by `rh_pids`, no second worker | `rh_wait` then `wait "$BG"` (the stub ends by itself); `rclean` |
| I7 | **External timeout and kill** | `rtest queue stub.sh PORTAL_CRON_QUEUE_TERM=6 PORTAL_CRON_KILL_AFTER=3 PORTAL_CRON_QUEUE_MAX_TIME=1 PORTAL_CRON_QUEUE_JOB_TIMEOUT=1` | after about 9 s: status 137, `category=timeout … killed_after_term=yes`, exit 137, one notice on your terminal; `rh_wait` returns 0 (no rehearsal process left; allow a second for a trailing `sleep`); `lock_free "$RH/shared/run/queue.lock"` is true | `rclean` |
| I8 | **Force-kill cleanup** | start the I6 first command, then `rh_kill_wrapper` (it kills only the wrapper's own shell, after validating it) | the lock stays held by the surviving children (conservative, by design): `lock_free` is false; the log shows a `START` with no `END`; after `rh_wait` (the stand-in ends by itself) the lock is free | `wait "$BG" 2>/dev/null`; `rclean` |
| I9 | **SMTP failure to a controlled sink** | `env_set MAIL_HOST 127.0.0.1 && env_set MAIL_PORT 1 && cc`, then invite an address you own | the send fails fast; the job is retried with backoff (60 s, then 180 s, rounded up to ticks) and after three attempts is in `failed_jobs`; no overlapping connections; the wrapper reports success (a failed job is not a process failure: F4) | `env_restore`; `art queue:retry all` only if you want the message sent; clear the rehearsal rows |
| I10 | **Application marker classified by the wrapper** | `rtest schedule marker.sh` | `category=scheduler`, exit 90, one notice carrying only the class and exit status. (A real failing scheduled task cannot be produced without changing code; the application's emission of the marker is covered in CI by `tests/Unit/Cron/PortalCronApplicationTest.php`.) | `rclean` |
| I11 | **Log rotation and modes** | `rtest schedule marker.sh PORTAL_CRON_LOG_MAX_BYTES=2000`, repeated until the log passes 2,000 bytes | `cron-schedule.log` rotates to `.1`; both files 600; one older generation only | `rclean` |
| I12 | **Maintenance mode is inert** (observation) | the block below, which refuses to run the worker or the scheduler unless `/login` returns **HTTP 503** | `/up` 200; `/login` 503; both wrapper runs exit 0 and log `END status=ok` **without processing a job or running a scheduled task** (Laravel pauses the worker and skips events in maintenance). The point is to know that `ok` is not evidence of work during maintenance. If `/login` is not 503 the block runs neither | the block always runs `art up`; then `curl -s -o /dev/null -w '%{http_code}\n' "https://$STAGING_HOST/login"` is 200, and `rclean` |
| I13 | **Release switch and rollback** | build a second release of the same commit: re-run [the build](./release-build.md) from step 0 (a new id and a new directory every time), upload and unpack it, `setrel <new id>`, link `.env` and `storage` and add the ACME link (`wk_link`; `link_ok` and `wk_content_ok` must pass before the swap), `cc`, `db_ok && art migrate --force`, `database:verify`, `runtime:preflight`, `roles:sync` (dry run), the handler carry-over (`htaccess_apply` while it is unselected, then `htaccess_ok`), then start `rtest queue stub.sh PORTAL_CRON_QUEUE_TERM=60 PORTAL_CRON_KILL_AFTER=5 PORTAL_CRON_QUEUE_MAX_TIME=1 PORTAL_CRON_QUEUE_JOB_TIMEOUT=1 &` (rehearsal state, not the real one), swap, `queue:restart`, `permission:cache-reset`, wait, A2 | the running invocation's `START` names the old release and it finishes on it; a following `rtest queue ok.sh` is skipped on the shared lock while it runs, then records the new `release=`; `htaccess_ok` passes after the swap and A2 passes on the new release with the `web` row on PHP 8.5.x (A8, H3); `acme_probe release` passes on the new release (D10) and the certificate dates are unchanged (D1); then swap back (the returned-to release first passes `link_ok && wk_content_ok`), `queue:restart`, wait, A2 passes again **and `acme_probe release` passes again on it**, with `cc` used wherever the configuration is rebuilt | leave the previous release in place at least 15 minutes after each swap; `rh_wait`; `rclean` |
| I14 | **Release retention** | before removing the older release, the runbook's retention check (`procs_cmd` on the old release's exact path, both locks free) | prints its last line: nothing runs from it, the locks are free, 15 minutes have passed, and it is not the rollback target | none |

**I12.** Maintenance mode is **not** part of the normal first-install sequence; it is a deliberate, short observation in the rehearsal root (so the real logs and locks are untouched). The precondition is explicit: the real worker and scheduler are only invoked after `/login` has been
seen to answer 503.

```bash
( set -u
  rh_ok || exit 1
  trap 'art up >/dev/null 2>&1' EXIT                       # the site is always brought back, whatever happens below
  art down || exit 1
  code=$(curl -s -o /dev/null -w '%{http_code}' "https://$STAGING_HOST/login")
  [[ "$code" == 503 ]] || { echo "/login answered $code, not 503: maintenance is not in effect: refusing to run the worker or the scheduler" >&2; exit 1; }
  curl -fsS -o /dev/null "https://$STAGING_HOST/up" || { echo "/up is not answering: STOP" >&2; exit 1; }
  rwrap queue;    echo "queue exit=$?"
  rwrap schedule; echo "schedule exit=$?" )
```

## J. The 02:00 UTC scheduler risk

`tickets:auto-close` is `dailyAt('02:00')` in the application's timezone (UTC). The task runs only if a scheduler tick **boots inside the 02:00 UTC minute**. The host's cron is not perfectly punctual (sixteen earlier samples
spaced 269 to 339 seconds apart), and sixteen samples do not bound the delay. The wrapper records `slot_offset_s` (seconds into the tick's nominal five-minute slot; the queue slot starts at minute 2) in every `START` line.

**Collect** (read-only, from the host; the logs rotate at 512 KB, about nine days of one task at roughly 190 bytes per tick, so copy them off weekly):

```bash
L=$PORTAL_ROOT/shared/logs
cat "$L"/cron-schedule.log.1 "$L"/cron-schedule.log 2>/dev/null | grep -E ' schedule pid=[0-9]+ START ' \
  | sed -E 's/^([^ ]+) .*slot_offset_s=([0-9]+)$/\1 \2/' > pairs.txt        # run in a scratch directory outside $PORTAL_ROOT
echo "--- start offsets"; cut -d' ' -f2 pairs.txt | sort -n | awk '{ a[NR] = $1 }
  function pct(p,  i) { i = int(p * NR); if (i < p * NR) i++; if (i < 1) i = 1; return a[i] }
  END { if (NR == 0) { print "no START records"; exit } printf "n=%d min=%d p50=%d p95=%d p99=%d max=%d\n", NR, a[1], pct(0.5), pct(0.95), pct(0.99), a[NR] }'
echo "--- ticks 50 s or more into the slot";  awk '$2 >= 50' pairs.txt | wc -l
echo "--- gaps over 600 s between consecutive starts"
cut -d' ' -f1 pairs.txt | date -u -f - +%s | awk 'NR > 1 { g = $1 - p; if (g > 600) print "GAP", g, "s before epoch", $1 } { p = $1 }'
echo "--- the 02:00 UTC ticks"; grep -E '^[0-9-]+T02:0[0-4]:' pairs.txt
echo "--- scheduler skips"; cat "$L"/cron-schedule.log.1 "$L"/cron-schedule.log 2>/dev/null | grep -c ' SKIP '
```

Report the ticks that start on minute :00 of the hour separately from the rest (many accounts schedule on the hour, so that slot may behave differently) and the 02:00 UTC slot on its own: it is one observation per day.

**Window.** Collect for at least 24 hours to see any data, and the recommended minimum before the pilot is **seven consecutive UTC days** (about 2,000 ticks per task and seven occurrences of the 02:00 slot). The owner sets the
window. **Whatever its length, it cannot prove daily-task reliability under every future host condition**; it measures what this host did while it was watched.

**Evidence that justifies asking the owner to rule on a once-per-UTC-day catch-up** (any one of the first four; the fifth is advisory):

1. a scheduler tick whose `slot_offset_s` plus about one second of boot reaches 60 seconds or more, on the 02:00 UTC slot, which is a missed task by definition;
2. any `START` at 50 seconds or more into its slot on any tick (the margin that matters), more than a few times in the window;
3. a gap of more than 600 seconds between consecutive scheduler starts (a skipped tick);
4. a scheduler `SKIP` or `lock-stale` around 02:00 UTC, or a day on which `tickets:auto-close` demonstrably did not run;
5. offsets that grow across the window, or a p99 above 30 seconds.

Nothing here implements a catch-up; it needs a new owner ruling (**G12**) and is a separate package.

## K. Teardown check

After a **temporary** [teardown (G9)](./staging-first-install.md#rollback-and-teardown), in its order: the staging cron lines are gone and `crontab -l` (from your machine) equals the **fresh G7 baseline** (`crontab.before`, never an older capture), **the `MAILTO` line included**, so the removed diagnostic entry is still absent
and the owner's current cron email is as the owner set it (if a separately approved G7a changed it, it was reversed first, from `crontab.pre-g7a`, and the diff is against that file); the staging hostname's document root is **`portal-staging-holding` again** (detached before any file was removed), the subdomain, the `www.` alias, the certificate and `HOLD` are **still in place**, and `acme_probe holding` passes (D10) with `cert_check` passing against the most recent verified state (D1: `UNCHANGED`, or an evidenced scheduled `RENEWED`);
the staging database, user and mailbox are gone; `$PORTAL_ROOT` does not exist and `wk_content_ok` still passes (removing the releases did not reach `HOLD`); the protected resources (the existing `portal.intechral.solutions` directory and database, the acme.sh entry, and the acme.sh listing with `Le_Domain`, `Le_Alt` and `Le_Webroot`) are unchanged;
**the existing portal's PHP version and handler equal the baseline recorded at G1d** (A9); the staging `APP_KEY`, database password and local `.env.staging` are discarded.

After a **permanent retirement (G9b)**, which needs its own owner decision: the exact certificate was identified first and its fate recorded as the owner decided (it was not removed by an automatic step); the subdomain and alias are deleted by exact name, after the certificate registration was dealt with; `HOLD` is removed only if the owner inspected it; the acme.sh cron entry and every other
certificate's registration and the `MAILTO` setting are unchanged.

## L. The separate survival test (gate G11)

**Not part of this checklist and not authorised by it.** The deliberate long-duration test of a queue process near 270 seconds needs all of:

- the **human** Namecheap answer to the question in the runbook, recorded, saying a cron-launched PHP CLI process of that length every five minutes is acceptable and that no shorter hard limit exists;
- the cron budget accepted again with the survival test in mind (the diagnostic entry is gone, so the worst case is five, which is the reported limit, and long-lived holders make it tight: [the runbook's slot budget](./staging-first-install.md#phase-10-install-the-two-cron-entries-g7));
- every other section of this checklist passed;
- **explicit owner approval** for the test itself. Permission for staging, for the install, or for any section above is not permission for it.

If the host rejects the duration, the smallest alternative is described in the runbook; it is a separate package. The worker timings are not changed here.

## Evidence record

For each row above record: the result (pass, fail, not run), the command output **with secrets removed**, the UTC time, and the release id. Keep the record, and the `crontab.*` and `php-vhosts.*` files, in the private evidence directory (`$HOME/portal-staging-evidence`, mode 700, outside every repository, web root and release; the runbook's `ev` writes there by absolute path) because they contain host details, for example in the owner's
password-manager notes or a private folder, and quote only the pass/fail table in a ticket.

| Always capture | Never capture |
|---|---|
| the archive SHA-256, entry count, `.htaccess` hash, `RELEASE`; the MultiPHP baseline rows (the existing portal's row and "others unchanged: yes/no"); the G1d probe and `wk_symprobe` output; the G1a read-back (certificate subject, issuer and dates for both names, `acme.sh --list`, the four `Le_` keys, `ls -la` of `HOLD` and its `.well-known`); every `acme_probe` result; the `link_ok` and B11 listing for each release; the document-root read-back text; `crontab.before`/`pre-g7`/`after` diff (private files, never pasted); the `MAILTO` state as present/absent and monitored/not, never the address; `cc` output and the `stat` lines (B5); `db_ok` output; `art runtime:preflight` diagnostics; the A2 `--verify` output; wrapper log lines (`START`, `END`, `SKIP`, `ALERT`); the first sink message headers; cPanel Resource Usage peaks | `shared/.env` or any `APP_KEY`, `DB_PASSWORD`, `MAIL_PASSWORD`; the operator password; cookie values; the wrapper's captured-output lines (the ones prefixed with a vertical bar; application output, possibly sensitive); the contents of the protected resources |

| Section | Result | Date (UTC) | Evidence location |
|---|---|---|---|
| A Host and PHP (CLI before G1; web at G1d) | | | |
| B Filesystem, quota, resources | | | |
| C Isolation | | | |
| D Web and TLS | | | |
| E Database | | | |
| F Mail | | | |
| G Cron scheduling | | | |
| H A1, A2, A3, B1 | | | |
| I Rehearsals | | | |
| J Scheduler observation (window: ___ days) | | | |
| K Teardown | | | |
| L Survival test | **not authorised** | | |
