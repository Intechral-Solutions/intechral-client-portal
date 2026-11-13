# Release 1.0: R1-4 Partner support access (design record)

**Status:** implemented on `feature/r1-4-partner-support` (not merged, not deployed), written against `main` at `209b365` for the owner-authorized R1-4 package
(rulings D-1, D-2, D-4 of 2026-11-12). §11 records the independent design challenge and how each finding was resolved; §12 the build, evidence and residual risks. Owner rulings that bind this design: partner support uses **dedicated named abilities and tightly scoped routes**, not a widened
`TicketPolicy::view`; a partner relationship alone grants nothing; only an eligible Intechral operator issues or revokes a grant, to specifically designated partner
personnel; support staff may **read and reply** on the **public** conversation of appropriately linked managed organizations and may not change status, assign, see internal
notes or time, enter the operator queue, export, or act in bulk; existing requester and operator behaviour is unchanged. No generalized relationship, delegation or
permission framework.

## 1. Vocabulary and the one rule

- **Partner** `P`: an organization with `can_manage_customers = 1` (MO-1A). **Managed customer** `C`: an organization whose `managed_by_organization_id = P` (one level, one manager).
- **Support grant**: an operator's explicit designation of one **membership** (`organization_members` row of user `S` in partner `P`). Two new nullable columns, `support_granted_at`
  and `support_granted_by`; only a **complete** pair (both set) is a grant, exactly as an approval record is complete only when both columns are set.
- **Effective support authority** over a Ticket `T` for actor `S` is true only when ALL hold, read from the tables at the moment of the request:
  1. `S`'s membership in `P` has a complete grant;
  2. `S` is active (`users.disabled_at` null) and is **not** an operator (not operator-eligible by the table-based `OrganizationAuthority::isOperator`);
  3. `P.can_manage_customers = 1` and `P.disabled_at` is null;
  4. `C.managed_by_organization_id = P.id`, `C.disabled_at` is null, and `T.organization_id = C.id`.

  Nothing else is consulted: not the membership **role**, not administrator **approval**, not Spatie permissions, not the permission cache, not CRM companies, not `owner_id`. A
  partner member without a grant, a member of an unrelated organization and an operator all fail the rule. A ticket of `P` itself is never supported by `P`'s staff (a partner is not its own customer).

## 2. Separation from existing visibility

`TicketPolicy::view`, `viewAsRequesterOrOperator`, `reply`, `viewInternal`, `adjustDueDate`, `downloadAttachment` and every existing route, controller and presenter keep their exact behaviour
(`TicketPolicy:23-33` anticipated this: a new viewer class gets its own named ability). The new class has its own abilities on `TicketPolicy`:

| Ability | Meaning |
|---|---|
| `viewAsPartnerSupport(User, Ticket)` | effective support authority (§1) |
| `replyAsPartnerSupport(User, Ticket)` | `viewAsPartnerSupport` and the ticket is not resolved or closed. Used **only to tell the page whether to offer a composer**; the reply route authorizes `viewAsPartnerSupport` (404) and the service owns the 422 |
| `downloadAttachmentAsPartnerSupport(User, Ticket, TicketAttachment)` | `viewAsPartnerSupport`, attachment belongs to the ticket, and is on the description or on a **public** reply |

All three delegate to one class, `App\Services\PartnerSupportAccess` (the single definition of §1, in the style of `OrganizationAuthority`). A refusal on a support route is a **404** (the controller asks `Gate::allows` and aborts; it does not use `authorize()`, whose default is 403), identical for a
ticket that does not exist, so the routes disclose nothing. Ticket-linked task visibility and time attribution keep asking `viewAsRequesterOrOperator`, which this package does not touch.

## 3. Routes and presentation

Dedicated, named, `auth`-only (authorization is the grant, not a Spatie permission), added to the **pilot** allowlist of `CustomerPilotGate` (so `closed` mode answers 404 and
`legacy` is unaffected):

| Route | Purpose |
|---|---|
| `GET /support/requests` (`support.index`) | list of supported tickets (404 unless the actor holds an effective grant); search over public text and a status filter only; paginated |
| `GET /support/requests/{ticket}` (`support.show`) | the public conversation, read-only history, reply area |
| `POST /support/requests/{ticket}/replies` (`support.replies.store`) | a **public** reply with optional attachments |
| `GET /support/requests/{ticket}/attachments/{attachment}` (`support.attachment.download`) | public attachments only |

There is no status, assign, bulk, report, export, queue, note, time or due-date route in this group, and none of the operator routes (`can:tickets.assign`) or the shared `tickets.replies.store`
(`reply` → `view`) becomes reachable: they still answer 403/404 for a support user.

