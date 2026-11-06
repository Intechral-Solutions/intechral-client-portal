# Release 1.0: Managed Organizations, MO-1A (operator-managed relationships)

**Status:** implemented on `feature/r1-0-mo-1a-managed-organizations`; independent implementation review (Opus 5.5 High) completed, and
its closure review returned **SAFE TO COMMIT**. Submitted as a pull request; **not merged, not deployed**. Release 0.9 staging is
unchanged. This record covers MO-1A only; MO-2, MO-3 and MO-4 are not started. The Release 1.0 deployment gates below remain open.

## Decision

The MO-0 discovery and its independent architecture review (Opus 5.5 High: **SAFE AFTER TARGETED REVISIONS**) chose **Option C**:

- `organizations` stays the tenancy and membership identity; `crm_companies` stays the directory profile. Each managed organization is a
  pair of exactly one of each. No Directory consolidation, no Person records.
- A shallow, explicit **managed relationship** between organizations is added, with its own policy and service. The global tenant scopes
  are not widened.
- **Management is not data access.** Nothing reads the relationship to authorize anything.

MO-1A is the operator-only first step: Intechral designates a partner (for example UNISA) and links customer organizations to it. It
gives UNISA administrators and staff **no new permission**. `tickets.company_id`, `TicketPolicy`, `TicketService`, `InvitationService`,
the global scopes, `CustomerPilotGate`, Spatie roles and permissions, and every React component are unchanged.

## Data model

| Change | Detail |
|---|---|
| `organizations.managed_by_organization_id` | Nullable self-FK, `ON DELETE RESTRICT`, indexed by the key. `NULL` for every existing row. |
| `organizations.can_manage_customers` | `NOT NULL` boolean, default `0`: the organization is designated a partner. |
| CHECK `organizations_partner_not_managed_check` | `can_manage_customers = 0 OR managed_by_organization_id IS NULL`: a partner cannot be managed (backstop). |
| UNIQUE `crm_companies_organization_id_unique` | One company per organization; `NULL` stays allowed. **Separate migration.** On a table the migration builds, InnoDB drops the plain index the foreign key was using, so the one key serves both. On a table restored from a dump the foreign key's index is an explicit key and **stays** next to the unique key (harmless). `down()` handles both. |

Neither column is mass-assignable and neither has an Eloquent relation: a relation would run through `OrganizationMembershipScope` and
undercount for any account the scope filters.

No CHECK stops an organization managing itself: MariaDB 11.4 refuses a CHECK that reads an `AUTO_INCREMENT` column (error 1901, verified).
The service refuses it; the preflight and the rehearsal snapshot count it.

## Rules and where they are enforced

`App\Services\OrganizationRelationships` (the only writer):

1. The manager must be a designated partner. 2. A partner cannot itself be managed, and an organization cannot manage itself, so there is
one level. 3. At most one manager. 4. Both ends have **exactly one** CRM company (a missing company, or two, refuses). 5. A partner with
customers cannot lose its designation. 6. A repeat is a no-op with no audit row.

**Link and Reassign are different operations.** A plain Link (the partner page's "Link customer", or the customer page's "Link to partner")
**refuses** a customer another partner already manages: nothing changes, no audit row, and the operator is told it may have been linked
since the page was loaded. Moving a customer is an explicit Reassign (the customer page sends `reassign=1` only when it shows a
current partner) and is audited as its own event. Linking to the partner it already has stays a harmless no-op.

**Concurrency.** Each mutation is one transaction. The organization rows it needs are locked `FOR UPDATE` in ascending id order, in **one
statement** (one lock order for every caller). The company pairing is then read under a shared lock (organizations before companies,
the order `CrmService::deleteCompany` also follows). Every rule is checked against the locked, current rows, never against a model
the caller loaded. The "does this partner have customers" count is **unscoped** (`DB::table`): the membership scope keys on the `operator`
role *name* and would make a partner look empty to a custom-role operator.

**Audit.** Each change writes an activity-log row in the same transaction: actor, subject organization, previous and new values, with the
partner's name beside its id.

## Authorization

`OrganizationPolicy::manageRelationships` requires **both** `portal.operator` and `crm.manage` (capabilities, never the `operator` role
name). It is checked by the controller (first statement), re-checked by the service, and the routes sit behind `can:crm.manage` and the
customer-pilot allowlist (unlisted route names are denied to a customer). The relationship routes take a plain integer id and resolve it
**unscoped after** the policy passes, never through route-model binding, so the operation itself is not answered 404 by the scope. The
relationship panel on `organizations.show` is built only for an authorized actor: a customer holding a stray `crm.manage` who opens their
own organization's page sees no relationship data.

