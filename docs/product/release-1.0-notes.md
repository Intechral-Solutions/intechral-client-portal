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
| **Baseline it was built from** | Release 0.9, commit `32ec62f5c034b6394950203941e170d830b4669c` (untagged; a tag `v0.9.0` is proposed for later). Gate G3 confirms the commit against the deployed release's `RELEASE`, and the rollback assessment re-checks the retained directory. |

An `app:version` command was considered and not added: the identity already travels in `RELEASE`, and a command would only re-read the file the build stamps.

Filled at build time:

| | |
|---|---|
| Release id | _(from `RELEASE`)_ |
| Commit | _(from `RELEASE`)_ |
| Archive SHA-256 | _(from `<archive>.sha256`)_ |
| Tag | _(none yet)_ |

## What changed since Release 0.9

Merged on `main` up to `fec5c81`:

| Change | Operators | Customers |
|---|---|---|
| Help Desk **due-date overrides** (PR #50): an operator moves a ticket's working due date as part of a reply, with a history row; the original SLA deadline is kept | new ability `adjustDueDate`; one nullable column, one table | nothing |
| Help Desk **opens on the Queue** for operators (PR #51) | default destination of the Help Desk workspace | nothing |
| **Managed organizations, MO-1A** (PR #52): an operator designates a partner organization and links customer organizations to it. Administrative only: it grants nobody access to anything | Partner relationship panel on the organization page; `organizations:preflight`; a company of a partner or managed organization cannot be deleted; company promotion is transactional | nothing |
| **Managed organizations, MO-2A1** (approved for PR submission after independent closure review; not yet merged or deployed): operators only. Organization membership moves into one audited service, and an organization administrator becomes an **approved** administrator only through an explicit operator act (role is not approval). Grants nobody access to anything | Membership and approval controls on the organization page; `organizations:preflight` gains an approval inventory and integrity failures; the member routes now need `portal.operator` **and** `crm.manage` and an active account | nothing (a customer holding `crm.manage` used to be able to add members; it now answers 403) |
| Development tooling (the Mailpit host port) | none | none |

**Customer surface:** unchanged. The customer-pilot allowlist, the permission catalogue and the built-in roles are the same as in Release 0.9, so there is nothing for `roles:sync` to apply.

**Not in this release yet:** organization invitations (MO-2A2), customer-facing delegation (MO-2B), and anything of MO-3 and MO-4.

## Deployment facts

| Fact | Detail |
|---|---|
| Migrations (4) | `2026_11_04_120000_add_ticket_due_date_override` (additive), `2026_11_06_120000_add_organization_management_relationship` (additive), `2026_11_06_120100_unique_company_organization_link` (**tightening**: refuses to run, changing nothing, if an organization has two companies), `2026_11_07_090000_add_organization_admin_approval` (additive: two nullable columns on `organization_members` and one foreign key; no backfill, no approval is ever created by it) |
| Before the unique-link migration | run `art organizations:preflight` on the real data and have the owner review any `failure` ([MO-1A record](./release-1.0-managed-organizations-mo-1a.md#existing-data-preflight)). After the approval migration the same command also inventories administrator approvals ([MO-2A1 record](./release-1.0-managed-organizations-mo-2a1.md)); a historical administrator is unapproved until an operator approves that exact membership |
| New commands | `organizations:preflight`, `database:rollback-assessment` |
| Configuration | no new keys |
| Queue | no new queued class; the six Release 0.9 classes only (pinned by a test). Restart workers after the swap as usual |
| Rehearsal | the four migrations are pinned candidates and were rehearsed alone and together; re-run `./dev rehearse` after any edit to one |
| Rollback record | `database/profile/rollback/0.9.json` and the pinned `database/profile/releases/0.9/foreign-keys.json` ship in the archive; any package that adds a foreign key, column, CHECK, queued class or migration updates them in the same change |

## Rolling back

Rolling the code back to Release 0.9 is **not** an ordinary rollback: the unmodified 0.9 build reports five `unexpected foreign key` lines in `database:verify` and `runtime:preflight`, and its
`--issue` refuses to issue an A2 challenge (a real A2 is still possible by issuing the challenge through `RuntimeChallenges::issue()`). There are two supported ways back:

- **Mode 1, code-only** (data kept): an exceptional, reviewed operation: assess on Release 1.0 after the producers are stopped and the queue is drained, pre-check the old verifier's exact output, swap, then a real A2 whose accepted violations are exactly the five lines. The old verifier **exits non-zero** and the procedure never says otherwise.
- **Mode 2, restore the verified pre-upgrade backup**: the old gates pass unchanged, and **every write since that backup is discarded**. A backup taken just before the rollback is *not* a Mode 2 source.

Mode 1 starts only after a written **step 0**: a verified pre-upgrade backup (identifier and digest), the completed rehearsal record, the owner's confirmation that delegation was never enabled, the approved target, an owner-defined maximum window and a **company-deletion freeze** (the 0.9 build cannot enforce it; it is a procedure with an operator inventory, acknowledgements and read-only monitoring). When managed relationships exist, an extra verified backup of the 1.0 state is mandatory.
Mode 1 is prohibited once MO-2B delegation has been enabled (a persistent database marker, to be built with MO-2B, will make the assessment refuse). **Before** a code-only rollback through older membership code, all administrator approvals must be revoked explicitly (audited, idempotent, by an authorized operator, one membership at a time on the organization page): `database:rollback-assessment` **refuses while either approval column holds any value**, complete or half-written, so a code-only rollback is prohibited until none remains. After any rollback, `organizations:preflight` must pass before the re-upgrade (repairs go through the application's relationship service, never SQL), and administrators need renewed review and explicit approval before delegation is enabled. The reviewed procedure,
its evidence, the re-upgrade and the integration requirements for later packages are in [Rolling the Release 1.0 code back to Release 0.9](../operations/rollback-1.0-to-0.9.md).

## Gates that must be closed before Release 1.0 upgrades an authoritative environment

1. **G1, verified pre-upgrade backup.** A verified, restorable backup set of the Release 0.9 database, taken immediately before the first 1.0 migration, with its identifiers recorded. Without it there is no Mode 2.
2. `organizations:preflight` is run on real data, and the owner reviews its findings before the unique-link migration.
3. **G2, rollback rehearsal.** The rollback and re-upgrade procedure is rehearsed once on a **disposable host-copy** (never the authoritative environment).
4. **G3, release identity.** The retained Release 0.9 directory's `RELEASE` records the commit named in the rollback declaration (`32ec62f5c034b6394950203941e170d830b4669c`).
5. The migration-rehearsal evidence is preserved, and re-run after any migration edit.
6. Hosted CI, including the full Pest suite and the browser suites, is green on the release commit.

Owner rulings on rollback (2026-11-06) are recorded in the [rollback document](../operations/rollback-1.0-to-0.9.md#4-owner-rulings-and-the-two-supported-ways-back).
