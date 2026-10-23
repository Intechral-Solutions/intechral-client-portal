# Staging First Install: the Authoritative Procedure (Release 0.9, WP3-B2)

**Authority:** the Release 0.9 shared-hosting runtime gate and the WP3-B2 owner rulings. **Companions:** [Release Build](./release-build.md) (the archive this installs),
[Staging Acceptance](./staging-acceptance.md) (what must pass afterwards), [Cron Execution](../architecture/cron-execution.md), [Runtime Preflight](../architecture/runtime-preflight.md),
[Database Profile](../architecture/database-profile.md), [Customer Pilot Surface](../architecture/customer-pilot-surface.md) (section 10), [Role Governance](../architecture/role-governance.md).

This is the one procedure for installing the first isolated **staging** copy of the portal on the Namecheap Stellar Plus account. Where the earlier documents each gave a partial order, this
one is authoritative, and they now point here ([why the order differs](#why-this-order)).

> **Status: documentation only. Nothing in it authorises a host change.** Every step that changes the host is behind a numbered gate (G1 to G12, below) that the owner must open **explicitly and
> separately**. Reading, building and rehearsing on a development machine need no gate. Do not infer a gate from a successful earlier step. The owner has already configured some resources
> ahead of the gate order ([Current state](#current-state-owner-reported-2026-10-22)); that is a record of the host, not a pass of any gate's observation steps.
>
> **Host facts this procedure does not assume.** None of the following is resolved by this document; each is a stop condition or a gate below: human Namecheap confirmation of the roughly 270-second queue process
> lifetime ([Host support dependency](#host-support-dependency)); the web PHP, its extensions and its handler for the staging hostname (G1d, not yet performed); whether this web server follows the `.well-known`
> symlink ([G1d](#phase-1-staging-resources-g1-each-sub-gate-separate), not yet performed); what cPanel does with the document root and with a PHP handler; a mail sink; the staging database default collation.
> The worker timings are unchanged.

## Protected resources and boundaries

The account already holds resources that this procedure must never touch, inspect more than a name-level listing requires, reuse the credentials of, or treat as disposable:

- the existing **`portal.intechral.solutions`** site: its directory, its database and user, **and its PHP version and handler**. Any change to that site's runtime at any point is an **immediate stop**;
- the existing **acme.sh** installation and its **account-wide renewal cron entry**. They now also manage the staging certificate ([TLS and ACME](#tls-and-acme-acmesh)). The entry, the acme.sh configuration and
  the certificate are **never modified, re-issued or force-renewed** by this procedure, and its ACME webroot (`portal-staging-holding`) is never moved;
- the account-wide **cron email** (`MAILTO`) setting, which the owner has configured to a monitored address. It is read, never changed, and never "restored" to an older value by teardown (G7a is the only way to change it);
- every other site, database, backup and mailbox, the account-wide CloudLinux PHP selector (the "Select PHP Version" extension and option toggles apply to the whole account), and the shared MariaDB server's settings
  (no server-wide default is ever changed).

The WP3-0B diagnostic cron entry was **removed by the owner** (2026-10-22). It is not a protected resource any more, and no step here restores it ([G10](#gates-and-approvals)).

Staging has its own hostname, database and database user, storage, `APP_KEY`, cache prefix, session cookie and outbound mail path. It holds no production customer data, no live payment or other
integration credential, and sends mail only to an owner-controlled mailbox.

## Current state (owner-reported, 2026-10-22)

The owner performed part of G1a, and several host checks, **before** this procedure's gates were opened. These are the owner's reports and a read-only look from a development machine. Nothing in
the right-hand column has been observed or passed by this procedure; every observation step in the procedure still applies, and an acceptance row stays "not run" until its own command has been run and recorded.

| Fact | Source | Verified by this procedure |
|---|---|---|
| The hostname `portal-staging.intechral.solutions` and the alias `www.portal-staging.intechral.solutions` exist, and the hostname was confirmed available | owner; DNS answers for both from a development machine | G1a observation (below) not yet run |
| Temporary document root `$HOME/portal-staging-holding`; the future document root is `$HOME/portal-staging/current/public`. The holding root was seen serving an owner-written `index.html` | owner; read-only look | the cPanel field and the directory listing not yet read back |
| TLS: a Let's Encrypt certificate for both names, issued and installed by **acme.sh 3.1.6** (HTTP-01, `cpanel_uapi` deploy hook); both names use `portal-staging-holding` as their ACME webroot | owner | `acme.sh --list` read-back and `curl` certificate dates not yet recorded |
| The PHP 8.5 CLI has all 20 required extensions (the 17 from `RELEASE`, plus `pdo_mysql`, `pcntl`, `posix`) | owner | phase 0 command not yet recorded here |
| The existing portal still runs PHP 8.5 | owner | the G1d baseline file does not yet exist |
| The diagnostic cron entry is removed; the account-wide cron email is a monitored address | owner | **recapture the crontab** (phase 0 step 3); do not rely on any earlier capture |
| Account disk use is about 2.1 GB, with no reported quota limit | owner | cPanel Disk Usage (B7) not yet recorded |
| An observed `http` to `https` 301 on the holding root | read-only look | its source is unknown (a vhost setting, or an `.htaccess` in the holding root, which G1d step 1 treats as a stop for owner review) |
| The web server is **LiteSpeed** | `Server` header, read-only look | see [Hosting environment](#hosting-environment) |
| The web PHP version and extensions, the `.well-known` symlink behaviour, the document-root read-back, any application check | nobody | **G1d, G5 and acceptance: not performed** |
| The 270-second process lifetime (human Namecheap answer) | pending | gates only the survival test (G11) |

## Hosting environment

The staging host is a Namecheap Stellar Plus account on **cPanel with CloudLinux**, and its web server is **LiteSpeed** (Apache-compatible: it reads `.htaccess` and honours `mod_rewrite` rules), not Apache. What follows is
what this procedure relies on and what it does not.

- **Symlinks.** LiteSpeed's symlink policy (follow always, or only when the link and its target have the same owner) is a server setting this procedure does not read. Nothing here assumes a link is followed:
  the `.well-known` link is **probed** before a release exists (G1d) and its effect is **observed** through a served release (G5).
- **Directory listings.** An account-wide automatic index was observed to be on. Laravel's `public/.htaccess` sets `Options -Indexes` only inside `<IfModule mod_negotiation.c>`, which may not apply on LiteSpeed.
  Directory-listing exposure under `/build/` is therefore an acceptance check, not an assumption ([D9](./staging-acceptance.md#d-web-and-tls)).
- **Handler block.** cPanel's MultiPHP writes the PHP handler into an `.htaccess`, if it writes a file at all. Which form it takes under LiteSpeed is observed at G1d; `htaccess_check` accepts one bounded
  `AddHandler`/`AddType` block naming `php85`, and anything else is an owner review.
- **One account user** runs the web server's PHP, cron and SSH, so file ownership checks compare against `id -un`.

## Names used here

| Name | Meaning | Value |
|---|---|---|
| `STAGING_HOST` | the **canonical** staging hostname (the one `APP_URL`, mail links and the session cookie use) | `portal-staging.intechral.solutions` |
| `STAGING_ALIAS` | an alias of the same site, **not canonical**. Whether it redirects to the canonical name is observed, never assumed ([D6](./staging-acceptance.md#d-web-and-tls)) | `www.portal-staging.intechral.solutions` |
| `HOLD` | the temporary document root, **also the ACME webroot of both names**. It is never deleted by a temporary rollback ([Teardown](#rollback-and-teardown)) | `$(readlink -f "$HOME")/portal-staging-holding` |
| `PORTAL_ROOT` | the deployment root, resolved through any home-directory symlink; the cron lines use this exact string | `$(readlink -f "$HOME")/portal-staging` (must still be free at G2: checked, not assumed) |
| `REL_ID` | `yyyymmddHHMMSS-<sha7>`, from the [build](./release-build.md#release-identity) | set per release |
| `STAGING_DB` / `<STAGING_USER>` | the new database and its user (cPanel prefixes both) | not chosen; **never** `intechral_client_portal_testing`, never an existing name |
| `OPERATOR_EMAIL` | an owner-controlled address for the first operator | |
| `PHP` | `/opt/alt/php85/usr/bin/php` | `/usr/bin/php` is a php-cgi entry point: never use it |

No real value of any of them (password, key, token) is ever written into this repository, a ticket, or a chat.

## Topology

```
$HOME/
  portal.intechral.solutions/        EXISTING. Not touched.
  portal-staging-holding/            EXISTS (the owner's G1a): the temporary document root AND the ACME webroot of both names (see "Why this order", item 6). Kept after a temporary rollback.
    .well-known/acme-challenge/      written by acme.sh; the only thing the release links expose
  portal-staging/                    $PORTAL_ROOT, 0755, created at G2
    incoming/             0700       uploaded archives and the .env, staged before they are moved into place
    releases/             0755
      <REL_ID>/           0755       immutable once unpacked. The archive's files are never modified, with ONE recorded exception: the reviewed PHP handler block appended to public/.htaccess BEFORE the release is selected (phase 8).
                                     Three symlinks are ADDED (never overwriting anything) before it is selected, and recorded: .env, storage and public/.well-known (phase 4)
        scripts/cron/portal-cron.sh  0755
        bootstrap/cache/config.php   written once, before the release is selected; mode 0600 (it holds the resolved APP_KEY and database password)
        public/                      the web document root (through the current link)
          .well-known -> ../../../../portal-staging-holding/.well-known    SYMLINK to the shared ACME directory; relative, so it resolves from the physical release (four levels up is $HOME)
        .env -> ../../shared/.env, storage -> ../../shared/storage     SYMLINKS, never copies
    current -> releases/<REL_ID>      a SYMLINK, replaced only with `mv -T`; never a directory
    shared/               0700
      .env                0600       the only private configuration; created by the owner, never committed
      .env.good           0600       the verified-good copy kept for the deliberate configuration-break rehearsals only
      home/               0700       HOME for administrative artisan runs; also htaccess.baseline, php-handler.block and handler-carry-over.log (phase 8), and wellknown-link.log (phase 4)
      storage/            0700       app/{private,public}, framework/{cache/data,sessions,views}, logs   (Laravel's application log)
      run/                0700       cron locks, holder and alert state, written by the wrapper
      logs/               0700       cron-schedule.log, cron-queue.log (+ .1), written by the wrapper
    rehearsal/            0700       acceptance rehearsals only: its own shared/{run,logs}, so tests never touch the real cron state
```

The web document root is `$PORTAL_ROOT/current/public`. The cron entries call `$PORTAL_ROOT/current/scripts/cron/portal-cron.sh` with `PORTAL_ROOT` set; the wrapper resolves the physical release once and runs
everything from it. Attachments live on the private `local` disk (`shared/storage/app/private`), nothing is served from storage, and there is **no `storage:link`**.

| Boundary | Staging | Must differ from |
|---|---|---|
| Hostname, TLS certificate | `portal-staging.intechral.solutions` (canonical) and its `www.` alias; one acme.sh-managed Let's Encrypt certificate for exactly those two names | `portal.intechral.solutions` and every other name |
| ACME challenge path | the shared `portal-staging-holding/.well-known`, reached from each release through its `public/.well-known` link | the release's own files: nothing else of `HOLD` is ever linked into a release |
| Web PHP | a **per-domain** setting for the staging hostname only | the existing portal's version and handler, and the account-wide selector |
| Database, user, privileges | new, this database only | the existing `portal*` database and its user |
| Files | `$PORTAL_ROOT` | `portal.intechral.solutions/` and everything else under `$HOME` |
| `APP_KEY` | generated for staging, discarded at teardown | any other environment |
| `CACHE_PREFIX`, `SESSION_COOKIE`, `SESSION_DOMAIN` | `portal_staging_`, `portal_staging_session`, unset (host-only cookie) | every other environment, including the existing portal's cookie name |
| Mail | an owner-controlled mailbox only | any customer address |
| Cron | two entries, identified by `PORTAL_ROOT=…/portal-staging` in the command | the acme.sh entry, anything else; the account-wide `MAILTO` |

## Gates and approvals

Each gate is a separate owner decision. A gate opens one group of host changes and nothing else; a failure inside it stops at that gate. Every host-changing step below names its exact target, the evidence that
proves it, the stop condition, and how to undo it.

| Gate | Opens | Host changes |
|---|---|---|
| G0 | Owner rulings recorded: database and user names, mail sink, the existing `MAILTO` state (a monitored address is reported; record it), operator address, the support question sent. Includes the read-only host check (phase 0) and a fresh crontab capture | none (read-only SSH) |
| G1 | Staging resources, **each approved on its own**: G1a subdomain, alias, temporary document root and TLS (**performed by the owner ahead of the gate order**; its read-only observation steps remain); G1b database, user and default collation; G1c mail sink; **G1d the web PHP probe, the `.well-known` symlink probe and, only if needed, the per-domain PHP version** | cPanel; temporary files in the temporary document root |
| G2 | Create `$PORTAL_ROOT` and its directories | `mkdir` under `$PORTAL_ROOT` |
| G3 | Upload and unpack one release ([Release Build](./release-build.md#upload-and-verify-on-the-host-a-host-step-gated)) | files under `$PORTAL_ROOT` |
| G4 | Private configuration and database preparation (phases 4 to 7), including the three release symlinks | `.env`, staging database, symlinks in the unselected release |
| G5 | Select the release and serve it (phase 8). **Not successful until the ACME challenge is retrieved through the served release** | `current` link, cPanel document root, a PHP handler block, one temporary token file in the ACME directory |
| G6 | Wrapper runs by hand and the A2 cross-context check | cache and job rows, wrapper logs, mail to the sink |
| G7 | Install the two staging cron entries | crontab (two added lines) |
| **G7a** | **Only if the existing `MAILTO` is absent or not a monitored recipient:** change the account-wide Cron Email. Never part of G7 and never read-only | crontab (the `MAILTO` line) |
| G8 | Failure and operational rehearsals ([Staging Acceptance](./staging-acceptance.md)) | deliberate, reverted staging misconfiguration |
| G9 | **Temporary** teardown or rollback: staging files, database and cron lines are removed; the document root returns to `portal-staging-holding`; the subdomain, the certificate, acme.sh and `HOLD` stay | removal of staging files and resources |
| **G9b** | **Separate owner decision, never automatic:** permanent retirement of the staging hostname, its certificate and `HOLD` | cPanel; the acme.sh renewal list; `HOLD` |
| G10 | **Closed by the owner (2026-10-22):** the diagnostic cron entry was removed outside the gate order. Nothing to do; a recapture must show it absent, and it is never restored | none |
| G11 | **Separate:** the 270-second survival test | none until opened; see below |
| G12 | **Separate:** any scheduler catch-up change | code; needs a new owner ruling |

## TLS and ACME (acme.sh)

The staging certificate is **acme.sh-managed**, not cPanel AutoSSL: Let's Encrypt, HTTP-01, installed through the `cpanel_uapi` deploy hook, one certificate for the apex and the `www.` alias, both with
`portal-staging-holding` as the ACME webroot. The existing account-wide acme.sh cron entry renews it. This procedure keeps that exactly as it is.

**The problem it solves.** acme.sh writes each challenge token to `HOLD/.well-known/acme-challenge/` and Let's Encrypt fetches it from `http://<name>/.well-known/acme-challenge/<token>`. While the document root is
`portal-staging-holding` that works. At G5 the document root becomes `portal-staging/current/public`, and `HOLD` is no longer served: **a renewal (about 60 days after issue, which is about 2026-12-21; acme.sh's default window, the
configured value is not verified here) would fail**, with a certificate that expires about 90 days after issue.

**The decision (owner, WP-S0).** Keep the versioned releases, the `current` link, the rollback design and the acme.sh configuration unchanged. Each release carries a relative symlink

```
public/.well-known -> ../../../../portal-staging-holding/.well-known
```

so the served release answers the challenge path from the unchanged ACME webroot. Nothing is changed in acme.sh, and no second ACME directory is introduced.

Rules that follow, each enforced by a helper or a gate below:

1. **The link is part of preparing a release, before it is selected** (phase 4, `wk_link`), and `link_ok` fails unless it is exactly the approved link. A release is never edited once selected, so the link is never added to a served release.
2. **`wk_link` only creates.** If `public/.well-known` exists in any form (a directory, a file, a link, a dangling link) it stops. It never overwrites, replaces or removes an existing entry.
3. **The target is a real directory of this account.** `HOLD` and `HOLD/.well-known` must be real (non-symlink) directories owned by the account user and not world-writable, and the link's canonical destination must equal exactly
   `<canonical $HOME>/portal-staging-holding/.well-known`.
4. **Only appropriate content is public.** `wk_content_ok`: the shared `.well-known` holds one entry, the real directory `acme-challenge`, which holds only small plain token files with names made of letters, digits, `-` and `_`.
   Anything else stops for owner review. Nothing else of `HOLD` (its `index.html`, an `.htaccess`) is reachable through the link, because the link names `.well-known`, not `HOLD`.
5. **The link is a recorded, controlled addition** to an unpacked release, in `shared/home/wellknown-link.log` (release id, link text, canonical destination). The archive, its checksum and the release's `RELEASE` file are not modified.
6. **Every release and every rollback target has a verified link** before it is selected (`link_ok`, `wk_content_ok`), and the challenge is **retrieved through it** after it is selected (`acme_probe release`): at G5, after every swap and after every rollback.
7. **A failed retrieval is a failed activation.** G5 does not report success, and A2 is not run, until `acme_probe release` passes. The recovery is to set the document root back to `portal-staging-holding` ([Undo](#phase-8-select-the-release-and-serve-it-g5)), which is the known-working state.
8. **Never force a renewal to test any of this.** No `acme.sh --renew --force`, `--issue`, `--install-cert`, `--remove` or `--uninstall`, no AutoSSL run for these names, no change to the acme.sh cron entry or its configuration. The probes
   write a random token under a name no real challenge uses and remove it. Read-only observation is allowed: `acme.sh --list` and the four `Le_` keys below. The first scheduled renewal is **observed** when it happens ([D11](./staging-acceptance.md#d-web-and-tls)).
9. **Retiring the certificate is a separate owner decision** (G9b, [Teardown](#rollback-and-teardown)); a temporary rollback never touches it.
10. **The certificate is compared with the most recent verified state, never with the original.** acme.sh renews on its own schedule, so the dates, the serial and `Le_NextRenewTimeStr` are *expected* to change over the life of staging; comparing against the
    G1a record would turn a healthy renewal into a false failure. See [Certificate state across renewals](#certificate-state-across-renewals).
11. **`HOLD/.well-known` and `HOLD/.well-known/acme-challenge` must always exist.** They are acme.sh's webroot paths and the target of every release's link. They are never removed or recreated as a deployment cleanup shortcut (not by a release, a rollback, a
    G9 teardown or a tidy-up; only the owner's G9b retirement, after the certificate's fate is decided, ends it), and nothing in this procedure edits acme.sh's renewal configuration. `wk_content_ok` fails when either is missing, a link, foreign-owned or world-writable; that is an owner review, not something to repair from here.

Read-only look at what acme.sh holds (it prints only these keys, never the whole file; the directory name, and `_ecc` for an ECC certificate, is as acme.sh created it, so adjust the glob if it differs):

```bash
"$HOME/.acme.sh/acme.sh" --list
grep -hE '^(Le_Domain|Le_Alt|Le_Webroot|Le_NextRenewTimeStr)=' "$HOME"/.acme.sh/portal-staging.intechral.solutions*/portal-staging.intechral.solutions.conf
```

### Certificate state across renewals

The staging certificate **will** be renewed by the account's acme.sh cron entry while staging exists (a Let's Encrypt certificate lasts about 90 days). So "the certificate is unchanged since G1a" is the wrong test: after a legitimate renewal it fails, and a
check that fails on a healthy renewal teaches people to ignore it. The test is instead: *the latest certificate and acme.sh state, against the most recent state that was verified*, with a short list of things that may change and a longer list that may not.

| | Fields | Rule |
|---|---|---|
| **Protected: never changes** | the SAN set (exactly the canonical name and the `www.` alias), `Le_Domain`, `Le_Alt`, `Le_Webroot` (every element is a `portal-staging-holding` path) | any difference from the last verified state, or from the two staging names, is an unexplained configuration change: **STOP** |
| **May change only with a scheduled renewal** | the serial, `notBefore`, `notAfter`, `Le_NextRenewTimeStr` | allowed only when the certificate (serial) is new **and** `RENEWAL_EVIDENCE` names where you independently read that acme.sh ran (its log, if logging is on, which is not assumed; or the notification it mailed to the cron email) **and** the dates fit a scheduled run: later start and end than the last state, a start no earlier than two days before the recorded next-renewal time (a run that long before it is due was forced), and a next-renewal time that moved forward. Otherwise **STOP** |
| **Validated every time** | the chain and the name | the handshake must validate against the system trust store for exactly that name and for both names, the certificate must be valid now with at least 14 days left (fewer means a renewal failed: **STOP**, owner review, do not force one), and both names must present the same certificate |
| **May change with a note** | the issuing intermediate (`issuer_cn`, for example YE2 to YE3) | Let's Encrypt rotates intermediates. With the same issuing organisation and a validating chain it is accepted and recorded in the history |
| **Owner decision** | the issuing organisation | a different organisation, even with a validating chain, returns **3: verify with the owner**. Nothing is recorded until the owner has approved it |

`RENEWAL_EVIDENCE` is a plain note, set for the one call (`RENEWAL_EVIDENCE='acme.sh log, renewal entry of <date>'`). The functions only check that it is not empty and that the dates are plausible: **they cannot prove that acme.sh ran**, and they say so by requiring you to
write down where you saw it. They read, compare and write state files in your evidence directory. They never run acme.sh, never contact an ACME service, and never read anything from the host except the `Le_` lines you pipe in. A STOP or a 3 changes nothing: the last verified state stays.

```bash
# on the OPERATOR's machine (a different shell from the host session); EVID, evid_ok and ev are defined in phase 0
# Certificate state (read-only). Run on the OPERATOR's machine. These functions never contact acme.sh, an ACME service or the host's files;
# the only network use is an ordinary TLS handshake with the staging names, and the only host data is the Le_ lines you pipe in.
CERT_NAMES=portal-staging.intechral.solutions,www.portal-staging.intechral.solutions       # the exact SAN set, sorted
cert_get() { sed -n "s/^$2=//p" "$1" | head -n 1; }                                          # cert_get <state-file> <key>
cert_fetch() {   # cert_fetch <name>: the leaf certificate the site presents for <name>, as PEM; fails unless the chain validates against the system trust store for exactly that name
  local o; o=$(timeout 30 openssl s_client -connect "$1:443" -servername "$1" -verify_hostname "$1" -verify_return_error -showcerts </dev/null 2>&1)
  grep -q 'Verify return code: 0 (ok)' <<< "$o" || { echo "the certificate presented for $1 does not validate (chain, name or dates): STOP" >&2; return 1; }
  awk '/-----BEGIN CERTIFICATE-----/ {p = 1} p {print} /-----END CERTIFICATE-----/ {exit}' <<< "$o"
}
cert_fields() {  # stdin: one PEM certificate. Prints normalised key=value lines (public data only)
  local pem iss nb na; pem=$(cat); [[ "$pem" == *'BEGIN CERTIFICATE'* ]] || return 1
  iss=$(openssl x509 -noout -issuer -nameopt multiline <<< "$pem") || return 1
  nb=$(openssl x509 -noout -startdate <<< "$pem") && na=$(openssl x509 -noout -enddate <<< "$pem") || return 1
  printf 'issuer_o=%s\n' "$(sed -n 's/^ *organizationName *= *//p' <<< "$iss" | head -n 1)"
  printf 'issuer_cn=%s\n' "$(sed -n 's/^ *commonName *= *//p' <<< "$iss" | head -n 1)"
  printf 'not_before=%s\n' "$(date -u -d "${nb#notBefore=}" +%s)"
  printf 'not_after=%s\n' "$(date -u -d "${na#notAfter=}" +%s)"
  printf 'serial=%s\n' "$(openssl x509 -noout -serial <<< "$pem" | sed 's/^serial=//')"
  printf 'sans=%s\n' "$(openssl x509 -noout -ext subjectAltName <<< "$pem" | grep -o 'DNS:[^, ]*' | sed 's/^DNS://' | LC_ALL=C sort | paste -sd, -)"
}
cert_state() {   # cert_state: stdin = the Le_ lines read from acme.sh's configuration (read-only, over ssh). Prints the current state; both names must present the SAME certificate
  local h f1='' f2='' line k v le_next
  for h in ${CERT_NAMES//,/ }; do
    f2=$(cert_fetch "$h" | cert_fields) && [[ -n "$f2" ]] || { echo "could not read the certificate for $h: STOP" >&2; return 1; }
    [[ -z "$f1" ]] && f1=$f2 || [[ "$f1" == "$f2" ]] || { echo "the two names present different certificates: STOP" >&2; return 1; }
  done
  printf '%s\n' "$f1"
  while IFS='=' read -r k v; do
    v=${v#\'}; v=${v%\'}; v=${v#\"}; v=${v%\"}
    case $k in Le_Domain) echo "le_domain=$v";; Le_Alt) echo "le_alt=$v";; Le_Webroot) echo "le_webroot=$v";;
      Le_NextRenewTimeStr) le_next=$(date -u -d "$v" +%s) && echo "le_next_renew=$le_next";; esac
  done
}
cert_compare() { # cert_compare <last-state> <new-state>: 0 = unchanged, or a renewal that fits an independently evidenced scheduled acme.sh run;  3 = verify with the owner (nothing is accepted);  1 = STOP
  local last=${1:?} new=${2:?} k now d
  for k in sans issuer_o issuer_cn not_before not_after serial le_domain le_alt le_webroot le_next_renew; do
    [[ -n "$(cert_get "$new" $k)" ]] || { echo "state is incomplete ($k): STOP" >&2; return 1; }; done
  [[ "$(cert_get "$new" sans)" == "$CERT_NAMES" ]] || { echo "the certificate does not name exactly the two staging hostnames: STOP" >&2; return 1; }
  [[ "$(cert_get "$new" le_domain)" == "${CERT_NAMES%%,*}" && "$(cert_get "$new" le_alt)" == "${CERT_NAMES#*,}" ]] \
    || { echo "acme.sh Le_Domain / Le_Alt are not the two staging hostnames: STOP" >&2; return 1; }
  for d in $(tr ',' ' ' <<< "$(cert_get "$new" le_webroot)"); do [[ "$d" == */portal-staging-holding ]] || { echo "Le_Webroot is not the holding root: STOP" >&2; return 1; }; done
  for k in sans le_domain le_alt le_webroot; do
    [[ "$(cert_get "$new" $k)" == "$(cert_get "$last" $k)" ]] || { echo "$k changed since the last verified state: an unexplained configuration change: STOP" >&2; return 1; }; done
  now=$(date +%s)
  (( $(cert_get "$new" not_before) <= now && now < $(cert_get "$new" not_after) )) || { echo "the certificate is not valid now: STOP" >&2; return 1; }
  (( $(cert_get "$new" not_after) - now >= 14 * 86400 )) || { echo "the certificate expires in under 14 days and has not been renewed: STOP for owner review (do not force a renewal)" >&2; return 1; }
  if [[ "$(cert_get "$new" serial)" == "$(cert_get "$last" serial)" ]]; then
    for k in issuer_o issuer_cn not_before not_after le_next_renew; do
      [[ "$(cert_get "$new" $k)" == "$(cert_get "$last" $k)" ]] || { echo "$k changed but the certificate (serial) did not: STOP" >&2; return 1; }; done
    echo "UNCHANGED: the same certificate and the same acme.sh state as the last verified"; return 0
  fi
  [[ -n "${RENEWAL_EVIDENCE:-}" ]] || { echo "a different certificate is presented and no renewal evidence is recorded (set RENEWAL_EVIDENCE to where you read it): STOP" >&2; return 1; }
  (( $(cert_get "$new" not_before) > $(cert_get "$last" not_before) && $(cert_get "$new" not_after) > $(cert_get "$last" not_after) \
     && $(cert_get "$new" not_before) >= $(cert_get "$last" le_next_renew) - 2 * 86400 \
     && $(cert_get "$new" le_next_renew) > $(cert_get "$last" le_next_renew) && $(cert_get "$new" le_next_renew) > $(cert_get "$new" not_before) )) \
    || { echo "the new certificate does not fit a scheduled renewal (dates older, earlier than the recorded renewal time, or the next renewal did not advance): STOP" >&2; return 1; }
  if [[ "$(cert_get "$new" issuer_o)" != "$(cert_get "$last" issuer_o)" ]]; then
    echo "REVIEW: the issuing organisation changed ($(cert_get "$last" issuer_o) -> $(cert_get "$new" issuer_o)); the chain validates, but the owner must approve it: nothing recorded" >&2; return 3
  fi
  if [[ "$(cert_get "$new" issuer_cn)" != "$(cert_get "$last" issuer_cn)" ]]; then
    echo "RENEWED (evidence: $RENEWAL_EVIDENCE). The issuing intermediate changed: $(cert_get "$last" issuer_cn) -> $(cert_get "$new" issuer_cn); the chain validated; record it"; return 0
  fi
  echo "RENEWED (evidence: $RENEWAL_EVIDENCE): new dates and next renewal time; hostnames, webroot and issuer unchanged"; return 0
}
cert_record() {  # cert_record <file>: the FIRST verified state (G1a read-back). Refuses to overwrite. stdin = the Le_ lines
  local f=${1:?} t; [[ ! -e "$f" && ! -L "$f" ]] || { echo "$f already exists: not overwriting: STOP" >&2; return 1; }
  t=$(mktemp) || return 1
  if cert_state > "$t" && cert_compare "$t" "$t" >/dev/null && ( umask 077; set -C; cat -- "$t" > "$f" ); then rm -f -- "$t"; echo "recorded: $f"; else rm -f -- "$t"; return 1; fi
}
cert_check() {   # cert_check <last-state-file>: compare what the site and acme.sh show NOW with the last verified state; on 0 the new state becomes the last (history kept). stdin = the Le_ lines
  local last=${1:?} t rc; [[ -f "$last" && ! -L "$last" ]] || { echo "no recorded state at $last (cert_record at G1a first): STOP" >&2; return 1; }
  t=$(mktemp) || return 1
  cert_state > "$t" || { rm -f -- "$t"; return 1; }
  cert_compare "$last" "$t"; rc=$?
  if (( rc == 0 )); then ( umask 077; { printf '# %s evidence=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${RENEWAL_EVIDENCE:-none}"; cat -- "$t"; } >> "$last.history"; cat -- "$t" > "$last.new" && mv -T -- "$last.new" "$last" ); rc=$?; fi
  rm -f -- "$t"; return $rc
}
```

At G1a the first state is recorded once (`cert_record`); after that every check is `cert_check`, which on success makes the new state the last verified state and appends the evidence to `cert.last.history`.

## Why this order

The three earlier documents agreed on the steps but not on the order, and none addressed first installation. These choices are deliberate:

1. **`config:cache` before `migrate`.** (The database profile listed it after `database:verify`; the cron document listed it first.) Production requires a cached configuration; after the cache is built, every
   later command (migration, `database:verify`, `runtime:preflight`, `roles:sync`, the bootstrap) runs against the **one frozen configuration** the web, the queue worker and the scheduler will use. Migrating on the
   uncached `.env` would prove a different resolution than the one that runs in production. Caching needs no database. Any change to `.env` means `config:cache` again, then `runtime:preflight`.
2. **No `database:verify` before `migrate`.** It fails by design while migrations are pending. The migration guard is the pre-migration check; `database:verify` is the post-migration gate.
3. **The site is not served until the application is ready.** On a first install there is no `current` link, so no request can reach the application. `roles:sync`, the first operator and the switch
   to `PORTAL_CUSTOMER_SURFACE_MODE=pilot` (with its re-cache and re-verification) all happen **before** `current` exists. That meets the intent of the customer-surface order (operators eligible before any
   request) without maintenance mode.
4. **Maintenance mode is not used on first install, and A2 cannot run under it.** A first install has no `current` release, so there is nothing serving to take down, and the customer-surface order
   ([section 10](../architecture/customer-pilot-surface.md#10-deployment-operator-eligibility-and-rolessync), which describes a deployment over a served release) is met by not serving at all. In maintenance mode a `queue:work --stop-when-empty` worker sleeps once and exits 0 without running any job, and
   scheduled events are not due (Laravel 13.36: `Worker::daemonShouldRun`, `Event::isDue`). A2's queue and scheduler legs would never answer, and the cron wrapper would log `END status=ok` while doing
   nothing. `GET /up` is excepted from maintenance, so the web leg alone would pass. Maintenance mode is for a later release that changes the role catalogue ([below](#later-releases)); A2 runs after `up`.
5. **The wrapper by hand, then A2, then cron.** The wrapper is the supported way to run a worker or scheduler by hand, takes the same lock as the cron entries, and exercises the same release resolution,
   so A2 can be completed before any crontab change. A2 is repeated, answered by the real cron entries, during acceptance.
6. **The document root is set after `current` exists.** A control panel that is told to use a path creates it as a **real directory**; `mv -T current.new current` cannot replace a directory
   (`mv: cannot overwrite directory 'current' with non-directory`, reproduced). Hence the temporary document root at G1a and the switch at G5.
7. **Every artisan run, and so every `config:cache`, is under `umask 077`.** The configuration cache contains the resolved `APP_KEY` and database password, and its file mode is simply the process umask (measured: 0664
   under umask 002, 0644 under 022, 0600 under 077; regenerating a 0600 file under 022 produced 0644). The host's SSH default umask is not safe to rely on, so the `art` helper sets it, and `cc` verifies the mode after
   **every** cache build.
8. **The extensions and the web PHP are checked before the release is uploaded, not after.** Composer's generated platform check verifies only the PHP version and 64-bit; it does not verify `exif` or any other
   extension. The CLI is checked directly from SSH (phase 0) and the web handler directly through a one-off probe (G1d), so a missing extension stops the install before anything depends on it.
9. **The web side is verified through the application, not assumed from the link.** That `current` is a symlink proves nothing about what cPanel serves. A2's web observation reports the physical release and the PHP
   version the web process actually ran with; it is repeated after every swap and rollback.
10. **The ACME link goes in before the release is selected, and the challenge is fetched through it before success is declared.** The certificate renews from `HOLD`, which stops being served at G5. A link that
    exists proves nothing about what LiteSpeed serves, so G1d probes the mechanism before any release exists, and G5 and every swap and rollback retrieve a real random token over both schemes and both names
    ([TLS and ACME](#tls-and-acme-acmesh)).

## Session setup (fail-closed helpers)

Paste once per SSH session. Nothing here changes the host. Every helper below calls a guard first and **fails closed**: an unset, empty, placeholder or foreign value stops it with a message, and `art` and
`wrap` run in subshells, so a failed guard or a failed `cd` can never leave artisan running from the directory you were in. All the assignments are valid as written: the two placeholders are inert strings the
guards reject until you replace them.

```bash
PORTAL_ROOT="$(readlink -f -- "${HOME:?}")/portal-staging"            # absolute and symlink-resolved: the cron lines use this exact string
export -n PORTAL_ROOT                                                 # NEVER exported: only the wrapper's processes may carry it (this also removes an export inherited from your login environment)
PHP=/opt/alt/php85/usr/bin/php
STAGING_HOST=portal-staging.intechral.solutions
STAGING_ALIAS=www.portal-staging.intechral.solutions                  # an alias of the site, never the canonical name
REL_ID=REPLACE_WITH_RELEASE_ID            # from the build: 14 digits, a dash, 7 hex digits. Change it ONLY with: setrel 20261022225141-6df8847
STAGING_DB=REPLACE_WITH_STAGING_DB_NAME   # the exact cPanel-prefixed name created at G1b
REL="$PORTAL_ROOT/releases/$REL_ID"
HOLD="$(readlink -f -- "$HOME")/portal-staging-holding"               # the temporary document root, which the owner created at G1a and which is also the ACME webroot
WK_TEXT=../../../../portal-staging-holding/.well-known                # the ONE approved link text, from <staging root>/releases/<id>/public: four levels up is $HOME
setrel() { REL_ID=$1; REL="$PORTAL_ROOT/releases/$REL_ID"; rel_name_ok; }      # keeps REL in step with REL_ID (REL is derived once, so editing REL_ID alone would leave it stale)

# Guards. Each fails closed and says why; every mutating helper below calls one.
root_ok() {
  [[ "${HOME:-}" == /* && "${PORTAL_ROOT:-}" == "$(readlink -f -- "$HOME")/portal-staging" ]] || { echo "PORTAL_ROOT is not the staging root: STOP" >&2; return 1; }
  [[ "${STAGING_HOST:-}" =~ ^[a-z0-9.-]+$ && "$STAGING_HOST" != "portal.intechral.solutions" ]] || { echo "STAGING_HOST is empty or the existing portal: STOP" >&2; return 1; }
  ! env | grep -q '^PORTAL_ROOT=' || { echo "PORTAL_ROOT is exported to child processes (run: export -n PORTAL_ROOT): STOP" >&2; return 1; }
}
rel_name_ok() { [[ "${REL_ID:-}" =~ ^[0-9]{14}-[0-9a-f]{7}$ ]] || { echo "REL_ID is not a release id (still the placeholder?): STOP" >&2; return 1; }; }
rel_path_ok() {   # REL is exactly <staging root>/releases/<id>, directly inside the real (non-symlink) releases directory: no traversal, nothing outside it
  root_ok && rel_name_ok || return 1
  [[ "${REL:-}" == "$PORTAL_ROOT/releases/$REL_ID" && "$REL" != *..* && "$REL" != *//* ]] || { echo "REL is not <staging root>/releases/<id>: STOP" >&2; return 1; }
  [[ -d "$PORTAL_ROOT/releases" && ! -L "$PORTAL_ROOT/releases" && "$(readlink -f -- "$PORTAL_ROOT/releases")" == "$PORTAL_ROOT/releases" ]] || { echo "releases/ is missing or not a real directory: STOP" >&2; return 1; }
}
rel_ok() {
  rel_path_ok || return 1
  [[ -f "$REL/artisan" && ! -L "$REL" ]] || { echo "release $REL_ID is not unpacked under the staging root: STOP" >&2; return 1; }
}
rel_unselected() {   # true only if current is absent (first install) or a link that resolves to a DIFFERENT directory directly inside releases/: canonical paths, never link text
  [[ -e "$PORTAL_ROOT/current" || -L "$PORTAL_ROOT/current" ]] || return 0
  local cur rel; [[ -L "$PORTAL_ROOT/current" && -d "$PORTAL_ROOT/current" ]] || return 1                  # not a link, or a broken one: unexpected
  cur=$(readlink -f -- "$PORTAL_ROOT/current") && rel=$(readlink -f -- "$REL") || return 1
  [[ -n "$cur" && -n "$rel" && "${cur%/*}" == "$PORTAL_ROOT/releases" && "$cur" != "$rel" ]]
}
# The shared ACME directory and the release's link to it (TLS and ACME). They only read, except wk_link, which only creates.
wk_dir_ok() {   # wk_dir_ok <dir>: a real directory (not a link), already canonical, owned by this account, not world-writable
  local d=${1:-} m
  [[ -d "$d" && ! -L "$d" && "$(readlink -f -- "$d")" == "$d" && "$(stat -c %U -- "$d")" == "$(id -un)" ]] || return 1
  m=$(stat -c %a -- "$d") && (( (8#$m & 2) == 0 ))
}
wk_target_ok() {   # HOLD and HOLD/.well-known are real directories of this account: never links, never world-writable
  wk_dir_ok "$HOLD" && wk_dir_ok "$HOLD/.well-known" \
    || { echo "the ACME target $HOLD/.well-known is missing, a link, not canonical, not owned by $(id -un), or world-writable: STOP" >&2; return 1; }
}
wk_ok() {   # the release's ACME link is exactly the approved one: its text, its canonical destination, its owner, and a safe real target
  rel_ok || return 1
  local l="$REL/public/.well-known" want; want="$(readlink -f -- "$HOME")/portal-staging-holding/.well-known"
  [[ -d "$REL/public" && ! -L "$REL/public" && -L "$l" && "$(readlink -- "$l")" == "$WK_TEXT" && "$(readlink -f -- "$l")" == "$want" && "$(stat -c %U -- "$l")" == "$(id -un)" ]] \
    || { echo "public/.well-known is not exactly the approved link to the ACME directory: STOP" >&2; return 1; }
  wk_target_ok
}
wk_content_ok() {   # the shared ACME directory exposes only acme-challenge/ and, inside it, small plain token files; nothing else of HOLD is reachable through the link
  wk_target_ok || return 1
  local d="$HOLD/.well-known" bad
  bad=$(find "$d" -mindepth 1 -maxdepth 1 ! \( -type d -name acme-challenge \) -printf '%f\n' | LC_ALL=C tr -c 'A-Za-z0-9._\n-' '?' | head -n 3 | tr '\n' ' ')
  [[ -z "$bad" ]] || { echo "unexpected entries in the shared .well-known (names, first three: $bad): STOP for owner review" >&2; return 1; }
  [[ -d "$d/acme-challenge" ]] || { echo "the shared .well-known has no acme-challenge directory: STOP for owner review" >&2; return 1; }
  wk_dir_ok "$d/acme-challenge" || { echo "acme-challenge is not a real directory of this account, or is world-writable: STOP" >&2; return 1; }
  bad=$(find "$d/acme-challenge" -mindepth 1 \( ! -type f -o ! -regex '.*/[A-Za-z0-9_-]+' -o -perm /113 -o -size +4k \) -printf '%f\n' | LC_ALL=C tr -c 'A-Za-z0-9._\n-' '?' | head -n 3 | tr '\n' ' ')
  [[ -z "$bad" ]] || { echo "unexpected content in acme-challenge (names, first three: $bad): STOP for owner review" >&2; return 1; }
  echo "the shared .well-known holds only acme-challenge and plain token files"
}
link_ok() {   # the release is wired to the staging shared files and to the ACME directory, by symlink and never by copy
  rel_ok || return 1
  [[ -L "$REL/.env" && "$(readlink -f -- "$REL/.env")" == "$PORTAL_ROOT/shared/.env" ]] || { echo ".env is not a symlink to the staging shared .env: STOP" >&2; return 1; }
  [[ -L "$REL/storage" && "$(readlink -f -- "$REL/storage")" == "$PORTAL_ROOT/shared/storage" ]] || { echo "storage is not a symlink to the staging shared storage: STOP" >&2; return 1; }
  wk_ok
}
env_ok() { root_ok && [[ "$(stat -c '%a %U' -- "$PORTAL_ROOT/shared/.env" 2>/dev/null)" == "600 $(id -un)" ]] || { echo "shared/.env is missing or not 0600 owned by you: STOP" >&2; return 1; }; }

# Helpers.
art() { ( link_ok || exit 1; cd -- "$REL" || exit 1; umask 077; exec env -i PATH=/usr/bin:/bin HOME="$PORTAL_ROOT/shared/home" "$PHP" artisan "$@" ); }
wrap() { ( root_ok && [[ -L "$PORTAL_ROOT/current" && ( "${1:-}" == queue || "${1:-}" == schedule ) ]] || { echo "usage: wrap queue|schedule (with current selected)" >&2; exit 1; }
           exec env -i PATH=/usr/bin:/bin PORTAL_ROOT="$PORTAL_ROOT" /bin/sh "$PORTAL_ROOT/current/scripts/cron/portal-cron.sh" "$1" ); }
cc() {        # every configuration cache goes through here: umask 077 (inside art) and the mode is verified every time
  env_ok && art config:cache && [[ "$(stat -c %a -- "$REL/bootstrap/cache/config.php" 2>/dev/null)" == 600 ]] \
    && echo "config cache written, mode 600" || { echo "config:cache failed or the cache is not mode 600: STOP" >&2; return 1; }
}
db_ok() {     # positively identify the connected database before anything touches it
  [[ "${STAGING_DB:-}" =~ ^[A-Za-z0-9_]+$ && "$STAGING_DB" != REPLACE_* && "$STAGING_DB" != intechral_client_portal_testing ]] || { echo "STAGING_DB is not set to the staging database: STOP" >&2; return 1; }
  local got; got=$(art tinker --no-ansi --execute='echo "DB=", DB::getDatabaseName(), PHP_EOL;' 2>/dev/null | sed -n 's/^DB=//p' | tail -n 1)
  [[ "$got" == "$STAGING_DB" ]] || { echo "connected database is '${got:-?}', expected '$STAGING_DB': STOP" >&2; return 1; }
  echo "connected to the staging database: $got"
}
lock_free() { [[ -f "$1" ]] && flock -n -x "$1" true; }     # nonblocking acquire-and-release: true only if nothing holds the lock right now
procs_env() { local d; for d in /proc/[0-9]*; do [[ "${d#/proc/}" != "$$" && "$(stat -c %U -- "$d" 2>/dev/null)" == "$(id -un)" ]] && tr '\0' '\n' 2>/dev/null < "$d/environ" | grep -qxF -- "$1" && echo "${d#/proc/}"; done; true; }
procs_cmd() { local d; for d in /proc/[0-9]*; do [[ "${d#/proc/}" != "$$" && "$(stat -c %U -- "$d" 2>/dev/null)" == "$(id -un)" ]] && tr '\0' ' ' 2>/dev/null < "$d/cmdline" | grep -qF -- "$1" && echo "${d#/proc/}"; done; true; }
cron_runs() {   # cron_runs: one "<queue|schedule> <pid>" line per ACTIVE wrapper INVOCATION of your account for THIS staging root. Counts invocations, not processes:
                # the wrapper re-executes itself (so it may show as current/ or as releases/<id>/), and its own subshells, `timeout` and PHP are children, never counted again.
  local d p st rest ppid k; local -a a; local -A kind=() par=()
  for d in /proc/[0-9]*; do
    p=${d#/proc/}; [[ "$p" != "$$" && "$(stat -c %U -- "$d" 2>/dev/null)" == "$(id -un)" ]] || continue
    mapfile -t a < <(tr '\0' '\n' < "$d/cmdline" 2>/dev/null)
    [[ ${#a[@]} -eq 3 && "${a[0]}" == /bin/sh && ( "${a[2]}" == queue || "${a[2]}" == schedule ) \
       && "${a[1]}" =~ ^"$PORTAL_ROOT"/(current|releases/[0-9]{14}-[0-9a-f]{7})/scripts/cron/portal-cron\.sh$ ]] || continue
    tr '\0' '\n' < "$d/environ" 2>/dev/null | grep -qxF -- "PORTAL_ROOT=$PORTAL_ROOT" || continue
    st=$(<"$d/stat") 2>/dev/null || continue; rest=${st##*) }; set -- $rest; kind[$p]=${a[2]}; par[$p]=$2
  done
  for p in "${!kind[@]}"; do [[ -z "${kind[${par[$p]}]:-}" ]] && echo "${kind[$p]} $p"; done | sort -k2n; return 0     # a top-level invocation: its parent is not itself a wrapper
}

# The PHP handler block (phase 8): narrow checks, not a general .htaccess parser.
htaccess_check() {   # htaccess_check <file>: succeeds only if <file> is exactly ONE bounded, recognised cPanel PHP handler block (plus blank lines)
  [[ -f "${1:-}" && ! -L "$1" ]] || { echo "no such handler file: STOP" >&2; return 1; }
  LC_ALL=C awk '
    function bad(m) { printf "unrecognised handler file, line %d: %s: STOP for owner review\n", NR, m > "/dev/stderr"; failed = 1; exit 1 }
    NR > 40                                   { bad("longer than a handler block should be") }
    /\r/                                      { bad("carriage return") }
    /^[ \t]*$/                                { next }
    /^# php -- BEGIN cPanel-generated handler, do not edit$/ { if (state != 0) bad("repeated or nested BEGIN"); state = 1; next }
    /^# php -- END cPanel-generated handler, do not edit$/   { if (state != 1) bad("END without BEGIN"); if (depth != 0) bad("unclosed IfModule"); state = 2; next }
    state != 1                                { bad("content outside the BEGIN/END markers") }
    /^#/                                      { next }
    /^[ \t]*<IfModule [a-z_]+_module>[ \t]*$/ { if (depth != 0) bad("nested IfModule"); depth = 1; next }
    /^[ \t]*<\/IfModule>[ \t]*$/              { if (depth != 1) bad("stray </IfModule>"); depth = 0; next }
    /^[ \t]*(AddHandler|AddType)[ \t]+[A-Za-z0-9._+\/-]+([ \t]+\.[A-Za-z0-9]+)+[ \t]*$/ {
      if (depth != 1) bad("directive outside an IfModule"); if ($2 !~ /php85/) bad("handler is not for PHP 8.5"); directives++; next }
                                              { bad("unexpected directive or content") }
    END { if (failed) exit 1; if (state != 2) { print "incomplete handler block: STOP" > "/dev/stderr"; exit 1 }
          if (directives < 1) { print "no handler directive in the block: STOP" > "/dev/stderr"; exit 1 } }
  ' "$1"
}
htaccess_adopt() (   # htaccess_adopt: validate the temporary document root's .htaccess (written by cPanel at G1d) and keep it as THE reviewed block
  set -u; root_ok || exit 1
  [[ -d "$PORTAL_ROOT/shared/home" && ! -e "$PORTAL_ROOT/shared/home/php-handler.block" ]] || { echo "no shared/home, or a reviewed block is already recorded: STOP" >&2; exit 1; }
  htaccess_check "$HOLD/.htaccess" || exit 1
  ( umask 077; cp -- "$HOLD/.htaccess" "$PORTAL_ROOT/shared/home/php-handler.block" ) || exit 1
  echo "reviewed block recorded, sha256 $(sha256sum < "$PORTAL_ROOT/shared/home/php-handler.block" | cut -d' ' -f1)"
)
htaccess_apply() (   # htaccess_apply: append the reviewed block to the NOT-yet-selected release's public/.htaccess; the only change ever made to a file of the archive
  set -u; rel_ok || exit 1
  blk="$PORTAL_ROOT/shared/home/php-handler.block"; target="$REL/public/.htaccess"
  [[ -f "$blk" && ! -L "$blk" && -f "$target" && ! -L "$target" ]] || { echo "the reviewed block or the target is missing: STOP" >&2; exit 1; }
  rel_unselected || { echo "release $REL_ID is the selected release, or current is not a link to a release: refusing to modify: STOP" >&2; exit 1; }
  htaccess_check "$blk" || exit 1
  [[ -z "$(tail -c 1 -- "$blk")" && -z "$(tail -c 1 -- "$target")" ]] || { echo "the block or the target does not end with a newline: STOP" >&2; exit 1; }
  grep -qF -- '# php -- BEGIN cPanel-generated handler' "$target" && { echo "the target already contains a handler block: refusing to add another: STOP" >&2; exit 1; }
  base=$(sed -n 's/^htaccess_sha256=//p' "$REL/RELEASE"); [[ "$(sha256sum < "$target" | cut -d' ' -f1)" == "$base" && -n "$base" ]] || { echo "the target is not the archived file (already modified?): STOP" >&2; exit 1; }
  umask 022                                                                            # explicit: the host default (0002) is not relied on
  tmp=$(mktemp -- "$target.XXXXXX") && [[ "$tmp" == "$target."?????? && -f "$tmp" && ! -L "$tmp" ]] || { echo "cannot create a private temporary file beside the target: STOP" >&2; exit 1; }   # exclusive create, never follows a planted link
  trap 'rm -f -- "$tmp"' EXIT; trap 'exit 1' HUP INT TERM                                 # removes only the file made above, on any failure or interrupt
  { cat -- "$target" "$blk" > "$tmp"; } || exit 1
  [[ -f "$tmp" && ! -L "$tmp" ]] || { echo "the temporary file was replaced: STOP" >&2; exit 1; }
  size=$(wc -c < "$target"); cmp -s -n "$size" -- "$tmp" "$target" || { echo "the original content is not preserved: STOP" >&2; exit 1; }       # the archived bytes are an exact prefix
  chmod 644 -- "$tmp" || exit 1
  rel_unselected && [[ -f "$target" && ! -L "$target" ]] || { echo "the release became selected, or the target changed, during the update: STOP" >&2; exit 1; }       # revalidated immediately before the replacement
  mv -T -- "$tmp" "$target" || exit 1
  tmp=                                                                                   # renamed into place: nothing left to remove
  [[ -f "$target" && ! -L "$target" && "$(stat -c %a -- "$target")" == 644 ]] || { echo "the result is not a regular file of mode 644: STOP" >&2; exit 1; }
  ( umask 077; printf '%s release=%s baseline_sha256=%s block_sha256=%s result_sha256=%s mode=644\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$REL_ID" "$base" \
      "$(sha256sum < "$blk" | cut -d' ' -f1)" "$(sha256sum < "$target" | cut -d' ' -f1)" >> "$PORTAL_ROOT/shared/home/handler-carry-over.log" ) || exit 1
  echo "handler block added to $REL_ID/public/.htaccess, mode 644; recorded as an intentional pre-publication difference from the archive (shared/home/handler-carry-over.log)"
)
htaccess_ok() {   # the .htaccess of $REL is exactly what was published: the archive's file, or the recorded result of the carry-over
  rel_ok || return 1
  local want got; want=$(sed -n "s/^.* release=$REL_ID baseline_sha256=[0-9a-f]* block_sha256=[0-9a-f]* result_sha256=\([0-9a-f]\{64\}\) .*$/\1/p" "$PORTAL_ROOT/shared/home/handler-carry-over.log" 2>/dev/null | tail -n 1)
  [[ -n "$want" ]] || want=$(sed -n 's/^htaccess_sha256=//p' "$REL/RELEASE")
  got=$(sha256sum < "$REL/public/.htaccess" | cut -d' ' -f1)
  [[ -n "$want" && "$got" == "$want" ]] && echo "public/.htaccess is exactly what was published" || { echo "public/.htaccess differs from what was published (was a served release edited?): STOP" >&2; return 1; }
}

# The ACME link (TLS and ACME). wk_symprobe and acme_probe write temporary files and remove them; neither renews, issues or configures anything.
wk_link() (   # wk_link: add the approved ACME link to an UNSELECTED release's public/. It only creates: anything already at public/.well-known stops it
  set -u; rel_ok || exit 1
  l="$REL/public/.well-known"
  rel_unselected || { echo "release $REL_ID is the selected release, or current is not a link to a release: refusing to modify: STOP" >&2; exit 1; }
  [[ -d "$REL/public" && ! -L "$REL/public" ]] || { echo "public/ is not a real directory: STOP" >&2; exit 1; }
  [[ ! -e "$l" && ! -L "$l" ]] || { echo "public/.well-known already exists (a directory, a file or a link): not creating over it. Inspect it with wk_ok: STOP" >&2; exit 1; }
  wk_content_ok || exit 1                                                                # a safe, real, appropriate target first
  ln -sT -- "$WK_TEXT" "$l" || exit 1                                                    # -T: if the destination became a directory in the meantime this fails, instead of nesting a link inside it
  wk_ok || { [[ -L "$l" ]] && rm -- "$l"; echo "the new link did not validate and was removed: STOP" >&2; exit 1; }       # removes only the link made above
  ( umask 077; printf '%s release=%s link=public/.well-known text=%s destination=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$REL_ID" "$WK_TEXT" "$(readlink -f -- "$l")" \
      >> "$PORTAL_ROOT/shared/home/wellknown-link.log" ) || exit 1
  echo "public/.well-known linked in $REL_ID; recorded as an intentional addition after extraction (shared/home/wellknown-link.log)"
)
wk_symprobe() (   # wk_symprobe (G1d, before any release exists): does this web server follow a ".well-known" symlink at all? The link and its target are BOTH inside HOLD, a permitted
                  # traversal within the holding root; it does NOT test a link that leaves a document root (a release's public/ to HOLD), which is what the releases rely on.
                  # So a PASS is a necessary precondition, never the proof: the authoritative evidence is the HTTP retrieval through the served release at G5 (acme_probe release).
                  # It builds a throw-away tree inside HOLD (it never touches HOLD/.well-known), fetches one token over https on both names, and removes the tree.
  set -u; root_ok || exit 1
  [[ "${STAGING_ALIAS:-}" == "www.$STAGING_HOST" ]] || { echo "STAGING_ALIAS is not www.\$STAGING_HOST: STOP" >&2; exit 1; }
  wk_dir_ok "$HOLD" || { echo "HOLD is not a real directory of this account: STOP" >&2; exit 1; }
  n="wk-probe-$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')"; tok="t$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')"; body=$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')
  [[ "$n" =~ ^wk-probe-[0-9a-f]{16}$ && "$tok" =~ ^t[0-9a-f]{16}$ && "$body" =~ ^[0-9a-f]{48}$ ]] || { echo "could not draw random names: STOP" >&2; exit 1; }
  p="$HOLD/$n"; [[ ! -e "$p" && ! -L "$p" ]] || { echo "probe path exists: STOP" >&2; exit 1; }
  work=$(mktemp -d) && [[ -d "$work" && ! -L "$work" ]] || exit 1
  cleanup() { rm -f -- "$p/target/$tok" "$p/dir/.well-known" "$work/want" "$work/got"; rmdir -- "$p/target" "$p/dir" "$p" "$work" 2>/dev/null; return 0; }       # only the files and directories made below
  trap cleanup EXIT; trap 'exit 1' HUP INT TERM
  ( umask 022; mkdir -- "$p" "$p/target" "$p/dir" && printf '%s' "$body" > "$p/target/$tok" && ln -s ../target "$p/dir/.well-known" ) || exit 1
  printf '%s' "$body" > "$work/want" || exit 1
  bad=0
  for h in "$STAGING_HOST" "$STAGING_ALIAS"; do
    rm -f -- "$work/got"
    code=$(curl -sS --max-time 20 -o "$work/got" -w '%{http_code}' "https://$h/$n/dir/.well-known/$tok" 2>&1)
    if [[ "$code" == 200 ]] && cmp -s -- "$work/got" "$work/want"; then echo "https://$h: the symlink is followed, body matches: PASS"; else echo "https://$h: HTTP $code, or the body differs: FAIL"; bad=1; fi
  done
  cleanup
  [[ ! -e "$p" && ! -L "$p" ]] || { echo "the probe tree was not removed: STOP" >&2; exit 1; }
  (( bad == 0 )) && echo "RESULT=PASS" || { echo "RESULT=FAIL"; exit 1; }
)
acme_probe() (   # acme_probe release|holding: a random token in the ACME directory is retrieved, byte for byte, over http and https on the canonical name and the alias.
                 # release: the served release (setrel <it>) is the one current points to; its links are validated first.  holding: after the document root went back to portal-staging-holding.
  set -u; root_ok || exit 1
  mode=${1:-}; [[ "$mode" == release || "$mode" == holding ]] || { echo "usage: acme_probe release|holding" >&2; exit 1; }
  [[ "${STAGING_ALIAS:-}" == "www.$STAGING_HOST" ]] || { echo "STAGING_ALIAS is not www.\$STAGING_HOST: STOP" >&2; exit 1; }
  if [[ "$mode" == release ]]; then
    link_ok || exit 1
    [[ -L "$PORTAL_ROOT/current" && "$(readlink -f -- "$PORTAL_ROOT/current")" == "$(readlink -f -- "$REL")" ]] || { echo "current is not the release selected with setrel: STOP" >&2; exit 1; }
  fi
  wk_content_ok || exit 1
  tok="portal-probe-$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')"; body=$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')       # a name no real ACME challenge uses
  [[ "$tok" =~ ^portal-probe-[0-9a-f]{16}$ && "$body" =~ ^[0-9a-f]{48}$ ]] || { echo "could not draw a random token: STOP" >&2; exit 1; }
  f="$HOLD/.well-known/acme-challenge/$tok"; made=0
  [[ ! -e "$f" && ! -L "$f" ]] || { echo "the probe token path already exists (a file or a link): not touching it: STOP" >&2; exit 1; }
  work=$(mktemp -d) && [[ -d "$work" && ! -L "$work" ]] || exit 1
  cleanup() { [[ "$made" != 1 ]] || rm -f -- "$f"; rm -f -- "$work/want" "$work/got"; rmdir -- "$work" 2>/dev/null; return 0; }       # the token only if THIS run created it; never another file in the ACME directory
  trap cleanup EXIT; trap 'exit 1' HUP INT TERM
  ( set -C; umask 022; : > "$f" ) 2>/dev/null || { echo "could not create the probe token exclusively (it exists, or the directory is not writable): STOP" >&2; exit 1; }     # noclobber: O_EXCL, never overwrites
  [[ -f "$f" && ! -L "$f" ]] && made=1 || { echo "the probe token is not a plain file we just created: leaving it alone: STOP" >&2; exit 1; }
  printf '%s' "$body" >> "$f" || { echo "could not write the probe token: STOP" >&2; exit 1; }          # the file is ours now (made=1), so a failed write is still cleaned up
  printf '%s' "$body" > "$work/want" || exit 1
  bad=0
  for h in "$STAGING_HOST" "$STAGING_ALIAS"; do
    for s in http https; do
      rm -f -- "$work/got"
      out=$(curl -sS --max-time 20 --max-redirs 3 -L -o "$work/got" -w '%{http_code} %{num_redirects} %{url_effective}' "$s://$h/.well-known/acme-challenge/$tok" 2>&1)
      read -r code nred eff <<< "$out"; host=${eff#*://}; host=${host%%/*}
      # final answer: 200, on one of the two names, still https if it started as https, and exactly the token body
      if [[ "$code" == 200 && ( "$host" == "$STAGING_HOST" || "$host" == "$STAGING_ALIAS" ) && ( "$s" == http || "$eff" == https://* ) ]] && cmp -s -- "$work/got" "$work/want"; then verdict=PASS; else verdict=FAIL; bad=1; fi
      echo "$s://$h -> HTTP ${code:-?}, ${nred:-?} redirect(s), final host ${host:-?}: $verdict"
    done
  done
  cleanup
  [[ ! -e "$f" ]] || { echo "the probe token could not be removed: STOP" >&2; exit 1; }
  echo "after removal: HTTP $(curl -s -o /dev/null --max-time 20 -w '%{http_code}' "https://$STAGING_HOST/.well-known/acme-challenge/$tok") (404 expected; informational)"
  (( bad == 0 )) && echo "RESULT=PASS ($mode)" || { echo "RESULT=FAIL ($mode)"; exit 1; }
)
```

- `PORTAL_ROOT` is made **not exported** (`export -n`, which also removes an export inherited from your login environment), and `root_ok` refuses to continue if it is exported. Otherwise every process you start from the session would carry
  `PORTAL_ROOT=<staging root>`, and the liveness checks below could not tell them from the wrapper's. The real cron commands are unaffected: the cron line sets `PORTAL_ROOT=` explicitly in the command's own environment, and `wrap` and `rwrap` pass it
  explicitly through `env -i`.
- `rel_path_ok` (called by `rel_ok`, so by `art`, `link_ok` and the unpack) proves `REL` is exactly `<staging root>/releases/<id>` inside a real (non-symlink) `releases/` directory: no traversal, no double slash, no placeholder, nothing outside it.
- `htaccess_check`, `htaccess_adopt`, `htaccess_apply` and `htaccess_ok` are the only code that ever touches a release's `public/.htaccess` ([phase 8](#phase-8-select-the-release-and-serve-it-g5)). They are narrow checks, not an `.htaccess` parser: they accept
  one bounded cPanel PHP 8.5 handler block and refuse everything else. `htaccess_apply` compares **canonical paths** (`readlink -f`) to decide whether the release is selected, so a `current` link written as an absolute path or through an alias is
  recognised, and a `current` that is not a link to a release (broken, a directory, or pointing outside `releases/`) is a refusal; the check is repeated immediately before the replacement. It writes to a **`mktemp` file created exclusively
  beside the target** (a pre-existing or planted `.htaccess.new` is never used or followed), removes that file on any failure or interrupt, and replaces the target atomically only after the archived bytes are confirmed as the exact prefix.
- `wk_ok`, `wk_content_ok`, `wk_link`, `wk_symprobe` and `acme_probe` are the only code that touches the ACME link or the ACME directory ([TLS and ACME](#tls-and-acme-acmesh)). `link_ok` includes `wk_ok`, so `art` refuses a release whose link is missing,
  has other text, resolves anywhere but exactly `<canonical $HOME>/portal-staging-holding/.well-known`, or whose target is a link, foreign-owned or world-writable. `wk_link` only creates (any existing path stops it) and works only on an unselected release.
  `wk_content_ok` is separate from `link_ok` because a token file may legitimately appear while a renewal runs. `acme_probe` writes one `portal-probe-<random>` file and removes it on every exit path; it removes nothing else.
- `lock_free` answers "is anything holding this lock right now" by trying to take it without waiting. **Holder records (`shared/run/*.holder`) persist by design**: they record the last holder and are never removed, so they say nothing about
  whether a process is running.
- `cron_runs` counts **active wrapper invocations** for this staging root, one line each, so a single run (the wrapper shell, `timeout`, PHP and whatever PHP starts) is **one**, not several. It matches the exact argument list `/bin/sh <root>/(current|releases/<id>)/scripts/cron/portal-cron.sh queue|schedule`,
  requires `PORTAL_ROOT=<root>` in the environment, and drops any match whose parent is itself a match (the wrapper's own subshells). It is the count to compare with a limit on concurrent cron jobs; `procs_env` is not ([acceptance B10](./staging-acceptance.md#b-filesystem-permissions-quota-resources)).
- `procs_env` and `procs_cmd` list only processes of **your account user**, and only those whose environment contains exactly the given line (the wrapper and everything it starts carry `PORTAL_ROOT=<its root>`), or whose
  command line contains the given staging path. A generic name such as `php` or `timeout` is never evidence of anything. They answer "is anything of staging still alive?", and their counts are **process** counts, not cron-job counts. `PORTAL_ROOT` is deliberately **not exported**, so your own shell's children do not carry the staging root and cannot be mistaken for the wrapper's processes.
- `db_ok` reads the name of the database the staging configuration actually connects to. The staging database user has privileges on that database only, so a wrong `.env` could not reach the existing portal's database in
  any case; `db_ok` makes the identity explicit before a migration or a destructive step.
- Replace `STAGING_DB` with the real name when it exists, and select a release with `setrel <id>` (never by editing `REL_ID` alone). Nothing in this procedure reads either before then.

## Procedure

Each phase lists what to expect and when to stop. **On any unexpected result, stop, keep the output, and report; do not retry blindly.** Multi-step host sequences are subshells that stop at the first failing step with an explicit `|| exit 1` (or an `&&` chain). They do **not** rely on `set -e`, which bash silently ignores when the block is pasted inside an `&&`/`||` list or run from a wrapper; `set -u` is kept, because an unset variable aborts a subshell regardless.

### Phase 0: build, rulings and the read-only host check (no host change; G0)

1. Build the archive on a development machine and verify it: [Release Build](./release-build.md). Record the SHA-256, the entry count, the `.htaccess` hash and `RELEASE`. The build prints the `EXTS_WEB` and `EXTS_CLI` lines for step 5.
2. Settle and record the G0 rulings. **The hostname already exists** (the owner's G1a, [Current state](#current-state-owner-reported-2026-10-22)), so verify it as found rather than as available: in cPanel Domains, the canonical name and the `www.` alias
   are the owner's, and no other domain, subdomain or alias uses either; `getent hosts portal-staging.intechral.solutions www.portal-staging.intechral.solutions` answers for both, with the account's address; no part of the name falls under an
   existing wildcard virtual host. Any other answer is an owner decision. (TLS is [acme.sh-managed](#tls-and-acme-acmesh); AutoSSL is not involved, and nothing here runs it.)
3. **Cron email: capture, then decide. Nothing is changed here.** The account-wide Cron Email (`MAILTO`) setting is shared by *every* entry in the account's crontab, including acme.sh. **The owner changed this crontab on 2026-10-22** (the diagnostic entry
   removed, a monitored cron email configured), so **any capture older than that is obsolete: discard it, and capture afresh now**. First, on your machine, one private
   **evidence directory**, outside every Git working tree, the web root and every release. Every file below is written through `ev`, an absolute path inside it, so running the commands from an unexpected working directory (the repository root, say)
   can never put them anywhere else:
   ```bash
   EVID="$HOME/portal-staging-evidence"      # on the OPERATOR's machine: absolute, outside every Git working tree, the web root and every release
   evid_ok() {
     [[ "${HOME:-}" == /* && "${EVID:-}" == "$HOME/portal-staging-evidence" && -d "$EVID" && ! -L "$EVID" ]] || { echo "EVID is missing, a symlink or not the expected path: STOP" >&2; return 1; }
     [[ "$(stat -c '%a %U' -- "$EVID")" == "700 $(id -un)" ]] || { echo "EVID is not mode 700 owned by you: STOP" >&2; return 1; }
     ! git -C "$EVID" rev-parse --is-inside-work-tree >/dev/null 2>&1 || { echo "EVID is inside a Git working tree: STOP" >&2; return 1; }
   }
   ev() { [[ "${1:-}" =~ ^[A-Za-z0-9._-]+$ ]] && evid_ok && printf '%s/%s' "$EVID" "$1"; }      # ev crontab.before -> its absolute path; prints nothing (so a redirect to it fails) if EVID is not safe
   ( umask 077; mkdir -p -m 700 -- "$EVID" ) && evid_ok && echo "evidence directory ready: $EVID"
   ```
   It holds `crontab.before`, `crontab.pre-g7`, `crontab.pre-g7a` (only if G7a is opened), `crontab.after`, `crontab.mailto`, `crontab.teardown`, `php-vhosts.before.json`, `php-vhosts.after.json`, and the certificate and ACME read-backs (`cert.last` and `cert.last.history`). These contain account paths and possibly addresses: they are never committed, pasted into a ticket or printed in a report. If `evid_ok` fails,
   `ev` prints nothing and the redirect fails before any command runs. Then:
   ```bash
   SSH_TARGET=REPLACE_WITH_YOUR_SSH_CONFIG_ALIAS
   ( umask 077; ssh "${SSH_TARGET:?}" crontab -l > "$(ev crontab.before)" )      # a private file in the evidence directory; never print or paste it
   grep -c '^MAILTO=' "$(ev crontab.before)"                                    # 1 if a MAILTO line exists, 0 if not (do NOT print the line)
   awk '/^[[:space:]]*(#|$)/{next} /^[A-Za-z_][A-Za-z0-9_]*=/{next} {n++; printf "entry %d: schedule=[%s %s %s %s %s] has-a-redirect=%s\n", n,$1,$2,$3,$4,$5, ($0 ~ />/ ? "yes: inspect it" : "no")}' "$(ev crontab.before)"
   ```
   The `awk` output is **only a heuristic**: it counts entries and notes whether a `>` appears, and nothing more. `2>&1` alone does not send output away from mail (it merely joins stderr to stdout, and both are still mailed); `> /dev/null` alone leaves stderr
   mailed; a command can also redirect inside a script it calls. So **read the actual entries privately** in the evidence file and work out, for each one, what really happens to its stdout and its stderr (discarded, appended to a file, or left to be mailed). Record
   only the conclusion per entry (for example "mails its output" or "silent"), never the command text. Record, without any address: whether a `MAILTO` line exists; whether it reaches a mailbox you actually monitor (confirm with an ordinary test mail from
   elsewhere, or read the address in cPanel Cron Email yourself); and which existing entries can mail. **Prefer to leave `MAILTO` unchanged** when it already reaches a monitored recipient: staging's failure notices then arrive there, alongside the
   existing entries' mail, and nothing else changes. The owner reports that a monitored address is now configured: that is a report, so confirm it here and record it as present or absent, never the address. Only if it is absent or unmonitored is a change proposed, as the separate sub-gate **G7a** ([phase 10](#phase-10-install-the-two-cron-entries-g7)), because the change is account-wide and also redirects the existing entries' notices.
   Confirm that the capture shows the **diagnostic entry absent** and the acme.sh entry present; the diagnostic is gone and is never put back ([G10](#gates-and-approvals)).
4. **Send the host support question** ([below](#host-support-dependency)). Staging may proceed in parallel; the survival test may not.
5. **Read-only host check** over SSH, with the session setup pasted and `EXTS_CLI` pasted from the build (a missing or empty `EXTS_CLI` stops it). The wrapper-specific tools and the tools this procedure uses:
   ```bash
   for t in flock timeout readlink date mkdir chmod head tail tr awk wc grep mv rm rmdir ln cp cmp diff sed cut od seq sleep stat find sha256sum tar gzip curl id ps; do
     command -v "$t" >/dev/null || echo "MISSING $t"; done                                    # prints nothing; any MISSING stops the install
   "$PHP" -r '$bad = 0; foreach (explode(",", $argv[1]) as $e) { $ok = extension_loaded($e); $bad += $ok ? 0 : 1; echo $e, $ok ? " ok" : " MISSING", PHP_EOL; }
              foreach (["pcntl_async_signals","pcntl_signal","pcntl_alarm","pcntl_signal_dispatch","posix_kill","proc_open"] as $f) { $ok = function_exists($f); $bad += $ok ? 0 : 1; echo $f, $ok ? " callable" : " MISSING", PHP_EOL; }
              exit($bad ? 1 : 0);' "${EXTS_CLI:?}" && echo "CLI extensions and functions: PASS" || echo "CLI extensions and functions: FAIL: STOP"
   ```
   **Fail closed:** a `MISSING` tool, extension or function, or `FAIL`, stops the install. In particular a missing **`exif`** is *not* fixed here: enabling an extension through the CloudLinux selector is an account-wide change that
   would also alter the existing portal's runtime, so it is an owner decision. The signal probes and the remaining read-only checks are rows A1 to A6 of [Staging Acceptance](./staging-acceptance.md#a-host-and-php).

### Phase 1: staging resources (G1, each sub-gate separate)

**G1a, hostname, alias, temporary document root, TLS: performed by the owner, ahead of the gate order.** The subdomain `portal-staging.intechral.solutions` and its `www.` alias exist, with the **temporary document root `portal-staging-holding`**
(a sibling of `$PORTAL_ROOT`, not inside it, which must still be free at G2), and a Let's Encrypt certificate for both names is installed by acme.sh ([TLS and ACME](#tls-and-acme-acmesh)). **Nothing is created, re-issued or renewed
here, and AutoSSL is not run for these names** (it could replace or compete with the acme.sh certificate). What remains of G1a is the **read-only observation**, which has not been recorded yet; run it, and save the output in the evidence record:

```bash
# from your machine
( set -u; H=portal-staging.intechral.solutions; A=www.$H
  getent hosts "$H" "$A"                                                                   # both answer, with the same account address
  for n in "$H" "$A"; do echo "== $n"; curl -sv -o /dev/null --max-time 20 "https://$n/" 2>&1 | grep -E '^\*  (subject|start date|expire date|issuer):'; done     # record these as text; the machine-readable record is cert_record (below), and later checks compare with the LATEST verified state, not with this one
  for n in "$H" "$A"; do curl -s -o /dev/null --max-time 20 -w "http://$n/ -> %{http_code} %{redirect_url}\n" "http://$n/"; done     # RECORD the redirect behaviour (D6); do not assume one
  curl -sI --max-time 20 "https://$H/" | grep -iE '^(HTTP|server):' )                       # the web server is LiteSpeed: see Hosting environment
ssh "${SSH_TARGET:?}" 'ls -la "$(readlink -f "$HOME")/portal-staging-holding" "$(readlink -f "$HOME")/portal-staging-holding/.well-known" "$(readlink -f "$HOME")/portal-staging-holding/.well-known/acme-challenge"'   # names, owners, modes: expect real directories of the account
ssh "${SSH_TARGET:?}" '"$HOME/.acme.sh/acme.sh" --list'                                  # read-only
ssh "${SSH_TARGET:?}" 'grep -hE "^(Le_Domain|Le_Alt|Le_Webroot|Le_NextRenewTimeStr)=" "$HOME"/.acme.sh/portal-staging.intechral.solutions*/portal-staging.intechral.solutions.conf' \
  | cert_record "$(ev cert.last)"     # the FIRST verified state (needs the helpers from "Certificate state across renewals"); refuses to overwrite; fails unless both names validate and the state is exactly the two staging names
```

Expect the certificate to name exactly the two hostnames, `Le_Webroot` to be `portal-staging-holding` for both, and the holding root to be a real directory owned by the account. Whether the holding root has an `.htaccess` is read at G1d step 1. In the cPanel
Domains page, record that the document-root field says `portal-staging-holding` literally. The redirect is observed, not enabled: do not turn on a control-panel redirect that writes into a release's `public/.htaccess`.
*Stop:* either name is missing from the certificate, `Le_Webroot` is anything else, the holding root or its `.well-known` is a link or foreign-owned, or the output differs from [Current state](#current-state-owner-reported-2026-10-22). The owner decides.
*Undo:* none from here. A temporary rollback leaves all of it in place; retiring it is [G9b](#rollback-and-teardown).

**G1b, database.** In cPanel (MySQL Database Wizard) create `STAGING_DB` and `<STAGING_USER>` with a generated password (store it in your password manager; it goes only into your local `.env`),
privileges **on this database only**. Set `STAGING_DB` in the session to the exact name cPanel shows. The wizard has no charset selector, so the database default is the server's latin1. Then, **while the database has no tables**, set its default:

```sql
-- phpMyAdmin, with the staging database selected (this empty database only; never an existing database)
ALTER DATABASE `REPLACE_WITH_STAGING_DB_NAME` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'REPLACE_WITH_STAGING_DB_NAME';
```

Replace both placeholders with the exact name from cPanel, and confirm that name is the selected database in phpMyAdmin before running either statement. Expect `utf8mb4` and `utf8mb4_unicode_ci`. The
[Database Profile](../architecture/database-profile.md) advises against `ALTER DATABASE` on an existing database; the reason is data and tables that already exist. On a database with no tables the statement only sets the default for
future tables. It affects this database only; **no server-wide default is touched**.
*Fallback, accepted if `ALTER` is refused or unavailable:* leave the database default latin1. Laravel names `utf8mb4` and `InnoDB` on every `CREATE TABLE`, and `database:verify` checks every table and column,
so correctness does not depend on the default. Record which case applied. Do not retry with other privileges or tools.
*Undo:* drop **this** database and user in cPanel, selecting them by the exact name.

**G1c, mail sink.** An owner-controlled mailbox or sink is chosen and verified by the owner, with host, port, TLS mode and credentials noted for the `.env`. Outbound staging mail goes only there.
*Undo:* delete the mailbox.

**G1d, the web PHP probe, the `.well-known` symlink probe and, only if needed, the per-domain PHP version.** This establishes, before any release exists, which PHP and which extensions the staging hostname's **web** handler really has, and whether this
web server follows the symlink that the releases will use for ACME, without touching the existing portal, the ACME directory or any account-wide setting. It needs its own approval because it places temporary diagnostic files in the temporary document root.
**None of it has been performed**; neither a PHP version nor the symlink behaviour is assumed from the owner's reports.

1. **Record the baseline of the existing site's runtime (read-only), before anything.** In cPanel MultiPHP Manager read, and save privately, the PHP version and handler of **every** domain. If UAPI is available you can read the same list from your machine:
   `( umask 077; ssh "${SSH_TARGET:?}" 'uapi --output=json LangPHP php_get_vhost_versions' > "$(ev php-vhosts.before.json)" )` (if it is unavailable, save a screenshot instead; nothing here assumes it exists). Put only the existing portal's row, and "all other rows recorded", in the evidence record.
   Record the temporary document root's `.htaccess` state: `ls -la "$HOLD"` and, if the file exists, its `sha256sum`. **It should not exist yet.** If it does, stop for owner review: nothing could then tell cPanel's additions from earlier content.
2. **Probe the staging hostname's web PHP** (the version, the SAPI, every required extension, and the settings that matter after a symlink swap). `EXTS_WEB` is pasted from the build:
   ```bash
   PROBE="ext-check-$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n').php"        # an unguessable name
   (
     set -u
     root_ok || exit 1
     HOLD="$(readlink -f -- "$HOME")/portal-staging-holding"
     [[ -d "$HOLD" && ! -L "$HOLD" && "$PROBE" =~ ^ext-check-[0-9a-f]{16}\.php$ && "${EXTS_WEB:-}" =~ ^[a-z0-9_,]+$ ]] || { echo "probe preconditions failed (temporary document root, probe name or EXTS_WEB): STOP" >&2; exit 1; }
     trap 'rm -f -- "$HOLD/$PROBE"' EXIT                                          # the file is removed even if the request fails
     ( umask 022; cat > "$HOLD/$PROBE" <<'PHP'
   <?php
   header('Content-Type: text/plain');
   $missing = 0;
   echo PHP_VERSION, ' ', PHP_SAPI, "\n";
   foreach (explode(',', '__EXTS__') as $e) { $ok = extension_loaded($e); $missing += $ok ? 0 : 1; echo $e, $ok ? ' ok' : ' MISSING', "\n"; }
   foreach (['disable_functions', 'memory_limit', 'realpath_cache_ttl', 'opcache.revalidate_freq', 'opcache.validate_timestamps', 'display_errors'] as $k) echo $k, '=[', ini_get($k), "]\n";
   echo $missing === 0 ? "RESULT=PASS\n" : "RESULT=FAIL\n";
   PHP
     ) || exit 1
     sed -i "s/__EXTS__/$EXTS_WEB/" "$HOLD/$PROBE" || exit 1
     curl -fsS "https://$STAGING_HOST/$PROBE"
   )
   curl -s -o /dev/null -w 'after deletion: HTTP %{http_code}\n' "https://$STAGING_HOST/$PROBE"       # 404
   ```
   (The heredoc terminator `PHP` must start in column 1 when you paste this; it prints nothing secret: no `phpinfo()`, no paths, no environment.) Record the output: the version and SAPI, `RESULT`, `disable_functions`, `realpath_cache_ttl` and the OPcache values (they decide how long a swap can look stale: [Later releases](#later-releases)).
   Compare the temporary document root's `.htaccess` with step 1: a handler block appearing there tells you PHP selection is written into `.htaccess` in the document root (it is then validated and carried into the release in phase 8); nothing appearing tells you it is not.
   **2a. Probe the `.well-known` symlink** (the mechanism the releases rely on for ACME). `wk_symprobe` builds a throw-away tree `HOLD/wk-probe-<random>/{target,dir}` with `dir/.well-known -> ../target`, fetches one random token through
   it over https on both names, compares the body exactly, and removes the tree. It never reads or writes `HOLD/.well-known`, and it runs only against `HOLD` (the helpers refuse anything else). It checks the web server's symlink policy for a link and a target
   with the same owner; it does **not** replace the G5 check, which goes through the real release and its rewrite rules:
   ```bash
   wk_symprobe          # expect two PASS lines and RESULT=PASS; then: ls -la "$HOLD" shows no wk-probe-* left
   ```
   *Stop:* `RESULT=FAIL`. Then this web server does not follow the link, the releases cannot serve ACME challenges this way, and **no release is selected** until the owner rules on another way. Do not work around it from here (no change to acme.sh, no second ACME directory, no server setting).
3. **Pass:** the web PHP is **8.5.x** and `RESULT=PASS`. If the staging hostname already runs 8.5.x, **change nothing**.
4. **If the web PHP is not 8.5.x**, and only then: in cPanel MultiPHP Manager select **the staging hostname only** and its PHP 8.5 option, then repeat step 2. **Only a per-domain setting is permitted.** Do not use the CloudLinux "Select PHP Version"
   extension or option toggles: they apply to the whole account. If per-domain selection is not available, **stop for an owner decision**.
5. **Re-read the baseline after every per-domain PHP configuration change** (step 1 again, to `php-vhosts.after.json` through `ev`, and compare). If the existing portal's version or handler, or any other domain's, differs from the baseline in any way: **immediate stop.** Do not try to
   correct it from here; report it, and the owner decides how to restore the recorded value.
6. **If a handler block appeared in `$HOLD/.htaccess`, validate it now (read-only):** `htaccess_check "$HOLD/.htaccess"`. It must report nothing and succeed. An unfamiliar format is **not** accommodated by relaxing the check: stop for owner review.
7. **Stop** also if any extension is `MISSING` (above all `exif`): enabling it account-wide is an owner decision, not a step of this procedure. *Undo:* the probes remove themselves; a per-domain PHP change is reverted by selecting the previously recorded version for the staging hostname only.

### Phase 2: host layout (G2)

```bash
( set -u
  root_ok || exit 1
  test ! -e "$PORTAL_ROOT" || { echo "$PORTAL_ROOT already exists: STOP" >&2; exit 1; }          # an existing path stops the sequence
  mkdir -m 0755 -- "$PORTAL_ROOT" "$PORTAL_ROOT/releases" || exit 1
  umask 077
  mkdir -p -- "$PORTAL_ROOT/incoming" "$PORTAL_ROOT/shared"/{home,run,logs} \
      "$PORTAL_ROOT/shared/storage"/{app/private,app/public,framework/cache/data,framework/sessions,framework/views,logs} || exit 1
)
root_ok && find "$PORTAL_ROOT" -maxdepth 2 -type d -printf '%m %p\n' | sed "s#$HOME/##"      # releases 755; shared and its children 700; incoming 700
```

*Undo:* remove `$PORTAL_ROOT` with the guarded command under [Teardown](#rollback-and-teardown).

### Phase 3: upload and unpack (G3)

Follow [Release Build, upload and verify](./release-build.md#upload-and-verify-on-the-host-a-host-step-gated) exactly, after `setrel <REL_ID>`. Expect: checksum `OK`; the file count equal to the recorded one; `RELEASE` matching the build;
modes 755/644/755; nothing group- or world-writable; the installed `public/.htaccess` equal to the baseline hash. Then, in the session:

```bash
rel_ok && ( cd -- "$REL" && env -i PATH=/usr/bin:/bin "$PHP" -r 'require "vendor/autoload.php"; echo "PHP version and 64-bit check passed", PHP_EOL;' )
```

This is **only** Composer's version and architecture check. It says nothing about extensions (phase 0 and G1d did that, directly). A failure here names the PHP version problem: stop.
*Undo:* remove `$REL` (it has no data), with `rel_ok` passing first.

### Phase 4: private configuration (G4 begins)

On your machine, copy `src/.env.staging.example` to a private file **outside the repository**, mode 600, replace every `__TOKEN__`, and check:

```bash
grep -nE '__[A-Z0-9_]+__' .env.staging        # must print nothing
```

Generate the key on your machine (`echo "base64:$(openssl rand -base64 32)"`); it exists nowhere else. Then:

```bash
# from your machine: staged in the 0700 incoming directory, restricted, then moved into place
ENVFILE=REPLACE_WITH_PATH_TO_YOUR_PRIVATE_ENV_FILE
scp "${ENVFILE:?}" "${SSH_TARGET:?}:portal-staging/incoming/env.new"

# on the host
( set -u
  rel_ok || exit 1
  chmod 600 -- "$PORTAL_ROOT/incoming/env.new" || exit 1
  mv -T -- "$PORTAL_ROOT/incoming/env.new" "$PORTAL_ROOT/shared/.env" || exit 1
  ln -s ../../shared/.env "$REL/.env" || exit 1             # SYMLINKS: never copy the .env or the storage into a release
  ln -s ../../shared/storage "$REL/storage" || exit 1
)
wk_link                                                     # the ACME link (below): creates public/.well-known only if nothing is there, on this unselected release
env_ok && link_ok && echo "env 0600, release linked to the shared files and to the ACME directory"
grep -cE '__[A-Z0-9_]+__' "$PORTAL_ROOT/shared/.env"                             # 0
grep -E '^(APP_ENV|APP_DEBUG|APP_URL|CACHE_STORE|CACHE_PREFIX|SESSION_DRIVER|SESSION_SECURE_COOKIE|QUEUE_CONNECTION|DB_QUEUE_RETRY_AFTER|LOG_STACK|PORTAL_CUSTOMER_SURFACE_MODE)=' "$PORTAL_ROOT/shared/.env"
```

Only those non-secret names are printed: never print the whole file, `APP_KEY` or a `*_PASSWORD`. The uploaded file is briefly created with the host's default mode inside the 0700 `incoming` directory (not reachable by other accounts) before the `chmod`.
Delete the local `.env.staging` when staging is torn down; keep it in the password manager meanwhile.

**The ACME link is a controlled addition, not a change to the archive.** `wk_link` adds one relative symlink, `public/.well-known -> ../../../../portal-staging-holding/.well-known`, to the unpacked, **unselected** release, next to the two links above. It refuses when
`public/.well-known` already exists in any form, when the release is selected, or when the shared ACME directory is not a real, account-owned, appropriately populated directory (`wk_content_ok`). It records the release, the link text and the canonical destination in
`shared/home/wellknown-link.log`. The archive, its checksum and the release's `RELEASE` file are untouched, and are shown to be (acceptance [B12](./staging-acceptance.md#b-filesystem-permissions-quota-resources)). After this phase the release holds exactly **three** symlinks that are not in the
archive (`.env`, `storage`, `public/.well-known`; the archive has none), so a file count taken now is the archive's plus three, and the count at G3 (taken before any link) is the one that equals the recorded number.
`wk_link` is also the step for any **rollback target** or later release that lacks the link, while it is unselected. It is never applied to a selected release, which is never edited.

### Phase 5: configuration cache and the configuration check

```bash
cc                                        # "config cache written, mode 600". umask 077 inside art; the mode is verified, and a cache that is not 0600 stops here
art tinker --no-ansi --execute='$v = App\Support\Runtime\RuntimeProfile::configuration(); echo count($v), " violation(s)", PHP_EOL; foreach ($v as $x) echo " - $x", PHP_EOL;'
```

Expect `0 violation(s)`. This is `RuntimeProfile`'s configuration-only check (every rule `runtime:preflight` and the A3 startup guard enforce) and touches no database. Each violation names the
variable to fix; fix it in `shared/.env` (then `env_ok`), run `cc`, and repeat. The full `runtime:preflight` needs the tables, so it comes after the migration. **Every later `config:cache` in this procedure, including the pilot
activation and every rollback, is `cc`; never `art config:cache` directly.**

### Phase 6: database defaults and migration

```bash
db_ok                                      # connected to the staging database: <name>; anything else stops here
art tinker --no-ansi --execute='
$s = DB::selectOne("select default_character_set_name cs, default_collation_name co from information_schema.SCHEMATA where SCHEMA_NAME = database()");
echo "database default: $s->cs / $s->co", PHP_EOL;
echo "tables: ", DB::selectOne("select count(*) n from information_schema.TABLES where TABLE_SCHEMA = database()")->n, PHP_EOL;
echo "server: ", DB::selectOne("select version() v")->v, PHP_EOL;'
```

Expect the name to equal `STAGING_DB`, `utf8mb4 / utf8mb4_unicode_ci` (or latin1 if the fallback was recorded), `tables: 0`, and a `11.4.x-MariaDB` server. A connection error is a `.env` or privilege problem; a name that is not the staging database,
or `tables` other than 0, means the wrong database: **stop**. Then the migration, only if `db_ok` passes in the same command, under `APP_ENV=production` and the cached configuration so the migration guard applies:

```bash
db_ok && art migrate --force
art database:verify                       # must exit 0: the authoritative post-migration check
art runtime:preflight                     # must exit 0: tables, cache round trip, cache lock (writes one cache row and one lock row, removed)
```

Expect every migration to run and `database:verify` and `runtime:preflight` to exit 0. If `migrate` is refused, read [Database Profile section 4](../architecture/database-profile.md#4-migration-time-guard-production-only):
a `MigrationsStarted` refusal applied nothing; a `MigrationsEnded` refusal applied the migrations **before** refusing. The staging database holds no data, so the recovery is to drop and recreate it in cPanel (re-doing the collation step), after saving the output
as evidence. **Before dropping anything, run `db_ok` and select the database in cPanel by the exact name it printed.** Do not keep migrating into a half-profiled database.

### Phase 7: roles, first operator, pilot mode (still unserved)

```bash
FP=REPLACE_WITH_THE_PLAN_FINGERPRINT       # printed by the dry run below
OPERATOR_EMAIL=REPLACE_WITH_AN_OWNER_CONTROLLED_ADDRESS
art roles:sync                             # dry run. A fresh database shows only additions (permissions and the two built-in roles); any REVOKE line means stop
[[ "$FP" =~ ^[0-9a-f]{16}$ ]] && art roles:sync --apply --plan="$FP"
[[ "$OPERATOR_EMAIL" == *@* && "$OPERATOR_EMAIL" != REPLACE_* ]] && art operator:bootstrap "$OPERATOR_EMAIL"    # needs a terminal (ssh -t): asks for a strong password twice, hidden
art customer-surface:preflight --expect-mode=closed                    # must pass now
art tinker --no-ansi --execute='echo "can portal.operator: ", var_export(App\Models\User::orderBy("id")->first()->can("portal.operator"), true), PHP_EOL;'
art tinker --no-ansi --execute='echo "jobs queued: ", DB::table("jobs")->count(), PHP_EOL;'
```

`roles:sync` creates the missing permissions and roles itself; **no seeder is run** (the seeders do not reset roles outside local and testing, and `DevSeeder` must never run here). `customer-surface:preflight` is
a read-only report that fails by design until its conditions hold: before `roles:sync` it reports that `portal.operator` does not exist, and between `roles:sync` and the bootstrap it reports `No active account holds
portal.operator`. Run it once after the bootstrap, where it must pass and the `can portal.operator` line (the first account, which is the operator just created) must print `true`. `operator:bootstrap` refuses once any administrator
exists, so it is run once. The first-operator address must be owner-controlled. Two-factor authentication for operators is **not enforced** by the application today (an open owner decision); the account can enable it under Profile.
Note the `jobs queued` number: anything queued will be sent to the sink when the first queue run happens.

Now switch to the pilot surface, still unserved, re-cache, and re-verify what the application actually resolves:

```bash
( set -u
  env_ok || exit 1
  sed -i 's/^PORTAL_CUSTOMER_SURFACE_MODE=.*/PORTAL_CUSTOMER_SURFACE_MODE=pilot/' "$PORTAL_ROOT/shared/.env" || exit 1
  env_ok || exit 1                         # still mode 600 after the edit
  grep -c '^PORTAL_CUSTOMER_SURFACE_MODE=pilot$' "$PORTAL_ROOT/shared/.env"        # 1
)
cc                                         # a new cache under umask 077; mode verified
art customer-surface:preflight --expect-mode=pilot
art tinker --no-ansi --execute='echo "resolved mode: ", config("portal.customer_surface_mode"), PHP_EOL;'      # pilot
art runtime:preflight
```

Expect no failure and `resolved mode: pilot`. `legacy` would resolve to `closed` here and log a warning; `pilot` is the Release 0.9 surface (Customer Helpdesk, accounts and Home, secure project visibility;
finance, time tracking and task boards excluded on the server). Before any real customer is ever onboarded, `customer-surface:preflight --strict --expect-mode=pilot` must also pass; that belongs to
onboarding, not to this install. Once the install is verified, and **before** any deliberate configuration-break rehearsal, the verified-good copy is saved by the rehearsal setup (`env_backup`, [Staging Acceptance, section I](./staging-acceptance.md#i-failure-and-operational-rehearsals-needs-gate-g8)).

### Phase 8: select the release and serve it (G5)

**A release that is selected is never edited.** If the hostname's PHP handler lives in `.htaccess`, the handler block is added to the release *before* it is selected, from the block cPanel wrote while the hostname still pointed at the temporary
document root (G1d). The order below is the point: baseline, handler, validation, transfer, selection, then verification of what is actually served. Nothing in this phase touches the account-wide PHP selector, and nothing here assumes how cPanel behaves:
every cPanel-dependent step has a stop. The ACME link was added in phase 4, so it is already in the release, and **this phase is not successful until the ACME challenge has been retrieved through the served release** (step 9): a site that serves the application
but not the challenge path is a failed activation, because the certificate would not renew.

1. **Extract the baseline, always, and verify it against `RELEASE`.** The archive's original `public/.htaccess` is the reference for everything that follows:
   ```bash
   ( set -u
     rel_ok || exit 1
     tar -xzOf "$PORTAL_ROOT/incoming/portal-$REL_ID.tar.gz" public/.htaccess > "$PORTAL_ROOT/shared/home/htaccess.baseline" || exit 1
     want=$(sed -n 's/^htaccess_sha256=//p' "$REL/RELEASE"); got=$(sha256sum < "$PORTAL_ROOT/shared/home/htaccess.baseline" | cut -d' ' -f1)
     [[ -n "$want" && "$got" == "$want" ]] || { echo "htaccess.baseline does not match RELEASE: STOP" >&2; exit 1; }
     [[ "$(sha256sum < "$REL/public/.htaccess" | cut -d' ' -f1)" == "$want" ]] || { echo "the unpacked .htaccess is not the archived one: STOP" >&2; exit 1; }
     echo "baseline verified: $got" )
   ```
2. **The handler is selected first, per domain, before any difference is looked at.** G1d left the staging hostname on PHP 8.5.x with a per-domain setting, written (if cPanel writes it into a file at all) into the temporary document root, never into a release. Confirm that
   it is still in place and the existing portal is untouched (G1d step 5, a new `php-vhosts.after.json`). Do **not** select it again now: cPanel would write into whatever the document root currently is.
3. **Inspect and validate the handler, only now.** If `$HOLD/.htaccess` exists, its whole content is cPanel's addition (it was required to be absent at G1d). Validate it and keep it as the reviewed block (this refuses anything but one bounded, recognised cPanel PHP 8.5 handler block):
   ```bash
   htaccess_adopt                    # "reviewed block recorded" or a reason and STOP for owner review
   ```
   If `$HOLD/.htaccess` does not exist, the handler is not in a file: record "vhost-level or default" and skip steps 3 and 4's block work; phase 9 (A2's `web` row) is then the proof. An unfamiliar format (anything the check rejects) is **never** worked around by relaxing the check:
   it is an owner review.
4. **Transfer it into the unselected release.** The release is not yet selected (no `current` link points at it), which the helper enforces:
   ```bash
   htaccess_apply                    # appends the reviewed block, mode 644, records it; refuses a selected release, a modified target, or a second block
   htaccess_ok                       # public/.htaccess is exactly what was published
   ```
   This is an **intentional pre-publication difference from the immutable archive**. It is recorded in `shared/home/handler-carry-over.log` (release id, the archive's hash, the block's hash, the resulting hash, mode), the archive and its checksum still describe the artifact as built, and the
   archived bytes are an exact prefix of the result. The helper sets its own `umask 022` and `chmod 644`; it does not rely on the host's default umask.
5. **Select the release.** First the pre-selection checks, which are read-only: the release carries its verified ACME link and the shared directory holds only what may be public, and the certificate the site presents is on record
   (the G1a observation) so that step 9 can show it unchanged:
   ```bash
   link_ok && wk_content_ok && htaccess_ok          # all three must succeed; anything else is a STOP, and nothing is selected
   ```
   `current` must not exist, or must be a symlink; never a directory. Then the atomic selection (the same two commands every later swap uses):
   ```bash
   ( set -u
     rel_ok || exit 1
     if [ -e "$PORTAL_ROOT/current" ] && [ ! -L "$PORTAL_ROOT/current" ]; then echo "current is a real directory: STOP" >&2; exit 1; fi
     cd -- "$PORTAL_ROOT" || exit 1
     ln -s "releases/$REL_ID" current.new || exit 1
     mv -T -- current.new current || exit 1 )
   readlink "$PORTAL_ROOT/current"; readlink -f "$PORTAL_ROOT/current/public" | sed "s#$HOME/##"      # releases/<REL_ID>, then portal-staging/releases/<REL_ID>/public
   ```
   Then, in cPanel (owner), set the document root of the staging hostname to `portal-staging/current/public`. **Do not assume it is served through the link; read it back.**
6. **Read back the effective document root** from cPanel (the Domains page field, saved as text) and, if UAPI is available, from your machine:
   `ssh "${SSH_TARGET:?}" 'uapi --output=json DomainInfo single_domain_data domain=portal-staging.intechral.solutions'` (the `documentroot` value; if it is unavailable, the page text is the record). Record **whether it keeps `current/public` literally or has
   been resolved to a physical `releases/<REL_ID>/public` path.** If it was resolved to a physical release path, **stop**: every later swap would leave it pinned to the old release, and the design depends on the literal `current` link. That is an owner decision.
   Confirm cPanel did not replace the link: `[ -L "$PORTAL_ROOT/current" ] && echo "current is still a symlink" || echo "current was replaced by a directory: see below"`. If cPanel made `current` a real directory (it is then empty apart from `public/`),
   `rmdir "$PORTAL_ROOT/current/public" "$PORTAL_ROOT/current"` removes only empty directories; redo step 5 and set the document root again. This cPanel behaviour is not verified; the check is the control.
7. **Verify that nothing edited the served release.** `htaccess_ok` and `link_ok` again, after the document-root change and after **any** later cPanel action on the hostname (a PHP re-selection or a redirect toggle; AutoSSL is not run for these names). If either fails, cPanel wrote into
   the served release: **stop. Do not edit the served release.** Use the recovery loop below.
8. **Re-read the existing portal's runtime** after every per-domain PHP configuration change and once more now (G1d step 1 to a new file): **any difference from the original is an immediate stop.** Then check the site from outside:
   ```bash
   curl -fsS -o /dev/null -w '%{http_code} %{scheme}\n' "https://$STAGING_HOST/up"        # 200 https
   curl -s -o /dev/null -w '%{http_code}\n' "https://$STAGING_HOST/.env"                    # not 200 (404 or 403)
   curl -s -o /dev/null -w '%{http_code}\n' "https://$STAGING_HOST/bootstrap/cache/config.php"    # not 200 (404): the configuration cache is outside the web root
   curl -sI "https://$STAGING_HOST/login" | sed -E 's/(=)[^;]*/\1<value>/' | grep -iE '^(HTTP|set-cookie)'   # Set-Cookie: portal_staging_session=…; secure; httponly; samesite=lax; no domain=
   ```

9. **ACME challenge retrieval: the activation verdict.** The renewal is not tested by renewing; a random token is placed in the ACME directory and fetched through the served release over **http and https, on the canonical name and the `www.` alias**, and the body must match
   byte for byte. Run it with `setrel` on the release that was just selected, then check the certificate the site now presents against the **most recent verified state** (`cert.last`, from G1a or the last successful check), not against the original:
   ```bash
   acme_probe release        # four lines ending PASS, then RESULT=PASS (release). It records any redirect it was given: that is the D6 evidence, not a failure
   # from your machine (the certificate helpers are defined there)
   ssh "${SSH_TARGET:?}" 'grep -hE "^(Le_Domain|Le_Alt|Le_Webroot|Le_NextRenewTimeStr)=" "$HOME"/.acme.sh/portal-staging.intechral.solutions*/portal-staging.intechral.solutions.conf' | cert_check "$(ev cert.last)"
   ```
   Expect `UNCHANGED`. If a scheduled renewal has happened since the last state, it is `RENEWED` only when you set `RENEWAL_EVIDENCE` to where you saw acme.sh run; see [Certificate state across renewals](#certificate-state-across-renewals) for exactly what may and may not change.
   **A `RESULT=FAIL`, any `STOP` from the probe, or a `STOP` from `cert_check` (a changed hostname set, `Le_Domain`, `Le_Alt` or `Le_Webroot`, an invalid chain, an unevidenced new certificate) is a failed activation. A `3` is not a pass: nothing proceeds until the owner decides. Do not report this phase as successful, and do not run A2.** Return to the known-working state: set the document root back to `portal-staging-holding` in cPanel,
   remove `current` (the undo below), confirm `acme_probe holding` passes and `cert_check` still reports `UNCHANGED` (or an evidenced `RENEWED`), and report. Do **not** force a renewal, run AutoSSL, or edit acme.sh to investigate: the owner decides.

The `web` row of A2 in phase 9 must show **PHP 8.5.x**. That check, and `acme_probe release`, are repeated after every release selection, every swap and every rollback.

*Undo:* set the document root back to `portal-staging-holding` in cPanel, then remove the `current` symlink: `( root_ok && [ -L "$PORTAL_ROOT/current" ] && rm -- "$PORTAL_ROOT/current" )` (it refuses a real directory). Then `acme_probe holding` must pass: the holding root answers the challenge path itself, which is the known-working state the certificate was issued in.

**Recovery loop: phase 9 shows the wrong web PHP version (or step 7 fails).** The loop never edits a served release and never touches the account-wide selector:

1. **Take the release out of service.** First install: set the document root back to `portal-staging-holding` in cPanel, then remove `current` with the undo command above. Later release: roll `current` back to the previous good release (see *Ordinary rollback*); the failing release is then unselected.
2. **Select PHP again for the staging hostname only**, in MultiPHP Manager. cPanel now writes, if it writes a file, into the temporary document root. Re-read the existing portal's baseline: any difference is an immediate stop.
3. **Re-run the G1d probe.** It must report PHP **8.5.x** and `RESULT=PASS`.
4. **Re-validate.** If `$HOLD/.htaccess` changed, run `htaccess_check "$HOLD/.htaccess"`. The reviewed block (`shared/home/php-handler.block`) is replaced only by a deliberate owner decision: move the old one aside by hand, never overwrite it.
5. **If the release has no block yet,** `htaccess_apply` it (the release is unselected again). **If it already has a block that did not work, do not patch it:** the helper refuses a second block, and an id is never reused, so build and unpack a **new release** (a new id) and carry the block into that.
6. **Select again** (step 5 onward, including step 9, `acme_probe release`), re-verify `htaccess_ok`, and repeat phase 9.

If after one pass the web still reports the wrong PHP, stop for an owner decision rather than looping.

### Phase 9: the wrapper by hand, then A2 (G6)

Check the queue first (class names only), then run the wrapper exactly as cron does:

```bash
art tinker --no-ansi --execute='foreach (DB::table("jobs")->orderBy("id")->get(["id","payload"]) as $j) echo "#$j->id ", json_decode($j->payload)->displayName, PHP_EOL; echo DB::table("jobs")->count(), " job(s)", PHP_EOL;'
wrap queue;    echo "exit=$?"            # silent, exit 0
wrap schedule; echo "exit=$?"            # silent, exit 0
tail -n 4 "$PORTAL_ROOT/shared/logs/cron-queue.log" "$PORTAL_ROOT/shared/logs/cron-schedule.log"      # START … / END status=ok category=ok exit=0
stat -c '%a %n' "$PORTAL_ROOT"/shared/run "$PORTAL_ROOT"/shared/logs "$PORTAL_ROOT"/shared/run/* "$PORTAL_ROOT"/shared/logs/*     # directories 700, files 600
```

A drain sends whatever is queued to the sink. Then the cross-context check, **inside the 30-minute challenge window** and with the queue holding only what you expect:

```bash
CHALLENGE=REPLACE_WITH_THE_CHALLENGE_ID    # printed by --issue
art runtime:preflight --issue                                  # prints the challenge id and "valid until"
curl -fsS "https://$STAGING_HOST/up"                           # the real Namecheap web handler answers; it must reach PHP (no cache in front)
wrap queue;    echo "queue exit=$?"                            # the wrapper-launched real worker answers
wrap schedule; echo "schedule exit=$?"                         # the wrapper-launched real scheduler answers
[[ "$CHALLENGE" =~ ^[A-Za-z0-9-]+$ && "$CHALLENGE" != REPLACE_* ]] && art runtime:preflight --verify --challenge="$CHALLENGE"     # exit 0
```

Expect the line `Every context answered the challenge and agrees: CLI, web, queue and scheduler share the configuration, the release and the APP_KEY.` and a four-row table of `ok` with the PHP version and SAPI each ran under.
**Acceptance requires that the `web` row reports PHP 8.5.x.** A2 itself does not judge PHP versions, so read the row: a web row on any other version means the per-domain PHP setting is not in effect for the served release; stop and
use the [recovery loop](#phase-8-select-the-release-and-serve-it-g5) at the end of phase 8. Any `missing` or `differs` row also means stop and read [Reading a failure](../architecture/runtime-preflight.md#reading-a-failure). Do not substitute a local PHP server for the web leg.
If the challenge expires, `--issue` again.

### Phase 10: install the two cron entries (G7)

Preconditions: phases 1 to 9 passed; the existing `MAILTO` recorded and either confirmed monitored or changed under **G7a** (below); the **cron slot budget** below accepted; and a **fresh crontab baseline**, saved **from your machine** into the evidence directory,
so the diff afterwards is evidence.

**Three baselines, and which one is which.** They are different captures taken at different times, and none of them is ever "restored" from memory or from an older file:

| Name | What it is | File |
|---|---|---|
| **Adopted account baseline** | the account as the owner reported and you recaptured it at phase 0: the diagnostic entry gone, a monitored cron email, acme.sh the only entry. It is the state the owner chose; it is not "the original" account | `crontab.before` (phase 0) |
| **State before a separately approved G7a** | only if G7a is opened: the crontab captured immediately before the owner-approved `MAILTO` change, so that change can be reversed to exactly that state | `crontab.pre-g7a` (G7a step 0) |
| **Fresh G7 baseline** | whatever the crontab holds **immediately before** the two staging lines go in; the diff after install is against this | `crontab.pre-g7` (below), which becomes `crontab.before` if it supersedes the phase 0 capture |

```bash
# from your machine, immediately before adding the lines
( umask 077; ssh "${SSH_TARGET:?}" crontab -l > "$(ev crontab.pre-g7)" ); diff "$(ev crontab.before)" "$(ev crontab.pre-g7)" && echo "unchanged since phase 0"
```

If it differs from the phase 0 capture, the owner (or something else) changed the crontab since: read the difference privately, confirm with the owner that it is expected, then **supersede the baseline** (`mv "$(ev crontab.before)" "$(ev crontab.before.superseded)"` and
copy `crontab.pre-g7` to `crontab.before`). A baseline is never older than the last known owner change, and teardown compares with it, never with a capture that still holds the removed diagnostic entry or an earlier cron email.

**Cron slot budget.** Three counts must not be confused:

- **Configured entries**: the lines in the crontab. This budget assumes the crontab refreshed above (`crontab.pre-g7`) holds **exactly one entry, the existing acme.sh renewal**, so it holds three after G7. Check it before G7: if it holds anything else, the figures below are wrong and the owner recomputes them before G7.
  ```bash
  awk '/^[[:space:]]*(#|$)/{next} /^[A-Za-z_][A-Za-z0-9_]*=/{next} {n++} END{print n+0, "entry(ies); 1 expected"}' "$(ev crontab.pre-g7)"
  grep -v '^[[:space:]]*#' "$(ev crontab.pre-g7)" | grep -c 'acme\.sh'                                 # 1: that one entry is the acme.sh renewal (a count; never print the line)
  ```
- **Concurrent cron invocations**: how many entries are running at the same moment. This is what a limit on concurrent cron jobs counts; the account allows five and counts overlapping runs **as reported by Namecheap support, which this procedure has not verified**. Measured with `cron_runs`.
- **Child processes**: one invocation is several processes (the wrapper shell, `timeout`, PHP, and what PHP starts). `procs_env` counts these. It is a liveness diagnostic and is **never** compared with a cron-job limit.

With that single acme.sh entry, the worst case where everything coincides is: scheduler holder, queue holder, one brief contender each (the next tick landing while a holder still runs) and acme.sh, that is **five invocations, which is the reported limit** (it was six with the diagnostic, which the owner removed).
In normal operation the scheduler and a drain last a few seconds, so only one or two overlap. The budget is still tight, and the survival test (G11) would make the holders long-lived, so it needs the human Namecheap answer first. **Neither a process count nor an invocation count proves
the host's concurrency policy**: the evidence about the policy is the human support answer (pending) and the absence of any host notice, which is an observation and not a proof.

**The exact lines.** Cron needs absolute paths, and the string after `PORTAL_ROOT=` is also what identifies staging's processes later. Generate the lines from the verified path rather than typing it, and check them:

```bash
root_ok && [[ "$PORTAL_ROOT" =~ ^/[A-Za-z0-9._/-]+$ ]] && [ -f "$PORTAL_ROOT/current/scripts/cron/portal-cron.sh" ] && [ -x /bin/sh ] && [ -x "$PHP" ] && printf '%s\n' \
  "*/5    * * * *   PORTAL_ROOT=$PORTAL_ROOT /bin/sh $PORTAL_ROOT/current/scripts/cron/portal-cron.sh schedule" \
  "2-59/5 * * * *   PORTAL_ROOT=$PORTAL_ROOT /bin/sh $PORTAL_ROOT/current/scripts/cron/portal-cron.sh queue"
```

In cPanel Cron Jobs, add exactly the two printed lines (the five schedule fields in cPanel's fields, the rest as the command); no redirect, no `--env`, no other change.

```bash
# from your machine
( umask 077; ssh "${SSH_TARGET:?}" crontab -l > "$(ev crontab.after)" ); diff "$(ev crontab.before)" "$(ev crontab.after)"     # exactly two added lines (and nothing else, MAILTO included, changed)
```

Expect, within ten minutes, `START` and `END status=ok` records in both wrapper logs, with `slot_offset_s`, and **no cron email**. The entries are identified by `PORTAL_ROOT=…/portal-staging`; removing them is
removing exactly those two lines.
*Undo:* remove those two lines in cPanel; `diff "$(ev crontab.before)" <(ssh "${SSH_TARGET:?}" crontab -l)` shows no difference. That is the only restoration: the diagnostic entry is **not** put back and the owner's cron email is **not** reset, because neither is in the baseline. (A separately approved G7a is reversed on its own, from `crontab.pre-g7a`.)

**G7a, the account-wide Cron Email (only if needed; never combined with G7; not read-only).** Needed only when phase 0 found no `MAILTO`, or one that nobody monitors; the owner reports a monitored address is configured, so it is expected **not** to be needed. Prerequisites: the output behaviour of the existing entries known
(phase 0); the owner has approved this gate naming the exact new recipient. It is a change to the whole account's cron notifications: acme.sh will mail the new recipient too.

0. **Record the state before the change, now, immediately before it:** `( umask 077; ssh "${SSH_TARGET:?}" crontab -l > "$(ev crontab.pre-g7a)" )`. This is the restoration target. It is the account as it stands today (the adopted baseline, plus anything the owner has changed since), **not** an older capture.
1. Change the address in cPanel Cron Jobs, Cron Email (the host stores it as the `MAILTO` line of the account crontab).
2. Verify from your machine: `( umask 077; ssh "${SSH_TARGET:?}" crontab -l > "$(ev crontab.mailto)" ); diff "$(ev crontab.pre-g7a)" "$(ev crontab.mailto)"` shows **only the `MAILTO` line** changed (or added). Anything else changed means stop. G7 then follows, and its fresh baseline (`crontab.pre-g7`) includes the new address.
3. Restoration, recorded **before** the change in step 0: the previous value (from `crontab.pre-g7a`) or "there was no `MAILTO` line". To restore, put the previous address back in the same cPanel field and diff again against `crontab.pre-g7a` (plus the two
   staging lines if still installed): no `MAILTO` difference. If the original had no `MAILTO` line and the cPanel field cannot remove it, that is why this change needs explicit approval first. **Never reinstall the saved crontab wholesale** unless the diff shows
   the `MAILTO` line is the *only* difference, because that would also discard any entry changed since.
4. Verify again after teardown ([Teardown](#rollback-and-teardown)).

### Phase 11: post-install

Run the [Staging Acceptance](./staging-acceptance.md) checklist. Its failure and operational rehearsals (G8) deliberately break and then repair the staging configuration; they are separate from installation.

## Later releases

`N` to `N+1` on the same staging root, after the first install. Each upload and each swap is still a host change that the owner approves for staging; production rules are decided later. Select the **new** release with `setrel <new id>` for steps 1 to 2 and 4 to 5;
the swap (step 3) selects it.

**Standard release** (no role-catalogue change, N-1-compatible migrations):

1. Build, upload and unpack the new release ([Release Build](./release-build.md)). Link `.env` and `storage` as in phase 4 (symlinks; never copies) and add the ACME link with `wk_link` (the release is unselected); `link_ok` must pass, and so must `wk_content_ok`.
   **A release without a verified ACME link is never selected**: after the swap the certificate could no longer renew.
2. `cc` in the **new** release, then `db_ok && art migrate --force`, `database:verify`, `runtime:preflight`, `roles:sync` (dry run: it must report *in sync*, or additions the release notes expect; a `REVOKE` line stops the release). This runs against
   the live database while the old release still serves, so every migration must keep the **previous** release working ([N-1 compatibility](../product/release-0.9-scope-and-architecture.md#6-migration-and-rollback-contract):
   additive, expand-contract, nothing destructive in the release that stops using a column). Migrations are forward-only; `down()` is not a recovery tool.
   - **Handler carry-over, before the swap** (only if phase 8 recorded a block). With `setrel` on the new, **unselected** release: `htaccess_apply` (it appends the reviewed block from `shared/home/php-handler.block`, mode 644, records it, and refuses a selected release,
     a modified target or a second block), then `htaccess_ok`. If phase 8 recorded no block (the handler is not in a file), there is nothing to carry. A failure is a stop: never relax `htaccess_check`, and never edit a release that is selected.
3. Swap atomically, from `$PORTAL_ROOT`: `( link_ok && wk_content_ok && cd -- "$PORTAL_ROOT" && ln -s "releases/$REL_ID" current.new && mv -T -- current.new current )` (`link_ok` includes the ACME link, so a release without it is not selected).
4. `art queue:restart` (a running old worker finishes its job and stops) and `art permission:cache-reset` ([Role Governance](../architecture/role-governance.md)), with `art` pointed at the new release.
5. **Check the served release is unchanged, wait for the web process to switch, then A2.** `htaccess_ok` first (nothing edited the selected release).  PHP resolves the `current` link through its realpath cache and a cached opcode cache, so the web handler can keep serving the previous release for up to `realpath_cache_ttl` (default 120 seconds; the G1d probe recorded the real
   value, and `opcache.revalidate_freq`) after the swap. Wait at least that long, then `--issue`, `curl /up`, `wrap queue`, `wrap schedule` (or the cron ticks), `--verify`. If the `web` row still reports the previous release after **twice** the recorded
   `realpath_cache_ttl`, **stop**: the effective document root may be pinned to the old release (phase 8, step 6). **The `web` row must also report PHP 8.5.x**; a different version means the handler did not carry over (step 2). Check `slot_offset_s` and the logs for the
   first ticks of the new release. After the wait, **`acme_probe release`** (with `setrel` on the new release) must pass, and `cert_check "$(ev cert.last)"` must pass against the most recent verified state (`UNCHANGED`, or `RENEWED` with a `RENEWAL_EVIDENCE` that names the acme.sh run; a swap never causes a certificate change, so an unexplained one is a stop):
   a swap whose challenge retrieval fails is a failed swap, so roll back (below) and report.

**Maintenance release.** Use it when the release changes the built-in role catalogue ([Customer Pilot Surface section 10](../architecture/customer-pilot-surface.md#10-deployment-operator-eligibility-and-rolessync)), when
a migration is not N-1 compatible or is risky ([Migration and rollback contract](../product/release-0.9-scope-and-architecture.md#6-migration-and-rollback-contract) items 5 and 6), or when the release notes say so.
Take a **verified, restorable database backup first** (`mariadb-dump --default-character-set=utf8mb4 --single-transaction`, never `--compatible`; check the restore procedure, not only the dump). Then: `art down` (from the old release, so
no request meets new code before the grant) → steps 1 to 2 with `roles:sync --apply --plan="$FP"` in place of the dry run → handler carry-over → swap → step 4 → set `PORTAL_CUSTOMER_SURFACE_MODE` if needed (edit, `env_ok`, `cc`) →
**`art runtime:preflight` and `art customer-surface:preflight --expect-mode=<mode>` once more, after the last configuration change** → `art up` → wait (step 5) → A2 (**after `up`, never before**: see [why](#why-this-order) item 4). While the application is down, **cron keeps running but is inert**: the worker exits 0 without taking
a job, the scheduler runs nothing (including the 02:00 UTC auto-close, which is then missed), and the wrapper logs `END status=ok`. Keep the window short, and do not read those `ok` lines as work done.

**Overlapping releases.** A wrapper invocation stays on the release it resolved at its start for at most about 270 seconds, so the previous release's worker can run against the new schema for that long
(hence N-1). The lock is shared across releases, so a new-release tick skips while an old worker holds it. A2's release identity is the physical directory, so a context still on the old release fails
`--verify`.

**Retention and cleanup.** Keep the current release and **at least the previous one**, and never remove a release in the first **15 minutes** after a swap (a worker loads classes lazily from its release directory). **Holder records persist after a run** (they
name the *last* holder and are never deleted), so they cannot show whether a release is still in use. Before removing an older release `OLD`, establish that nothing is running from it, scoped to your user and to that exact path, and that the locks are free:

```bash
release_unselected() {   # release_unselected <id>: current is a link to a real release directory and that directory is NOT <id>: canonical paths, never link text or REL_ID alone; anything unexpected fails
  local old=${1:-} cur dir
  [[ "$old" =~ ^[0-9]{14}-[0-9a-f]{7}$ && -d "$PORTAL_ROOT/releases" && ! -L "$PORTAL_ROOT/releases" && "$(readlink -f -- "$PORTAL_ROOT/releases")" == "$PORTAL_ROOT/releases" ]] || return 1
  [[ -L "$PORTAL_ROOT/current" && -d "$PORTAL_ROOT/current" && -d "$PORTAL_ROOT/releases/$old" && ! -L "$PORTAL_ROOT/releases/$old" ]] || return 1        # current missing, broken or not a link; or no such real release directory
  cur=$(readlink -f -- "$PORTAL_ROOT/current") && dir=$(readlink -f -- "$PORTAL_ROOT/releases/$old") || return 1
  [[ "${cur%/*}" == "$PORTAL_ROOT/releases" && "$dir" == "$PORTAL_ROOT/releases/$old" && "$cur" != "$dir" ]]                                         # current is a release and a different one
}
release_idle() (   # release_idle <id>: succeeds only if that release is safe to remove; says why not
  set -u; root_ok || exit 1
  old=${1:-}
  [[ "$old" =~ ^[0-9]{14}-[0-9a-f]{7}$ && "$old" != "$REL_ID" ]] && release_unselected "$old" \
    || { echo "'$old' is not an existing release other than the selected one (or current is not a link to a release): STOP" >&2; exit 1; }
  [[ -z "$(procs_cmd "$PORTAL_ROOT/releases/$old/")" ]] || { echo "a process of yours is running from $old: STOP" >&2; exit 1; }      # no process of yours runs from that release
  for t in queue schedule; do f="$PORTAL_ROOT/shared/run/$t.lock"; [[ ! -e "$f" ]] || lock_free "$f" || { echo "the $t lock is held: STOP" >&2; exit 1; }; done      # both locks are free right now
  echo "release $old is not in use"
)
release_remove() (   # release_remove <id>: the only way a release is removed: every check above, then the selected-release guard AGAIN immediately before the one exact rm
  set -u; release_idle "${1:-}" || exit 1
  release_unselected "$1" || { echo "current changed or is not a link to another release: refusing to remove: STOP" >&2; exit 1; }
  rm -rf -- "${PORTAL_ROOT:?}/releases/${1:?}"
)
OLD=REPLACE_WITH_THE_OLD_RELEASE_ID
release_idle "$OLD"          # also confirm by hand: 15 minutes have passed since the swap, and it is not the rollback target; only then: release_remove "$OLD"
```

Keep no more than three releases, to bound disk and inode use. Removal is one exact path, and only when the checks pass (a placeholder or malformed id fails them, so the `rm` never runs): `release_remove "$OLD"` (it runs `release_idle`, then checks again, immediately before the `rm`, that
`current` is a link to a different release). Both compare **canonical paths** (`readlink -f`), never link text or `REL_ID` alone, and refuse when `current` is missing, broken, not a link, or resolves outside `releases/`; never a pattern.

**Ordinary rollback.** Valid only if every migration since the previous release was N-1 compatible **and** the rollback reopens no access the current release closes. Check the second condition:
`art tinker --no-ansi --execute='echo DB::table("users")->whereNotNull("disabled_at")->count();'`; if it is not 0 and the previous release predates account suspension, do not roll back (fix forward). Then, after `setrel <the release you are returning to>`:
swap `current` back with the same two commands, `art queue:restart` and `art permission:cache-reset`, wait as in step 5, and A2 `--verify` with the same two requirements (the `web` row reports the returned-to release **and** PHP 8.5.x). The previous release's own
configuration cache was built from the `.env` of its day, and its handler block (if any) is whatever it had at the time; if `shared/.env` changed since, run **`cc`** (never `art config:cache` directly) and `runtime:preflight` in it first, and run
`htaccess_ok` for it before selecting it (it must still be exactly what was published, block included). **A rollback target has a verified ACME link before it is selected:** `link_ok && wk_content_ok` (a release that lacks the link gets `wk_link` while it is unselected, never after),
and after the swap and the same wait, **`acme_probe release`** (with `setrel` on the returned-to release) must pass; a rollback whose challenge retrieval fails is not a recovery, so return to a release whose retrieval passes and report. Exceptional recovery (restoring a backup) discards data written since and is the owner's decision, not part of this procedure.

## Rollback and teardown

Two different things are called "undoing staging", and they are never merged. A **temporary rollback or teardown** (G9) returns the host to the state the owner left it in: the document root is `portal-staging-holding` again, the hostname, alias, certificate, acme.sh and `HOLD` are exactly as they were.
**Permanent retirement** (G9b) removes the hostname, the certificate registration and `HOLD`, and is a separate owner decision with its own preconditions. Nothing in G9 calls acme.sh, and nothing in G9 or G9b removes a certificate on its own.

**During the first install,** step back one phase at a time, newest first: remove the cron lines (G7), and restore `MAILTO` if G7a changed it → detach the document root (set it back to `portal-staging-holding`) and remove the `current` link (G5), then `acme_probe holding` → drop and recreate
the database if the migration was involved (G1b), after `db_ok` and by exact name → remove the release directory and `.env` (G3/G4) → the remaining resources, **except** the hostname, certificate and `HOLD`, which belong to G9b.

**Teardown (G9, temporary).** The end of the rehearsal or a failed install. Save the evidence first (logs, command output) off the host; the `crontab.*` files are already in the evidence directory. Then, in this order, so no file is removed while the web server or cron can still reach it:

1. Remove the two cron lines in cPanel. From your machine: `( umask 077; ssh "${SSH_TARGET:?}" crontab -l > "$(ev crontab.teardown)" ); diff "$(ev crontab.before)" "$(ev crontab.teardown)"` shows no difference, where `crontab.before` is the **fresh G7 baseline**
   (phase 10), **not** an older capture. Nothing is restored from an older file: the removed diagnostic entry stays removed, and the owner's current cron email stays as the owner set it.
   **If, and only if, a separately approved G7a changed the cron email:** that change is reversed first, as recorded, by putting back the value in `crontab.pre-g7a`; the diff is then against `crontab.pre-g7a` instead (no difference). It is never restored from `crontab.before`, because that already holds the G7a address.
2. **Detach the document root.** In cPanel set the staging hostname's document root back to **`portal-staging-holding`**, the state its certificate was issued in. **Do not delete the subdomain, the alias or `HOLD`** (that is G9b). Do this **before** deleting any release file, then:
   ```bash
   acme_probe holding        # RESULT=PASS (holding): the holding root answers the challenge path itself, over both schemes and both names
   ```
   and confirm `cert_check "$(ev cert.last)"` passes against the most recent verified state (`UNCHANGED`, or an evidenced `RENEWED`), with the acme.sh listing and the four `Le_` keys as protected as before.
3. Confirm nothing of staging is running and no lock is held (holder records are history, not liveness):
   ```bash
   root_ok && procs_env "PORTAL_ROOT=$PORTAL_ROOT"; procs_env "PORTAL_ROOT=$PORTAL_ROOT/rehearsal"     # both must print nothing (cron wrappers and everything they start carry this line)
   for t in queue schedule; do f="$PORTAL_ROOT/shared/run/$t.lock"; if [[ -e "$f" ]]; then lock_free "$f" && echo "$t lock free" || echo "$t lock HELD: wait"; fi; done
   ```
   If anything is listed or held, wait and look again. Do not kill by name or from a holder record.
4. Remove staging files, but only if the path is exactly the staging root and nothing is running:
   ```bash
   ( set -u
     root_ok || exit 1
     [[ -d "$PORTAL_ROOT/releases" && -d "$PORTAL_ROOT/shared" && ! -L "$PORTAL_ROOT" && "$(readlink -f -- "$PORTAL_ROOT")" == "$PORTAL_ROOT" ]] \
       || { echo "not the staging root: STOP" >&2; exit 1; }
     [[ -z "$(procs_env "PORTAL_ROOT=$PORTAL_ROOT")" && -z "$(procs_env "PORTAL_ROOT=$PORTAL_ROOT/rehearsal")" ]] || { echo "staging processes are still running: STOP" >&2; exit 1; }
     for t in queue schedule; do f="$PORTAL_ROOT/shared/run/$t.lock"; [[ ! -e "$f" ]] || lock_free "$f" || { echo "the $t lock is held: STOP" >&2; exit 1; }; done
     rm -rf -- "${PORTAL_ROOT:?}" )
   ```
   `rm -rf` removes a symlink, never what it points at, so each release's `public/.well-known` link goes without touching `HOLD`. Prove it: `wk_content_ok` (the shared ACME directory is still a real, account-owned directory holding only what it held).
5. In cPanel: delete the staging database and its user (`db_ok` was true while it existed; select them by exact name), and the mailbox if it was created for this. **`HOLD` stays**: it is the ACME webroot of a live certificate and the document root the hostname now has.
6. Discard the staging `APP_KEY`, the database password and the local `.env.staging`. Never reuse them.
7. Confirm the protected resources are unchanged: `portal.intechral.solutions/`, the existing database, the acme.sh entry, the `MAILTO` setting (the `crontab.teardown` diff above), the acme.sh listing (`"$HOME/.acme.sh/acme.sh" --list`, and `cert_check` for the four `Le_` keys and the certificate against the most recent verified state; `Le_Domain`, `Le_Alt` and `Le_Webroot` unchanged),
   and **the existing portal's PHP version and handler (re-read the MultiPHP list against the original baseline)**.

**Permanent retirement (G9b): a separate owner decision, never part of G9, never automatic.** Retire the staging hostname only after G9 has completed and the owner has ruled, in writing, that staging is not coming back. Nothing here is run by this procedure or by an agent on its own account:

1. **Identify the exact certificate first, read-only:** `"$HOME/.acme.sh/acme.sh" --list` and the four `Le_` keys, recorded in the evidence directory: the main domain, the alternative name, the CA, the creation date and the next renewal. The owner confirms that this one entry, and no other, is the staging certificate.
   It must name exactly `portal-staging.intechral.solutions` and `www.portal-staging.intechral.solutions` and nothing else (a certificate that also covers another name is **not** retired by this procedure).
2. **The owner decides what happens to the certificate:** let it expire, or take it off acme.sh's renewal list with `acme.sh --remove -d portal-staging.intechral.solutions` run by the owner, naming that main domain exactly (acme.sh's documented effect is to stop renewing; it does not revoke the certificate and does not delete its files).
   Never `--remove` without `-d`, never a loop over `--list`, never anything for another domain. Uninstalling the certificate from cPanel, revoking it, and deleting its acme.sh directory are further separate decisions.
   **Order matters:** if the hostname and `HOLD` are removed while the certificate is still on the renewal list, acme.sh's next scheduled renewal fails and mails its error to the account's cron email.
3. **Only then** delete the subdomain and the alias in cPanel (by exact name), and remove `HOLD` last, and only if it is a real directory that the owner has inspected: `H="$(readlink -f -- "$HOME")/portal-staging-holding"; [ -d "$H" ] && [ ! -L "$H" ] && rmdir -- "$H"` (`rmdir` refuses a non-empty directory; its `index.html`
   and `.well-known/acme-challenge/` are the owner's to remove deliberately, never by a recursive command from here).
4. Confirm again that the acme.sh cron entry, the other certificates' registrations and the `MAILTO` setting are unchanged. The acme.sh cron entry is account-wide and **is never removed**.

## Host support dependency

**Pending: human Namecheap confirmation of the 270-second queue process lifetime.** The queue wrapper can keep one PHP CLI process alive for up to about 270 seconds (255 s `timeout` plus 15 s kill-after), launched every five
minutes; the scheduler bound is 255 s. The host has not confirmed that this is permitted, or that no shorter wall-clock limit exists. An automated support answer does not count, and neither do the two-second
signal probes. Until a human answer is recorded, no long-duration test is run (**G11**) and the worker timings do not change. The message to send:

> On our Stellar Plus account a cron job runs every 5 minutes and launches a PHP 8.5 CLI process that normally lasts 2 seconds but may, in the worst case, run for up to about 270 seconds before it is force-stopped; only one such process runs at a time for that job. Is a cron-launched CLI process of that duration permitted? Is there a shorter hard wall-clock limit? Are repeated 5-minute invocations of that duration allowed? Are there shared-hosting restrictions that would affect this pattern? Please answer in writing from a human agent.

If the host rejects the duration, the smallest alternative that keeps reservation safety is a shorter ceiling (for example `--max-time` 45 s, `--timeout` 60 s, `timeout` TERM at 120 s and kill at 135 s: `45 + 60 < 120` and `135 < 300`). It
is a wrapper constants change with tests, a separate package, and not part of this one.

## What this procedure does not do

It does not reconcile historical Release 0.9 status language beyond the lines of the documents it touched (WP-S0 N5: partially addressed; the rest is deferred and is not part of this package). It does not deploy production, does not authorise any host change by itself, does not install a catch-up for the 02:00 UTC task (G12, new owner ruling), does not run the survival test (G11), and does not
change the CI harness, the worker timings, `RuntimeProfile` or any application behaviour. It does not issue, renew, force-renew, install, remove or revoke a certificate, does not change acme.sh, its configuration or its cron entry, and does not
introduce a second ACME directory; it only keeps the existing one reachable from each release and checks that it is.
