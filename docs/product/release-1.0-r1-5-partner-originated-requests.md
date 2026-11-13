# Release 1.0: R1-5 Partner-originated Service Requests (design record)

**Status:** implemented on `feature/r1-5-partner-originated-requests` from `main` at `d098709` (R1-4, PR #63). Not merged, not deployed; Release 0.9 staging is untouched.
Owner direction (2026-11-12): allow appropriately authorized partner-support personnel to create requests for contacts at customer organizations their partner manages,
preserving the R1-2 attribution model. No new access to anything that exists: the R1-4 support surface is the only place this authority is exercised.

## 1. The attribution contract

| Column | Meaning | Decided by |
|---|---|---|
| `tickets.user_id` | the **requester**: the customer contact who needs the support | the request names an id; the server accepts it only if it is an **eligible customer contact** of the organization (§2) |
| `tickets.organization_id` | the managed customer organization | the request names an id; the server accepts it only if the actor holds **effective support authority** over it right now (R1-4 §1) |
| `tickets.submitted_by_id` | the authenticated partner staff member | **never input**: `$actor->id` |

All three are produced by one value, `TicketAttribution(organizationId, submittedById, requesterId)` (R1-2 gave it two fields; `requesterId` is the third). It is built only by
`ServiceRequestAccess`: `attributionFor()` (the requester's own request: requester = submitter = actor, untouched) and the new `attributionForPartnerSupport()`. `TicketService::createWithReference()`
(private) now sets `user_id` from the attribution too, so no caller passes a requester in an attribute array. A `user_id`, `submitted_by_id`, `status`, `assignee_id`, due date or internal flag in the
payload is not validated and not read.

## 2. Contact selection (the owner decision, settled without escalation)

The Ticket model needs a `User` requester; Person and non-User requesters are deferred. The named-contact workflow is satisfied by an **existing User**:

> An **eligible customer contact** of customer organization `C` is an account that (1) has a **current membership row** of `C`, (2) is active (`users.disabled_at` null), (3) is **not an operator**
> (portal.operator or the operator role, directly or through a role), and (4) holds `tickets.create` **and** `tickets.view` (it could raise and read a request itself).

Single definition: `OrganizationAuthority::customerContactIds()` (tables only: no permission cache, no lock, safe under the organization locks for the reason `isMembershipManager` documents) plus the locked membership read in
`PartnerSupportAccess::refusal()`. Not inputs: membership role, administrator approval, a CRM link, other memberships. A user with several memberships is a contact of each customer they belong to; a user who is a member of
the customer *and* designated partner staff may name themselves.

No account creation, no invitation, no free-text "requester name". A customer with no eligible contact cannot be raised for: the page says so and points to Intechral (an operator adds the member or an invitation is issued). The R1-3 invitation flow is how
contacts come to exist.

**Disclosure.** The page receives, per supported customer, the contacts' **id, name and email** (email disambiguates two people of one name); nothing else of the account. The same people's names are already shown in the R1-4 supported list. There is no cap on the
list: at pilot scale a customer organization has tens of contacts; a search control is a convenience for later.

## 3. Authorization and concurrency

Door: `TicketPolicy::createAsPartnerSupport` = `PartnerSupportAccess::holdsGrant` (R1-4), checked by the controller before validation and again inside `attributionForPartnerSupport()` before any lock. Operators never hold effective authority and are refused. No Spatie permission is
consulted for the actor: the operator-issued designation is the authority, exactly as for the R1-4 reply (a designated member whose role lacks `tickets.create` can still use the support route; they are not creating for themselves).

`PartnerSupportAccess::refusal($actor, $customer, $contact)` is the R1-4 locked decision (renamed from the body of `assertLocked`, which is now its boolean wrapper, unchanged for the reply) extended with the contact. Lock order, inside the creation transaction and before the INSERT:

1. **users S and contact, one statement, ascending id, shared**; 2. **customer and partner organization rows, one statement, ascending id, shared** (the order `OrganizationRelationships::lock()` takes its exclusive locks in); 3. the actor's **partner membership** (shared);
4. the contact's **customer membership** (shared). Every writer of these rows takes user, then organization, then membership, so there is no cycle. A revocation, suspension, membership removal, disable or relink that committed before is seen; one that has not makes the creation wait and applies after it
(real two-connection tests F to J). The ticket INSERT's foreign-key checks take shared locks on the same rows.

**Refusals.** All write nothing (no row, attachment, file, mail, job) and are reported without disclosing existence:

| Situation | Answer |
|---|---|
| no effective grant at all, suspended or operator account, closed mode, guest | **404** (guest: login redirect) |
| customer not supported (unmanaged, managed by another partner, the partner's own, disabled, unlinked, nonexistent) or a designation revoked while the request was in flight | **422** on `organization_id`: *That customer is not available for this request.* |
| contact not eligible, whatever the reason (nonexistent, not a member of this customer, operator, suspended, lacks capabilities) | **422** on `requester_id`: *That contact is not available for this request.* |

The contact is examined only after the customer is proven supported. A granted actor therefore cannot tell an unmanaged organization from one managed by someone else (found by the tests: the first locked draft answered 404 for the latter).

## 4. Surface

`GET /support/requests/create` (`support.create`) and `POST /support/requests` (`support.store`): `auth` only, in the pilot allowlist (closed mode: 404), literal routes registered before `{ticket}`. React `support/create` (existing form components, the requester page's field set, plus a required
**Customer** choice when there are several, fixed when one, and a required **Contact** choice that is never defaulted and clears when the customer changes). The list page header gains "New request". Navigation: the Supported requests entry also matches `support.create`.
The shared content rules (`TicketController::contentRules()`) serve both forms, so they cannot drift; the creation transaction, reference issuing, deadlock retry, attachments and operator alert are `TicketService`'s one writer (`createAttributed`), not a copy.

After creation the staff member lands on the R1-4 support page of the new request. Nothing else becomes visible to them: `tickets.show`, the reply route and every operator route still refuse (tested).

## 5. Email semantics (unchanged) and what is recorded for R1-6

The **requester** (the contact) receives the ordinary receipt (`TicketCreatedNotification`, "Your support ticket ... has been received"), unless suspended; the operator alert is sent as before. The **submitter is not mailed** and neither mail says the request was raised by partner support.
Recorded for R1-6 rather than built: a creation notice to the submitter; "raised for you by <partner staff>" wording in the requester's receipt; the operator alert naming the submitter (the operator page and the CSV report already show "Submitted by" when it differs); notifying the partner when the requester or an operator replies.

## 6. Not in R1-5

Person and non-User requesters, account creation from this form, a per-customer grant scope, contact search/pagination, attribution correction after creation, any change to what partner support may read or do after creation, terminology (R1-7).

## 7. Independent security review (Opus, 2026-11-12): SAFE AFTER TARGETED REMEDIATION

The provenance core was confirmed: payload ids are requests re-decided under locks, ordinary creation is unchanged, the R1-4 reply path keeps its lock shape and result, no permission-cache write occurs under an organization lock, and the staff member gains nothing beyond R1-4. Findings and dispositions:

| # | Sev. | Finding | Disposition |
|---|---|---|---|
| 1 | Medium | A contact suspended after the transaction's read view began but before the locks was accepted: `refusal()` locked the contact row but only checked it existed; "active" came from the snapshot read in `customerContactIds()` | **Fixed.** `refusal()` refuses on the LOCKED row's `disabled_at`. Race test K (suspension between the grant read and the locks) fails without the check |
| 2 | Low | `support.store` is unthrottled and mails a third party | **Fixed.** `PartnerSupportCreateThrottle`: 60 attempts an hour per account, on that route only, refused attempts counted (tested) |
| 3 | Low, owner decision | Contact **emails** are newly shown to partner staff (id, name, email of every eligible member of every supported customer) | **Approved by the owner (2026-11-12).** Contact name and email are disclosed because two contacts of one name cannot otherwise be told apart. The approval is limited to currently authorized partner-support staff (effective grant, read from the tables on every request) and to eligible contacts belonging to the customer organizations they support right now. It does **not** authorize a general contact directory, search over contacts, or any other contact attribute. To tighten later, drop or mask `email` in `creationOptions` and the page |
| 4 | Info | The contact id was locked before the customer was proven supported; an id above the highest user takes a gap lock that a concurrent registration's INSERT waits on (possible 1213, retried, not a safety issue) | **Fixed.** The contact is nominated by a plain read and only an existing id is locked (test M); existence is decided after the customer is proven supported |
| 5 | Info | The locking-read guarantees (here, the reply's case B and G/I) assume `innodb_snapshot_isolation=OFF`; MariaDB 11.6.2+ defaults it ON and a stale read would then fail with error 1020 (closed, as a 500) | **Recorded; deferred to release readiness** (owner, 2026-11-12): confirm the production database's `innodb_snapshot_isolation` setting. 11.4.13 is pinned; `DatabaseProfile` does not check the variable. Candidate for the profile check before any MariaDB upgrade |
| 6 | Tests | Mid-flight actor suspension, the operator alert on the partner path, a misleading title | **Done** (test L, alert assertion, retitled) |

## 8. Evidence and residual risks

Hosted CI and the browser suites have not run for this branch. Residual: a contact whose membership or capabilities change after the page was served is refused by the server, never silently re-pointed. On the contact, ACTIVE is decided on the locked row; **operator status and the two capabilities are table reads outside the locks**
(like every permission read in this codebase, and like the actor's own operator check), so a role edit that commits during a creation can be missed. The consequence is a request whose requester just lost a capability or became an operator, not a disclosure. **Preserved for pre-RC1 security convergence** (owner, 2026-11-12).