**Custom-role operators (limit, not widened here).** An account that holds both capabilities without the `operator` role can perform every
relationship operation, and the change is made. But `organizations.show` and the member routes still bind through the membership
scope, which keys on the `operator` role name, so that page is a 404 for them unless they are a member. After a successful change
the controller therefore redirects to `organizations.show` **only if this actor can open it** (a scoped check), otherwise to
`organizations.index`, with the success message either way. The relationship panel is shown only on a page the actor can open: for a
custom-role operator, organizations they belong to. Full UI access for them waits for the `hasRole('operator')` scope checks to be
retired (a separate Directory task); opening `organizations.show` to them now would expose member lists and every active user as
candidates, and its member forms would still 404. The acceptance criterion "operators can link and unlink in the UI" is therefore met
for the actors those pages admit today; no page is opened to anyone new.

## Company deletion and promotion

- **Delete guard.** `CrmService::deleteCompany` refuses a company whose organization is a partner or is managed
  (`OrganizationRelationshipException`, HTTP 409 for JSON, back with a `company` error for a browser), with no side effect. It locks the
  organization row first and reads the flags under the lock. A company with no organization yet is read under a **shared** row lock, so a
  concurrent promotion (which takes that row `FOR UPDATE`) cannot commit between the read and the delete. Shared, not exclusive: linking a
  project needs a shared lock on the same row through its foreign key, and an exclusive one would make the project guard wait on a link it exists to refuse. The existing project guard, its stale-check recount and its tests are
  unchanged in behaviour. `tickets.company_id` keeps `SET NULL` (deliberately; see MO-4A).
- **Promotion** is one transaction under a lock on the company row. A second or stale promotion finds the link and returns the first
  organization; none is orphaned. The organization is read unscoped and the slug check is unscoped (a scoped check could not see other
  tenants' slugs). The promoting operator is still added as an admin member, as before (existing behaviour, unchanged).

## Migration and rollback

Both migrations are forward-only, and a repeat of either is harmless. Their contracts differ:

- **Relationship migration** detects every piece on its own (each column, the named foreign key, the CHECK) and adds only what is
  missing, so a run that stopped part-way (DDL commits as it goes) is completed by the next. A column or foreign key that already
  exists must have the shape the migration would create (column type, nullability and default; the key's target and
  `ON DELETE RESTRICT`), otherwise it stops with a message. Existing data is never rewritten.
- **Unique-key migration** is a no-op when the key exists, **refuses and changes nothing** on duplicate links, and otherwise adds
  the key. It does not inspect column shapes (it adds no column).

| | Class | Notes |
|---|---|---|
| `2026_11_06_120000_add_organization_management_relationship` | additive | Appended columns; no row rewritten, no backfill. Release 0.9 code ignores them and never writes them. |
| `2026_11_06_120100_unique_company_organization_link` | **tightening** | **Refuses to run, changing nothing, and names the duplicates** if two companies point at one organization. Never merges, repairs or deletes. Release 0.9 code cannot violate it (its only writer always creates a new organization). |

**Rollback to the Release 0.9 build is NOT fully verified through the existing release procedure.**

- The Release 0.9 *application* should keep serving against the expanded schema: it ignores the new columns, never writes them, and the
  unique key is invisible to it.
- But 0.9's `database:verify`, `runtime:preflight` and its `--verify` mode compare the live schema with the 0.9 foreign-key manifest
  and report **unexpected foreign keys** on the migrated schema: four, of which **three already come from PR #50** (due-date
  override) and one from this work. The runbook's rollback gate therefore cannot pass as written. The startup guard makes no
  database query, so it does not stop workers.
- The 0.9 code has **no company-delete guard** for partners and managed organizations. During a rollback window a company of a
  partner or managed customer can be deleted through the old code path, which is the case the 1.0 guard exists to refuse.
- Relationship-derived behaviour grants no access, so a rollback closes, never opens, anything. Recovery from backup discards
  relationship edits made after the backup; they are operator-entered and re-enterable.

**Open Release 1.0 release-engineering gate:** decide how the 1.0 rollback handles the 0.9 verifier and preflight (for example a
rollback-specific manifest or an explicit, reviewed acknowledgement of the known extra keys) **before the first 1.0 upgrade of any
authoritative environment**. Not solved here: no rollback framework is added and no Release 0.9 code is changed.

## Existing-data preflight

`php artisan organizations:preflight [--json]` is read-only (tables only: no permission cache, no scope). It reports duplicate
company-to-organization links (**failure**: blocks the unique migration), organizations without a company, companies without an
organization, organization **admin** memberships and the **operator-held** ones, users in several organizations, tickets that already
carry a company, and (after migration) any relationship that breaks a rule. Run it against a copy of production data before migrating.