Presentation reuses the requester's public thread: `RequesterTicketPresenter` (which already omits internal notes, time, SLA and ids) gains optional URL callbacks so attachment and row
links point at the support routes; the React `tickets/show` page gains optional `backLink` and `replyUrl` props; a small `support/index` page lists supported tickets (with the requester's name).
Neither requester route changes shape. The requester sees a partner reply in their own thread like any public reply.

## 4. Reply semantics

`TicketService::addPartnerSupportReply` is a separate method (the requester/operator `addReply` is untouched). In one transaction it locks the ticket, **re-evaluates §1 under locks**, then writes a
public reply (and attachments, same file-cleanup contract) and notifies exactly as a public reply does today (requester and assignee; never the author; never a suspended account).
It **never changes status** (owner ruling): no `pending_user` → `in_progress`, no reopen. Because a reply on a resolved or closed request would otherwise need a reopen, it is
refused there with a 422 on `body` ("Ask Intechral or the requester to reopen it"). A reply carries no due-date adjustment and no internal flag, and ignores both if sent.

## 5. Grants, disabling and immediate revocation

**Authority to grant/revoke:** `OrganizationPolicy::manageMembership` (portal.operator **and** crm.manage), checked in the controller and again in the service, like `OrganizationMembership`. No new
Spatie permission, so there is nothing for `roles:sync` to apply. `App\Services\PartnerSupportGrants`:

- `grant(P, S, actor)`: needs `P` to be a partner (disabled or not is irrelevant to the write; refused while disabled), `S` an existing **member** of `P`, active, and **not** an operator. Idempotent.
- `revoke(P, S, actor)`: always allowed, idempotent. Both audit (actor, previous and new values).
- Lock order is the repository's canonical one: **the actor's and the member's user rows (shared, ascending id) → organization row (exclusive) → membership row (exclusive)**, inside one transaction
  (`OrganizationInvitations`, R1-3A). The actor is locked first because `support_granted_by` is a foreign key to the actor, which InnoDB share-locks at the UPDATE.

**What ends authority, and when.** Because §1 is read from current rows on every request (list, page, download) there is no cache and no stale window:

| Change | Effect |
|---|---|
| grant revoked, membership removed | immediate (the row is cleared/deleted) |
| `S` suspended | immediate (`disabled_at`), **and** `AccountLifecycleService::suspend` clears every grant `S` holds in the same transaction, so reactivation cannot revive it |
| `S` becomes an operator | immediate (rule §1.2); the grant is dormant and a grant is never issued to an operator. **Residual:** if that account is later demoted back to a customer the dormant grant applies again (see §12) |
| partner designation removed | immediate (rule §1.3); `removePartner` also clears the partner's grants in its transaction (re-designation never revives them) |
| `C` unlinked from `P`, or reassigned | immediate (rule §1.4) |
| `P` or `C` disabled | immediate (`organizations.disabled_at`); re-enabling restores access only through grants that still exist (disabling is reversible, administrative state; suspension and removal are the permanent ones) |

**Organization disable state** (`organizations.disabled_at`, nullable datetime, additive, no FK): set and cleared only through `OrganizationRelationships::disable/enable`
(`manageRelationships` = portal.operator and crm.manage, organization row `FOR UPDATE`, audited). Its only readers in this release are §1 and `ServiceRequestAccess`, which refuses to
file a **new** request for a disabled organization with the existing "not available" message (a new state, so no existing behaviour changes). A requester's access to and replies on their own
existing tickets are unchanged: disabling is not a requester lockout.

## 6. Concurrency contract

- Read paths take no lock and use one join over `tickets`/`organizations`/`organization_members`/`users` plus the table-based operator test. Unscoped: `OrganizationMembershipScope` hides organizations from
  non-operators, so a scoped read would let the actor's own tenant filter decide the rule.
- The reply path re-checks in the existing ticket transaction, in this order: **ticket (X, as `addReply` already does) → user (S) → the customer and partner organization rows in ONE statement, ascending id (S) → membership (S)**.
  The partner is nominated by a plain read of `C.managed_by_organization_id`, and what the locks then show decides (a relationship that moved fails closed). Ascending id is the order `OrganizationRelationships::lock()` takes its
  exclusive locks in; the first draft locked customer then partner and could deadlock an operator re-submitting the link (§11, finding 1). This is a
  prefix-compatible extension of the repository's orders: nothing that holds an organization, membership or user lock ever waits for an existing ticket row (ticket creation inserts a new row; membership,
  relationship, disable, suspension and the grant service never lock tickets), so there is no cycle. Suspension (user X → membership update) and the grant service (user S → organization X → membership X)
  are ordered with the reply by the user row first. A revocation that has committed is seen by a reply that starts later; a reply that holds its locks first commits before the revocation can, and was valid when it did.
- The locked re-check and the unlocked join are two implementations of one rule; a parameterized drift test runs a state matrix through both (as `isMembershipManager` does for R1-3).

## 7. Schema (one additive migration)

`organization_members.support_granted_at datetime null`, `support_granted_by bigint unsigned null` (FK → `users.id` RESTRICT, added to `database/profile/foreign-keys.json`), `organizations.disabled_at
datetime null`. No backfill, no CHECK (an incomplete pair fails closed and the preflight reports it). Re-runnable in the style of the sibling migrations. `organizations:preflight` gains a
support-grant inventory (complete, incomplete, on a non-partner, held by an operator or suspended account) and the disabled count. Not a rehearsal candidate (R1-0).

