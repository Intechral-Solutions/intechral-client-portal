# Release 1.0: identity and release notes

**Status:** in development. Nothing here is built, tagged or deployed. This file is the one place the release is named and described; the values that only exist once a
build is made are left blank and are filled from that build's `RELEASE` file, never typed from memory.

## Identity

There is no version framework. A release is identified by what already identifies a build, plus one git tag:

| What | Where it comes from |
|---|---|
| **Name** | "Release 1.0" |
| **Git tag** | **None yet** (owner ruling, 2026-11-06: no release tags are created). Proposed for later: `v1.0.0` on the release commit. |
| **Build identity** (authoritative) | the `RELEASE` file at the root of the build archive: `release=<UTC yyyymmddHHMMSS>-<7-character commit>` and the full 40-character `commit=` ([Release build](../operations/release-build.md#release-identity)). A rebuild of one commit is a new id. |
| **Where it is visible at runtime** | the release directory name under `releases/`; the cron wrapper's `START release=<id>` lines; the A2 `--verify` release row; `backup:create` manifests (which read `RELEASE`) |
| **Baseline it was built from** | Release 0.9, commit `32ec62f5c034b6394950203941e170d830b4669c` (untagged; a tag `v0.9.0` is proposed for later). |

An `app:version` command was considered and not added: the identity already travels in `RELEASE`, and a command would only re-read the file the build stamps.

Filled at build time:

| | |
|---|---|
| Release id | _(from `RELEASE`)_ |
| Commit | _(from `RELEASE`)_ |
| Archive SHA-256 | _(from `<archive>.sha256`)_ |
| Tag | _(none yet)_ |

## Data, deployment and recovery ruling (R1-0)

**Owner ruling of 2026-11-09, made authoritative in this repository by R1-0 (2026-11-10).** It supersedes every earlier statement in the repository that treats Release 0.9
data as authoritative or requires a way back to the Release 0.9 build; those statements are kept as history and annotated, not rewritten.

- **No authoritative production or customer data exists.** The new portal has never been in production. Release 0.9 staging is **disposable** and is rebuilt only when the owner
  explicitly authorizes it; the shared hosting account's other sites and the legacy portal are *not* disposable, so every host operation still needs exact-target verification and explicit approval.
- **Release 1.0 is fresh-install and rebuild-first.** A migration may therefore refuse a populated table instead of transforming it (the refuse-and-list pattern of
  `2026_11_06_120100_unique_company_organization_link`), and it is never a rule that a new migration must keep an older build running.
- **Retired:** the mandatory code rollback from Release 1.0 to the Release 0.9 build (the `rollback-1.0-to-0.9` procedure, `database:rollback-assessment`, the `database/profile/rollback/0.9.json`
  declaration and its pinned `releases/0.9` manifest, Mode 1/Mode 2 and the gates G1 to G4 of that procedure), the "N-1" rule applied to the 0.9 build, and the requirement that every migration
  pass a populated Release 0.9 data-preservation rehearsal. Nothing was rehearsed against real data, so no evidence is lost: the history is in git.
- **Still required, and unchanged:** the database profile and `database:verify` with `database/profile/foreign-keys.json` (a migration that adds or changes a foreign key updates that manifest);
  fresh-migration verification; runtime preflight (`runtime:preflight`, A2); release identity (the `RELEASE` file); hosted CI and the browser suites as evidence; the security, tenant-isolation and
  integrity tests; and the unrelated deployment and hosting assets.
- **Backup and recovery remain required.** [Backup and recovery](../operations/backup-and-recovery.md) is the one recovery procedure (restoring a verified backup discards every later write).
  A **verified backup and a tested restore of the Release 1.0 database must exist before any real customer is onboarded to production**; that drill on the real host is an open requirement, not something this ruling waives.
- **The migration-rehearsal harness is kept as an optional tool** ([migration rehearsal](../testing/migration-rehearsal.md)) for proving a data-touching migration on populated fixtures; it is also the shared
  infrastructure of `backup:drill` and `database:verify`. A migration need **not** be listed in `database/rehearsal/candidates.php` to ship. Migrations already listed stay SHA-256 pinned: editing a pinned migration file,
  even a comment, invalidates its pin.

## What changed since Release 0.9

Merged on `main` up to `fec5c81`:

| Change | Operators | Customers |
|---|---|---|
| Help Desk **due-date overrides** (PR #50): an operator moves a ticket's working due date as part of a reply, with a history row; the original SLA deadline is kept | new ability `adjustDueDate`; one nullable column, one table | nothing |
| Help Desk **opens on the Queue** for operators (PR #51) | default destination of the Help Desk workspace | nothing |
| **Managed organizations, MO-1A** (PR #52): an operator designates a partner organization and links customer organizations to it. Administrative only: it grants nobody access to anything | Partner relationship panel on the organization page; `organizations:preflight`; a company of a partner or managed organization cannot be deleted; company promotion is transactional | nothing |
| **Managed organizations, MO-2A1** (approved for PR submission after independent closure review; not yet merged or deployed): operators only. Organization membership moves into one audited service, and an organization administrator becomes an **approved** administrator only through an explicit operator act (role is not approval). Grants nobody access to anything | Membership and approval controls on the organization page; `organizations:preflight` gains an approval inventory and integrity failures; the member routes now need `portal.operator` **and** `crm.manage` and an active account | nothing (a customer holding `crm.manage` used to be able to add members; it now answers 403) |
| **Organization invitations, R1-3 / MO-2A2** (implemented on `feature/r1-3-organization-invitations`, packages A to C; not yet merged or deployed): an operator invites a **new** person into **one named organization** from the organization page. The invitation is a hashed, 7-day, revocable, single-use credential delivered by an encrypted, after-commit mail; accepting it atomically creates the account (built-in `user` role only) and an ordinary, **unapproved** `member` membership. The organization-less operator invitation, its route, the users-page modal, the `users.invite` permission and the `invitations` table are **retired** | An **Invitations** card on the organization page (issue, Resend, Revoke, status, reason, inviter, expiry); authority `manageMembership` only; a suspended issuer's or de-authorized issuer's pending invitations are permanently revoked; `organizations:preflight` reports invitation counts and integrity | the registration page names the organization; **no customer-side control** (delegated invitation is MO-2B); every dead link is one uniform page |
| Development tooling (the Mailpit host port) | none | none |

**Customer surface:** unchanged. The customer-pilot allowlist, the permission catalogue and the built-in roles are the same as in Release 0.9, so there is nothing for `roles:sync` to apply.

**Not in this release yet:** customer-facing delegation (MO-2B, which includes customer-side invitation and the existing-account invitation), customer **invitation single sign-on** (deferred; it needs provider-email verification and a Microsoft driver first), and anything of MO-3 and MO-4.

## Deployment facts

| Fact | Detail |
|---|---|
| Migrations (4) | `2026_11_04_120000_add_ticket_due_date_override` (additive), `2026_11_06_120000_add_organization_management_relationship` (additive), `2026_11_06_120100_unique_company_organization_link` (**tightening**: refuses to run, changing nothing, if an organization has two companies), `2026_11_07_090000_add_organization_admin_approval` (additive: two nullable columns on `organization_members` and one foreign key; no backfill, no approval is ever created by it) |
| Before the unique-link migration | on any non-empty database (a rebuilt staging that was seeded, or a future production), run `art organizations:preflight` and have the owner review any `failure` ([MO-1A record](./release-1.0-managed-organizations-mo-1a.md#existing-data-preflight)); a fresh install has nothing to report. After the approval migration the same command also inventories administrator approvals ([MO-2A1 record](./release-1.0-managed-organizations-mo-2a1.md)); a historical administrator is unapproved until an operator approves that exact membership |
| R1-3 additions (organization invitations) | **Two more migrations, not rehearsal candidates (R1-0):** `2026_11_11_090000_create_organization_invitations_table` (additive: one table, four foreign keys, in `database/profile/foreign-keys.json`) and `2026_11_12_090000_retire_legacy_invitations` (**destructive by the R1-0 ruling**: drops `users.invitation_id` and the legacy `invitations` table, populated or not). **Queue:** `OrganizationInvitationNotification` (encrypted payload, after-commit, send-time eligibility) replaces `InvitationNotification`; the class count is unchanged; a rotation of `APP_KEY` must keep the retired key in `APP_PREVIOUS_KEYS` until the queue and `failed_jobs` have drained. **After deploying to an existing database run `php artisan roles:sync`**: a migration never edits permissions, so the retired `users.invite` permission stays until it runs. `organizations:preflight` also reports invitation counts and integrity (ids and counts only). Preproduction obligations that remain open are listed in the [R1-3 record](./release-1.0-r1-3-organization-invitations.md#209-residual-risks-and-owner-decisions) |
| New commands | `organizations:preflight`; `e2e:cleanup-invitees` (development and CI only: `local` and `testing`, narrow by design, never part of a deployment) |
| Configuration | no new keys |
| Queue | no new queued class; the six Release 0.9 classes only. Restart workers after the swap as usual |
| Rehearsal | optional. The four migrations are pinned candidates in `database/rehearsal/candidates.php` and were rehearsed; their SHA-256 pins mean none of those files may be edited. A new migration need not be added there |

## Recovery

There is no supported way back to the Release 0.9 build (see the ruling above). Recovery for Release 1.0 is **restoring a verified backup** with
[Backup and recovery](../operations/backup-and-recovery.md), or **rebuilding** a disposable environment when the owner authorizes it. A same-commit code swap-back of a retained
release directory remains an ordinary release operation, with its own conditions, in the [first-install runbook](../operations/staging-first-install.md).

## Gates before Release 1.0 is deployed to a customer-facing environment

1. A **fresh install** migrates cleanly: `php artisan migrate --force` then `php artisan database:verify` exits 0 with nothing pending.
2. `php artisan runtime:preflight` passes, and the release identity (`RELEASE`) is recorded for the build.
3. Hosted CI, including the full Pest suite and the browser suites, is green on the release commit.
4. On any non-empty database, `organizations:preflight` has been run and the owner has reviewed its findings.
5. **Before any real customer is onboarded to production:** a verified backup and a **tested restore** of the Release 1.0 database exist ([Backup and recovery](../operations/backup-and-recovery.md)).