Why admin memberships matter: `Organization::isAdmin()` has **no callers**, so `role = admin` grants nothing today. MO-2 would turn those
inert rows into authority; they need an owner review first.

## Validation evidence

**Independent review.** The first review (Opus 5.5 High) returned SAFE AFTER TARGETED REMEDIATION: no Critical or High findings, three
Medium findings (Migration A re-run accepting a missing foreign key; a custom-role operator redirected to a 404 after a successful
change; inaccuracies in this record) and four Low findings. All were remediated and the closure review returned **SAFE TO COMMIT**. It
verified authorization, tenant isolation, all nine invariants and the lock order on MariaDB itself, not only in the SQL text.

**Suites.** `OrganizationRelationshipsTest` (rules, policy intersection, custom-role operator and redirects, unscoped integrity, audit,
UI, stale Link versus explicit Reassign, "relationship grants nothing", delete guard, promotion), `OrganizationRelationshipsConcurrencyTest`
(two real connections: remove-vs-link, designate-vs-link, delete-vs-link, promotion lock), `ManagedOrganizationMigrationTest` (schema,
CHECK, rollback and forward, interrupted-run completion, mismatched foreign key refused, restored-table `down()`, legacy ambiguity) and
`OrganizationPreflightTest`. The rehearsal harness gained candidate entries and conditional snapshot checks (`organizations:*`), and the
database-profile foreign-key manifest one entry.

**Run results.** Focused suites green (CRM directory 70 passed; database, rehearsal, configuration, preflight and fixture suites 660
passed); Pint and `./dev check --no-php` pass. The full Pest suite passed once before the review (4626 passed, 2 fixture failures since
fixed) and **was not re-run locally on the final tree**, and neither was the browser suite: **hosted CI is the final-tree gate**.
Migration rehearsals (UID 1000, rehearsal databases only): each candidate alone and the due-date, relationship and unique migrations
together passed, with an identical snapshot after backup and restore; the CHECK, the UNIQUE key and the named foreign key
(`ON DELETE RESTRICT`) were confirmed present in the restored database. The rehearsal is not part of CI: re-run it after any edit to
either migration and recompute its pin in `candidates.php`.

**Pins.** `2026_11_06_120000_add_organization_management_relationship` `17e96582…21ee8`;
`2026_11_06_120100_unique_company_organization_link` `46bf6933…fb08` (full hashes in `database/rehearsal/candidates.php`).

### Remaining non-blocking findings

- No tests yet for the pre-migration preflight, the preflight's JSON content, a forced promotion failure, or the delete retry branch.
- Slug uniqueness on promotion: two different companies with the same name, promoted at once, can still collide (existing limit; the
  promotion now rolls back fully and a retry succeeds).
- `ATTEMPTS = 3` retries a lock timeout, so a request could wait up to three timeouts. Two concurrent deletes of one company can
  deadlock; the engine aborts one and the retry finds the company gone.
- The preflight prints user emails: keep its output out of committed evidence.
- Existing organization `admin` memberships are inert today; MO-2 would activate them and needs an owner review first.

### Release 1.0 deployment gates (open; not solved by this change)

1. Run `php artisan organizations:preflight` on real data, or a controlled copy, before the unique migration reaches any real environment.
2. The owner reviews its findings before that migration is applied.
3. Resolve the rollback-verification incompatibility with the Release 0.9 schema verifier (see Migration and rollback).
4. Preserve the migration-rehearsal evidence.

## Remaining work packages (not started)

| WP | Outcome | Gate |
|---|---|---|
| MO-2 | Org admins manage their own organization's members and invitations (fixes for dormant admin rows, `addMember` demotion, last-admin under lock, tenant-safe invitations, default-off flag) | Opus design and closure review |
| MO-3 | Partner admins create and manage customer organizations (new atomic service, not `promoteToOrganization`) | Opus review |
| MO-4A | Tickets carry a visible, explicit organization; guarded company delete | design gate |
| MO-4B | Partner support staff read and reply on the public thread (separate ability and routes; `TicketPolicy::view` unchanged) | Opus design gate first |
| Next release | `tickets.company_id` to `RESTRICT` once the guard has shipped | rehearsal |

## Deferred decisions

`can_support` and `partner_support_enabled` (MO-4B); inferred or backfilled ticket attribution (rejected for MO-1A: EPIC-017 records
`company_id` as not inferred); H10 invitation-token hashing before invitations can confer admin rights; delegated invitation of existing
accounts; time and finance visibility; retiring the `hasRole('operator')` scope checks; correcting EPIC-008 and EPIC-010B's stale claims.