## 8. Operator surface

Organization page (Blade, operator-only, same gates as the member administration): per member of a **partner**, "Grant support access" / "Revoke support access" with status text ("Support: granted by X on D;
in effect / not in effect because ..."), and Disable / Enable organization. Customers see none of it. A customer with an effective grant sees a **Support requests** entry in the Helpdesk workspace.

## 9. Negative tests (all in pilot mode; the suite default is legacy)

A partner member without a grant; a granted member of an unrelated partner; a granted member against a ticket of an unmanaged organization, of the partner itself, and of a disabled customer; a member of the customer organization; the requester; an operator holding a grant; each
revocation row of §5 followed by an immediate 404 with no cache flush; no internal note or internal attachment in any prop, response or download, and no `time`, assignee id, requester id or organization id; the support routes absent in `closed` mode; the operator routes and
`tickets.replies.store` still refused for a support user; a partner reply leaves the status and the due date unchanged and is refused on resolved and closed; a requester's own pages unchanged; real two-connection tests for revoke-versus-reply (waits, and seen when committed first), relink-versus-reply, suspend-versus-reply and suspend-versus-grant, plus a query-log pin of the reply's organization lock order.

## 10. Explicitly not in R1-4

Partner-originated requests with a named contact (R1-5, since built: [design record](./release-1.0-r1-5-partner-originated-requests.md)), participant notifications (R1-6), terminology (R1-7), partner-administrator self-service grants (MO-3), organization-wide customer visibility, a
per-customer grant scope (the unit is the partner's current managed customers), any permission framework. Support users' own tickets stay ordinary requester tickets.

## 11. Independent design challenge (Opus, 2026-11-12): SAFE AFTER TARGETED REVISIONS

Seven findings, none disputing the owner rulings; the existing routes, the mail path and the presenter were confirmed to give a support user nothing outside §1.

| # | Sev. | Finding | Disposition |
|---|---|---|---|
| 1 | Medium | Locking customer then partner can deadlock `assignCustomer` (exclusive, ascending id) when the partner id is lower; the reply has no retry | **Fixed.** Nominate the partner, lock both rows in one ascending statement, re-check under lock. The lock statement's order is pinned by a query-log test (C2); the two-connection test (C) shows the link waiting, not a deadlock, because a second connection run from the first's callback can only time out |
| 2 | Medium | A dormant designation of an account that becomes an operator applies again if that account is later demoted | **Accepted residual, owner-visible (§12).** Clearing it needs a before/after hook in `AccessGrants`, whose role-permission edits do not enumerate holders; judged out of proportion for a state that needs two deliberate operator acts. Mitigations: never issued to an operator, the operator page shows "granted, not in effect: the account is an operator" with Revoke, the preflight warns |
| 3 | Medium | The requester presenter promises a reopen/return and says "Waiting on you" to a partner | **Fixed.** Partner reply state has no notice and no composer on a resolved or closed request; `pending_user` reads "Waiting on requester" in rows, header, history and filter |
| 4 | Low | §2 and §4 disagreed, and `authorize()` answers 403 | **Fixed** (§2, §3): the controller aborts 404 on `viewAsPartnerSupport`; the service owns the 422 |
| 5 | Low | A partner reply on an unassigned request alerts nobody at Intechral | **Accepted, R1-6.** The requester and assignee are mailed as for any public reply; the unassigned-reply alert is R1-6's stated scope |
| 6 | Low | §5 did not say whose user row is locked first | **Fixed** (§5) |
| 7 | Low | Allowlist pin, navigation condition and label | **Done:** pin updated, the entry has its own condition and a `support.*` pattern, label "Supported requests" |

## 12. Build, evidence and residual risks

Built as designed with the §11 revisions. Operator-visible additions beyond §8: a "Support" status per partner member, and the preflight section `partnerSupport` (ids and counts only).

**Residual risks (all accepted, none new in the security architecture; owner rulings of 2026-11-12):**
- **Known, nonblocking authorization-lifecycle consideration for pre-RC1 convergence:** a dormant designation of an operator-turned-customer revives if that account is later demoted (finding 2). Accepted by the owner; no implementation now. A cheap hardening for later is to clear designations in `AccessGrants` when an account's operator status changes.
- Disabling an organization is reversible and leaves designations in place; enabling restores access through those that still exist.
- Hosted CI and the browser suites have not run for this branch.
- Provenance: a partner's reply shows as an ordinary public reply under the author's name; the operator ticket page does not say "via partner support" (deferred to the UX convergence backlog by the owner).
- A partner reply on an unassigned request alerts nobody at Intechral: the unassigned-reply alert is R1-6 scope (owner ruling); since built, see the [R1-6 design record](./release-1.0-r1-6-participant-notifications.md).
- The support list is the partner's current managed customers as a whole; there is no per-customer scope (MO-3 territory).
