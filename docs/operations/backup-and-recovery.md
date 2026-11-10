# Backup and Recovery (Release 0.9, WP-B1)

**Authority:** [Release 0.9 scope §7.1 and §8](../product/release-0.9-scope-and-architecture.md#7-backup-consistency-app_key-and-ticket-number-continuity),
[ADR-008](../architecture/adr/ADR-008-ticket-public-reference.md) (stale-identity safeguards on recovery). **Companions:** [Staging First Install](./staging-first-install.md)
(host layout, `PORTAL_ROOT`, the host PHP binary), [Cron Execution](../architecture/cron-execution.md) (maintenance mode makes both cron entries inert),
[Migration Rehearsal](../testing/migration-rehearsal.md) (the guarded rehearsal databases the restore drill reuses).

> **Status.** Implemented and tested in the repository only. **No backup or restore has been run on staging or production**, no cron entry exists for it, no key
> has been created, and nothing is uploaded anywhere. Every host step below needs the owner's go-ahead.
>
> **Recovery path (R1-0, 2026-11-10).** This document is the one recovery procedure ([owner ruling R1-0](../product/release-1.0-notes.md#data-deployment-and-recovery-ruling-r1-0)): there is
> no separate rollback to the Release 0.9 build. Restoring a set discards every write made after it. No authoritative production data exists yet, but **a verified backup and a tested restore on the
> real host must exist before any real customer is onboarded to production**; that drill has not been run and is an open requirement.

This is the smallest recovery capability Release 0.9 needs: one command makes an encrypted, self-describing backup set on the host; the owner pulls it off the host
by hand; a drill on the owner's machine proves it restores; and one guarded command restores it into an empty installation when that is ever needed.

| Command | Where | What it does |
|---|---|---|
| `php artisan backup:create` | host (or any installation) | Writes one sealed, encrypted set. Reads the database and private disk; changes nothing. |
| `scripts/backup/open-backup-set.sh` | owner's machine (private key) | Checks a sealed set and decrypts it into a new private directory. Needs bash, coreutils and gpg only. |
| `php artisan backup:verify` | anywhere | Sealed set: integrity. Opened set: contents (dump, manifest and files agree). Never a restore. |
| `php artisan backup:drill` | development machine | Restores an opened, authenticated set into the disposable rehearsal restore database and proves it. |
| `php artisan backup:restore` | the installation being recovered | Restores an opened, authenticated set into an **empty** database and private disk, while the application is down, and proves it. |

## 1. The backup set

A set is one directory named by its id (`20261102T164103Z-d5204c63`: UTC start time and 8 random hex characters). A sealed set holds exactly six files, all `0600`
in a `0700` directory:

| File | Contents |
|---|---|
| `database.sql.gz.gpg` | `mariadb-dump --single-transaction` of the application database: one consistent InnoDB snapshot |
| `attachments.tar.gz.gpg` | exactly the private attachment files the snapshot's `ticket_attachments` rows reference, as `tickets/{ticket id}/{file}` |
| `manifest.json.gpg` | derived **from the dump**: schema fingerprint, per-table row count and row digest, ticket count and id-to-reference fingerprint, canonical and legacy reference counts, migrations, the attachment inventory (row, path, size, SHA-256), live files outside the snapshot, source release, times, and the `APP_KEY` id and canary |
| `set.json` | plain: id, times, source environment and release, the recipient key, and the SHA-256 and size of every artifact, encrypted and decrypted. No customer data, credentials or key material. |
| `SHA256SUMS` | `sha256sum` lines for `set.json` and the three `.gpg` files |
| `COMPLETE` | written last: format, id, and the SHA-256 of `SHA256SUMS` |

**The set digest** is the SHA-256 of `SHA256SUMS`. `backup:create` prints it. **Record it when the set is made, and keep it independently of the set**: in the
evidence log or another place the backup directory, the host and whoever can write the set cannot change, never beside the set or in the same archive. It covers
`SHA256SUMS`, which covers every other file, so comparing it during recovery (`--expect-digest`) is what authenticates a set; a digest read from the set itself
proves nothing. A checksum file travelling with the set only proves
the set is intact, not that it is the set that was made. `backup:restore` and `backup:drill` therefore **require** `--expect-digest` (the drill accepts
`--unauthenticated` instead, for a format test that proves nothing about where a set came from); `backup:verify` and the opener accept it
and say when they ran without it.

**Completion rule.** A set is built in `.partial-<id>/` and becomes `<id>/` only after the plaintext is removed, `COMPLETE` is written and the sealed set has passed
its own verification; the rename is the last step. A set without `COMPLETE`, or whose `COMPLETE` does not match `SHA256SUMS`, is invalid. A failed run removes its partial
set. A **killed** run (SIGKILL, power loss) cannot clean up: it leaves `.partial-<id>/`, private (`0700`/`0600`) and possibly with plaintext inside, never named like
a set and never accepted by any verifier; the next `backup:create` in that directory removes it (only directories carrying its own marker).

**Encryption.** GnuPG public-key encryption to the owner's key. Only the **public** key is on the host. It is given as a file plus the fingerprint it must have; a
key file holding any other key, or more than one key, is refused. `gpg` runs with a throw-away home directory per run (never the account's keyring), and each encrypted
file is read back to confirm it is addressed only to that key. **Public-key encryption is confidentiality only. It is not a substitute for a trusted digest or a
signature**: anyone holding the public key (which is not secret) can produce a set that decrypts correctly with the owner's key. Release 0.9 authenticates a set by the
independently recorded digest above; it does not sign sets (a signing key on the shared host would be no more trustworthy than the host).

**What is not in the set.** `.env` and the plaintext `APP_KEY` (escrowed separately, H9). The manifest holds only a key id (16 hex characters of a SHA-256 of the key,
not reversible) and a **canary**: a random string encrypted with the live key, plus its SHA-256. A restore proves the escrowed key is the right one by decrypting it.

## 2. Why a set is consistent while the application is in use

1. **One database image.** `mariadb-dump --single-transaction` reads every table in one `REPEATABLE READ` snapshot. Every table must be InnoDB (the reader refuses any
   other engine, for which the snapshot would not hold). DDL during the dump makes `mariadb-dump` fail (MariaDB error 1412, observed), so a migration cannot produce
   a mixed set; a backup taken during one fails and must be repeated.
2. **The files follow the snapshot, not the clock.** The attachment rows are read **from the dump file**, and exactly the files they name are copied. An upload that
   commits after the snapshot is not in the dump, so its file is not copied; an upload in flight at the snapshot (file written, row not yet committed) is not in
   the dump either. Both are counted as *live files outside the snapshot* in the manifest, for information.
3. **Every referenced file existed at the snapshot.** The application writes an attachment's file *before* it inserts the row, in the same transaction
   (`TicketService::storeAttachment`), so a row the snapshot can see belongs to a file that was already written.
4. **Committed files are never deleted or changed.** That is the §7.1.4 precondition. In the application only `TicketService` touches the private disk (it stores new
   files, and deletes only the files a failing request itself wrote, before their rows commit), and `e2e:cleanup-tickets` deletes whole `tickets/{id}` directories but
   refuses to run outside `local` and `testing`. `tests/Unit/Backup/CommittedAttachmentFilesTest.php` pins exactly those call sites, so a new delete, move or overwrite
   fails the suite until it is reviewed against this section.
5. **And it is checked, not assumed.** A referenced file that is missing, is not a regular file (or sits in a symbolic-linked directory), differs in size from its
   row, was changed in a later second than the snapshot began, or changes while it is being copied **fails the backup**. File times are whole seconds, so the
   dump is started at the beginning of a second, and a same-size rewrite *within the snapshot's first second* would not be seen; point 4 is what excludes it.
   (If connecting to the database took longer than the rest of that second, an upload committed meanwhile would fail the backup spuriously; it fails closed and
   a retry succeeds.)

The ticket identity fingerprint and every other figure in the manifest come from the dump itself, never from a separate query against the live database.

`tests/Unit/Backup/BackupConsistencyTest.php` runs real backups while a second process uploads attachments the way the application does (some held in flight),
and requires every set to verify and restore with every check passing.

## 3. Host prerequisites and owner inputs

| Item | Value | Status |
|---|---|---|
| Tools on the host | `mariadb-dump` (11.4), `tar`, `gzip`, `gpg` (2.2), PHP CLI `/opt/alt/php85/usr/bin/php` with `proc_open`, `posix` and `pcntl` | Reported present (S3-B, 2026-10-31); **re-check** before the first run |
| Owner key pair | Generated **on the owner's machine**; the private key never leaves it. Export the public key and record its fingerprint: `gpg --armor --export <FPR> > intechral-backup-pub.asc`, `gpg --fingerprint <FPR>` | Owner action, not done |
| Public key on the host | `$HOME/portal-backup/backup-pub.asc`, mode `0600` | Owner action, not done |
| Backup directory | `$HOME/portal-backups`, mode `0700`, outside `PORTAL_ROOT`, every release and every document root. The command refuses one that is group- or world-accessible, a symbolic link, not owned by the account, or inside or containing the application, storage, public directory or private disk | Owner approval, not created |
| Free space and inodes | About twice the attachment volume plus twice the compressed dump, plus the uncompressed dump while it is read; one extra inode per attachment during the run. Each plaintext file is removed as soon as its encrypted form exists | Check `df` and the inode count first |
| `APP_KEY` escrow | Recorded by the custodian (H9), separately from every backup set, with the key id `backup:create` writes into the manifest | Owner action |
| Restore drill target | The development machine's rehearsal databases (`./dev rehearse:provision` once) | Available |

## 4. Making a backup (manual)

From the release directory whose code includes this tooling, on the host, never during a deployment or migration:

```bash
cd "$PORTAL_ROOT/current"     # or an unselected release directory that has .env and storage linked (see section 9)
umask 077
/opt/alt/php85/usr/bin/php artisan backup:create \
  --to="$HOME/portal-backups" \
  --recipient-key="$HOME/portal-backup/backup-pub.asc" \
  --recipient-fingerprint=<40-hex fingerprint from the owner>
```

Exit codes: **0** a complete set was written (the output names its directory and prints the set digest; record the digest); **1** the backup failed after it began
and no complete set exists (the message says why: a missing or changed file, a dump error, a full disk); **2** it refused before starting (unsafe directory, another
backup running, wrong key) and wrote nothing.

The database credentials are read from the application's own configuration and given to `mariadb-dump` through an inherited pipe (`--defaults-extra-file=/dev/fd/3`):
never on a command line (`ps`), in an environment or on disk. Nothing the command prints contains a credential, the key or row data. A second run in the same directory
while one is running is refused (`flock` on `.backup.lock`). A backup is read-only for the application; it can run while the site is up.

**Scheduling later.** The command is suitable for cron as it stands (non-interactive, lock, exit codes, no secrets in output), but **no schedule, retention or remote
destination is configured by this package**, and none may be added without an owner decision. Retention is manual: delete old sets by hand.

## 5. Off-host copy (staging rehearsal: manual pull)

On the owner's machine, pull the sealed set over SSH into a private directory, then check it before anything else:

```bash
umask 077
rsync -a --protect-args "<ssh target>:portal-backups/<id>/" "$HOME/portal-backups/<id>/"
scripts/backup/open-backup-set.sh "$HOME/portal-backups/<id>" "$WORK/<id>" --expect-digest <recorded digest>
```

The opener (bash, GNU coreutils and gpg: a Linux or WSL shell; it uses `mv -T`, which macOS lacks) refuses, before decrypting anything, a set that is not exactly the six files, whose `COMPLETE` does not match `SHA256SUMS`, whose digest is not the recorded
one, or any file that does not match `SHA256SUMS`; decryption needs the owner's private key and must report a good, integrity-protected result. It writes nothing unless
all three artifacts decrypt (it works in a temporary directory beside the output and renames it at the end).

**The opened directory is plaintext customer data.** Keep `$WORK` on an encrypted disk of the owner's machine (not `/tmp` on WSL, which is RAM), and delete it as soon as
the drill or recovery is finished.

## 6. Proving a set: verify and restore drill

```bash
php artisan backup:verify "$WORK/<id>" --expect-digest <recorded digest>          # contents: dump, manifest and every file agree
```

`backup:verify` also accepts a sealed set (integrity only). It never claims a set is recoverable. The **drill** is the proof:

```bash
# on the development machine, with the dev stack running; $WORK is mounted read-write (the tools work beside the opened set)
docker compose run --rm -T -u "$(id -u):$(id -g)" -e HOME=/tmp -v "$WORK:/backup-work" app \
  php artisan backup:drill /backup-work/<id> --app-key-file=- --expect-digest <recorded digest> \
  < "$WORK/app-key"                                  # the escrowed APP_KEY, in a 0600 file; delete it afterwards
# staging sets taken before C6-R is deployed hold the two legacy references: add --expect-legacy-references=2
```

The drill restores **only** into the rehearsal restore database (`intechral_client_portal_rehearsal_restore` on host `db`) and `storage/rehearsal/restore/files`, under
the rehearsal harness's own guard, re-checked before each destructive step: the environment must be `local` or `testing`, the database the reserved name and not the one
the application uses, the account not root and without global privileges, the database empty or carrying the harness marker. It reads no `DB_DATABASE`, so it cannot
be pointed at staging or production. One drill or rehearsal runs at a time.

It passes only when all of these hold:

1. The opened set verifies (as `backup:verify`).
2. The dump loads with the `mariadb` client (`--binary-mode --sandbox`, with the session's SQL mode and character set fixed by the loader itself). Before it
   runs, every statement is checked against an exact allowlist of what `mariadb-dump` 10.11, 11.4 and 11.8 write for one database: only their own session
   `SET` lines, each under its own version gate (so no other SQL mode or character set can make the server read quoting differently from the check), each `CREATE TABLE` straight after its own `DROP TABLE IF EXISTS`, plain unqualified identifiers, no
   comment or control character inside a statement, no `SELECT`, `USE`, `CREATE`/`DROP DATABASE`, trigger, routine, `GRANT`, `UPDATE`/`DELETE` or client command. The drill's database account is the shared
   development account, which also holds the development and testing databases, so this check and the set digest are what keep a crafted dump away from
   them (a restore-only account for the drill is a recommended follow-up; see the staging-rehearsal safeguards below).
3. The restored database, dumped again the same way, equals the manifest **table by table and row by row** (definition, count and digest of every row): so the
   same records, the same internal numeric keys, and therefore the same associations of replies, status histories, tasks, time entries and attachments to their
   tickets; the same ticket references; the same migrations.
4. The restored `tickets/` holds exactly the manifest's files (size and SHA-256), each inside its own `tickets/{ticket id}/`, nothing else, no links.
5. The escrowed `APP_KEY` decrypts the canary.
6. The rehearsal invariants hold on the restored data: every foreign-key and logical reference resolves (no reply, history, task, time entry or attachment points at
   a missing ticket), ticket references are canonical (`TK-XXXXX-XXXXX`) and unique, every attachment row has its file inside its own directory, and no file or ticket
   directory lacks its row. Legacy references are accepted only in the exact number given with `--expect-legacy-references`, and only if the set recorded the same number.
7. The runtime-state cleanup a recovery performs (sessions, database cache and locks, password reset tokens) runs.

The restored data is removed afterwards (`--keep` leaves it in the rehearsal restore target for inspection; `./dev rehearse:clean` removes it). Exit codes: **0** passed;
**1** a check failed (the output lists every failure); **2** refused, nothing changed.

### Safeguards for the staging rehearsal

These apply to the first staging backup and drill, and to every rehearsal after it:

1. **The staging database and private files stay untouched.** `backup:create` only reads them (one `REPEATABLE READ` snapshot, then file reads); it writes only
   inside the private backup directory outside `PORTAL_ROOT`. `backup:restore` is **never** run on staging for a rehearsal, and the drill runs only on the owner's
   development machine, against its own rehearsal restore database. Record the staging crontab, release link and `shared/.env` checksum before and after, as the
   staging runbooks already do, and compare them.
2. **The digest is recorded off the host** when the set is made, and given to the opener and the drill with `--expect-digest`; `--unauthenticated` is not used for
   the rehearsal.
3. **The legacy-reference restore path is proven before the C6-R conversion.** Staging still holds the two legacy `TKT-` references, so the set taken at ADR-008
   step 1 must pass `backup:drill … --expect-legacy-references=2` (the drill refuses any other count) **before** `tickets:rereference --apply` runs. That drill is
   what makes the step-1 set the rollback point the conversion relies on. *(R1-0 note, 2026-11-10: staging is now disposable and is rebuilt only when the owner authorizes it, so this safeguard may be moot for
   staging. It is kept until the owner confirms whether to retire it; it is not a Release 0.9 code-rollback obligation.)* (`BackupRoundTripTest` proves this path on fixtures: a real dump of a database holding
   `TKT-` references verifies, fails the drill unless exactly that count is expected, and passes when it is.)
4. **The disposable restore database uses narrowly scoped privileges wherever practical.** The drill's target is fixed (the rehearsal restore database), but on
   the development machine it connects with the shared `portal` account, which also holds the development and testing databases; the dump checks and the digest
   are what keep a crafted dump away from them today. The recommended next step is a dedicated account with privileges on the restore database **only**,
   provisioned like the rehearsal databases. Any restore drill run elsewhere (for example a host-class drill on cPanel) must use a database and user created for
   it, with privileges on that one database only, never the staging or production user.

## 7. Recovering the portal from one set

Recovery restores an older consistent state: everything written after the snapshot is lost, and references issued after it may (with the owner-accepted, very small
probability of ADR-008) be issued again. Security state reverts too: an account suspended, a password or two-factor setting changed, or a role revoked after
the snapshot is back as it was. If the recovery follows a security incident, re-apply those changes before reopening (R9). Decide that recovery is necessary before starting. Every step is explicit; none is automatic.

**R1. Freeze.** `php artisan down --secret=<random>` on the affected installation. Under maintenance mode the queue worker takes no job and the scheduler runs no
event ([Cron Execution](../architecture/cron-execution.md), item 7), so nothing writes and nothing is sent while you work. The secret lets you check the site before
reopening it. Never restore into an installation that is up.

**R2. Select and authenticate the set.** Choose the set by id from the evidence log, pull it, and open it with the **recorded** digest (section 5). Run `backup:verify` on
the opened set. Prefer a set that has already passed a drill; if time allows, drill it now.

**R3. Preserve what is there; provide empty targets.** Nothing is overwritten or deleted:

- **Database:** create a new, empty database (cPanel) and point the installation's `.env` `DB_DATABASE` at it, keeping the damaged database untouched for later
  analysis. Rebuild the config cache afterwards as the staging runbook does (`umask 077`). `backup:restore` refuses a database that has any table.
- **Attachments:** move the existing private `tickets/` aside on the same filesystem, never delete it:
  `mv "$PORTAL_ROOT/shared/storage/app/private/tickets" "$PORTAL_ROOT/shared/storage/app/private/tickets.pre-recovery-<UTC stamp>"`.
  `backup:restore` refuses while `tickets/` holds anything.

**R4. The key.** The installation's `APP_KEY` must be the escrowed key whose id is in the manifest. If `.env` was lost, restore it from escrow (owner). Do not rotate the key
as part of a recovery.

**R5. Put the opened set on the host.** The private key never goes to the host, so the owner uploads the **opened** set over SSH into a new `0700` directory
(`$HOME/portal-restore/<id>`, outside every release and document root), and checks it there with `backup:verify --expect-digest`.

**R6. Restore.**

```bash
/opt/alt/php85/usr/bin/php artisan backup:restore "$HOME/portal-restore/<id>" --confirm-database=<the new empty database> --expect-digest <recorded digest>
```

It refuses (exit 2, nothing changed) unless `--expect-digest` is the recorded digest, the application is in maintenance mode, `--confirm-database` names the configured database, the live connection reports
that database, it has no tables (checked again immediately before loading), the private disk has no `tickets/`, and the set verifies. Then it loads the dump, extracts
the files (`0600`/`0700`), proves the result equals the set exactly as the drill does (items 3 to 5), and only then invalidates the runtime state: **every session**
(no page from before the loss can submit anything; everyone signs in again), the database cache and its locks (including the permission-cache epoch) and password reset
tokens, and **every remember-me token** (`users.remember_token`), so no browser stays signed in through a "remember me" cookie. Exit **1** means the restored state is not the set: leave the application down, and start again from R3 with a new empty database.

**R7. Review the queue before anything runs.** The output lists queued jobs by class and the number of failed jobs. Nothing is replayed. Queued jobs restored from the
set are mail that may describe the older state or may already have been sent before the loss. Decide with the owner, before R10, whether to keep them (they run at the
first worker tick after `up`) or clear them (`php artisan queue:clear database`; record what was dropped). `failed_jobs` are never retried as part of a recovery.

**R8. Quarantine unexpected files.** Compare `tickets.pre-recovery-<stamp>/` with the restored `tickets/`. Files there that the restored database does not reference are
uploads made after the snapshot (their rows are lost) or debris; leave them in the quarantine directory for the owner to review. Never copy them into `tickets/`
(the restored database has no rows for them, and a restored id may now belong to a different ticket).

**R9. Re-check, then smoke test.** All must pass:

```bash
php artisan backup:restore "$HOME/portal-restore/<id>" --verify-only --expect-digest <digest>   # still the set (sessions/cache/queue tables by definition only; users without remember tokens)
php artisan permission:cache-reset
php artisan runtime:preflight                    # exit 0
php artisan tickets:rereference --check          # exit 0: every reference canonical (a pre-C6-R set needs the C6-R conversion instead)
```

Then, through the maintenance secret: sign in, open a ticket by its reference, download an attachment, and confirm an old numeric ticket URL is a 404.

**R10. Resume.** `php artisan up`. Watch the next cron ticks (`END status=ok`) and run the A2 cross-context check after `up` (never before). Record the recovery, the
loss window (snapshot time to the failure), and the queue decision. Tell affected customers that changes after the snapshot time are lost.

**R11. Clean up.** Delete the opened set on the host and on the owner's machine. Keep the sealed set, the damaged database and the quarantine directory until the owner
releases them.

**Undoing a recovery** before R10: point `DB_DATABASE` back at the original database, move `tickets.pre-recovery-<stamp>` back, rebuild the config cache. Nothing was
deleted.

## 8. Tests and negative controls

`tests/Unit/Backup/*` (152 tests) runs against the rehearsal databases (never the development or testing database) and throw-away GnuPG keys. Run it with
`./dev test:php --filter=Backup` once the `app` image has been rebuilt with the MariaDB client (`docker compose build app`). It covers:

| Control | Test |
|---|---|
| Incomplete set (missing `COMPLETE`, artifact or payload), extra file, permissions, links | `SetVerificationTest` |
| Corrupt or truncated archive, dump or artifact; an artifact from another set; a re-sealed forgery against the recorded digest | `SetVerificationTest` |
| Missing, resized, same-size-rewritten or symlinked attachment; a removed ticket directory (the e2e cleanup's operation) | `BackupCreationTest` |
| Unexpected file or directory, same-size content change, symbolic or hard link, and unsafe paths in the archive or in an attachment row | `SetVerificationTest` |
| Wrong or unavailable decryption key; wrong recipient key; wrong `APP_KEY` | `SetVerificationTest`, `BackupCreationTest`, `RestoreDrillTest`, `RecoveryTest` |
| Interrupted backup (exception, SIGTERM, SIGKILL and the next run's sweep); concurrent backups (lock) | `BackupCreationTest` |
| Concurrent uploads during real backups | `BackupConsistencyTest` |
| The committed-file precondition; the e2e cleanup refusing on staging and production | `CommittedAttachmentFilesTest` |
| Legacy, duplicate and non-canonical references; child rows pointing at a missing ticket | `RestoreDrillTest`, `SetVerificationTest` |
| A real dump of a pre-C6-R database with legacy `TKT-` references: create, open, verify, drill (exact count only) | `BackupRoundTripTest` |
| A non-rehearsal target for the drill; a non-empty or non-confirmed target, or a live application, for the recovery | `RestoreDrillTest`, `RecoveryTest` |
| Statements a dump must never contain, including a crafted SQL mode that would desynchronise quoting (independent review H1); truncation; escaped values | `DumpReaderTest` |
| Restore without the recorded digest; remember-me tokens after recovery; SIGTERM through the real command | `RecoveryTest`, `BackupCommandsTest`, `BackupCreationTest` |
| Credentials never on a command line or in the environment | `BackupCreationTest` |

Each safeguard was also disabled in turn (mutation probes) to confirm its test then fails; the results are in the WP-B1 handback.

## 9. Known limits and decisions left to the owner

- **The first staging backup needs this code on the host.** The running staging release predates it. Because C6-R makes legacy ticket URLs 404 as soon as it is
  selected, the backup that gates the C6-R deployment (ADR-008 step 1) must be taken **before** the new release is selected: unpack the new release, link `.env` and
  `storage` as usual, and run `backup:create` from that unselected directory (it only reads). That is a host step for the owner to approve.
- **Set and build.** `backup:verify` and the restore check that the dump, the manifest and the files agree with each other, including a summary of the migrations recorded in the set (count, last name, SHA-256).
  The restore does not compare that summary with the migrations of the build that runs it, so restore a set into a build whose migration set matches it, and run `php artisan database:verify` after any restore.
- The client tools differ by host: `mariadb-dump` 11.4 on Namecheap, 11.8 in the development image, 10.11 on hosted CI runners. The dump reader accepts the
  statement forms these write and refuses anything else, so a future client format change fails closed (no set) rather than producing an unchecked one.
- File-time resolution leaves the same-second window in section 2, point 5.
- A killed backup leaves a private partial set until the next run removes it.
- Not in this package: scheduling, retention, remote storage, point-in-time recovery, automated key management, `APP_KEY` rotation.
