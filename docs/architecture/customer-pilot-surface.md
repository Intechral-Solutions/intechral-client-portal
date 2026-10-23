# Customer Pilot Surface Gate (Release 0.9, R0.9-CS1)

**Status: implemented; remediated after two independent reviews (Opus 5.5: the first and the closure review both returned NEEDS
REMEDIATION; findings R-1 to R-3 of the second are corrected here). The final focused closure review (Opus 5.5) returned SAFE TO
COMMIT. Integration approval is not customer approval: not merged, and Release 0.9 is not customer-ready.**

**Package designation.** This package is **R0.9-CS1 — Customer Pilot Surface Gate**. It was first built as "R0.9-2A"; that label is
kept only for the separate hosting-discovery work in the [release scope](../product/release-0.9-scope-and-architecture.md).

This document is the contract for what a pilot customer can reach, how that is enforced on the server, and what is
still undecided. Sections 1 and 2 are the authorization inventory written before the code; section 2.1 is amended to
record that the first design classified customers by operational capabilities and why that was replaced (section 4.1).
Sections 3 onward are the implemented design.

## 1. Owner-approved contract

For the initial Release 0.9 customer early access:

| Allowed | Excluded |
|---|---|
| Customer Helpdesk | Customer finance and invoice interfaces |
| Projects the customer is authorized to see | Customer payments |
| A customer-appropriate project overview | Customer time tracking |
| Customer-appropriate milestone names and dates | Task boards and task details |
| | Project and task comments and checklists |
| | Internal project activity |
| | CMS administration and management |

SSO is deferred for the first pilot. Operators keep their existing functionality. Exclusion is enforced on the server,
including direct routes and JSON requests; hiding navigation is presentation only.

Not approved and **not implemented here**: the H6 project deletion policy, the suspended-status privacy ruling (Info-5),
the remaining H5 to H10 decisions, administrator recovery and operator MFA.

## 2. Authorization inventory (Phase 1)

### 2.1 Who is a customer, who is an operator

There is no `is_operator` column and no user type. Identity is capabilities (Spatie permissions, through roles):

- The built-in `user` role (`PermissionCatalogue::userDefaults()`) is what every invitation assigns
  (`InvitationService::acceptWithPassword` and `acceptWithSocialAccount`). It holds `tickets.view`, `tickets.create`,
  `tickets.view_org`, `projects.view`, `projects.view_org`, `tasks.view_org`, `billing.view`, `billing.view_org`,
  `time.log`, `time.view_own`, `time.view_org`, `cms.view`.
- The built-in `operator` role carries the whole catalogue.
- Custom roles may carry any mix, so the role name proves nothing, and neither does any single operational capability:
  `settings.view` gates no route at all, `tasks.view_all` and `time.view_all` only widen a list. The first design called an
  account an operator when it held any of 11 such capabilities (the shell's Operational classification); the independent
  review rejected that (finding F-2), and the boundary now depends on one explicit capability, `portal.operator`
  (section 4.1).

Consequence: the built-in `user` role alone does not define "customer", and no operational permission defines "operator".

### 2.2 Routes a customer can reach today (before this package)

Every authenticated customer holding the `user` defaults can reach all of the following. None of it is approved.

| Area | Routes (names) | Why reachable |
|---|---|---|
| Finance, invoices | `billing.client.invoices.index/show` (`/my/invoices*`) | `auth` only; `InvoicePolicy::view` lets `billing.view` see own invoices |
| Customer payment | `billing.invoices.pay`, `billing.invoices.pay.intent` | `auth` only; `InvoicePolicy::pay`; creates a Stripe PaymentIntent |
| Time tracking | `time.*` (`/time*`): list, store, timers, allocation, context options, update, delete | `can:time.log` is a `user` default |
| Standalone tasks | `tasks.*` (`/tasks*`): list, store, bulk, show, update, delete, complete, reopen, assignee | `auth` only; TaskPolicy decides rows |
| Project board | `projects.board` | `auth`; `ProjectPolicy::view` (member) |
| Project tasks | `projects.tasks.*` incl. comments and checklist mutations | `auth`; membership |
| Project time | `projects.time.index` | membership plus `time.log` |
| Project milestones | `projects.milestones.index` | membership; also leaks descriptions, completer names, task counts |
| Project overview | `projects.show` | membership; leaks task counts, health reasons, time total, milestone descriptions and `completedBy {id,name}` |
| Project list | `projects.index` | `auth`; leaks health, task progress, member count, target date |
| CMS viewer | `cms.index`, `cms.show` (`/pages*`) | `cms.view` is a `user` default (read-only published content) |
| SSO | `sso.redirect`, `sso.callback` | no middleware; login, invitation and link intents |
| Helpdesk | `tickets.*`, `tickets.replies.store`, `tickets.attachment.download` | the approved surface |

Operator-only routes (`operator.*`, `crm.*`, `organizations.*`, `admin/*`, `billing.invoices.*` management,
`projects.create/store/edit/update/destroy/companies/members`, `invitations.store`) are capability-gated and unreachable
by a customer; they are listed so the denylist is complete.

### 2.3 Serialized data a customer receives today

Removing a component does not remove props already sent. The Inertia props that exist today and carry non-approved data:

- `dashboard/customer-home` `invoices` (outstanding invoice count and link).
- `projects/index` rows: `health`, `tasks`, `targetDate`, `nextMilestone`, `memberCount`, `abilities`.
- `projects/show` (Overview): `description`, `health`, `tasks` counts, `milestones` (descriptions, task counts, completer
  `{id,name}`), and for a user with `time.log` a project `time` total.
- `projects/milestones/index`: milestone descriptions, completer names, task counts.
- Shared `auth.permissions` lists every capability (including `time.log`, `billing.view`); the React timer provider keys
  off `time.log` and polls `/time/timers/active`.
- Shared `navigation`: Focused entries "Invoices" and "Resources".

### 2.4 Other entry points

- **Stripe webhook** `POST /webhooks/stripe`: unauthenticated, CSRF-exempt, verified by Stripe signature. A customer has
  no business there; it is not customer functionality. It must keep working for Stripe and for the operator payment
  infrastructure, so the boundary does not apply to unauthenticated requests.
- **SSO**: `login` (an existing linked account signs in directly through `Auth::login`, **bypassing the two-factor
  challenge**), `invitation` (creates a new `user`-role account) and `link` (attaches a provider to the signed-in
  account). Operators use SSO login and must not be disrupted.
- **Attachments**: the only download route is `tickets.attachment.download`, authorized by `TicketPolicy::downloadAttachment`
  (own ticket, no internal-note attachments). The `storage.local` signed-URL route exists in development for the `local`
  disk and serves only signed URLs.
- **Fortify**: login, logout, password reset, two-factor challenge and management, password confirmation, profile
  information and password update. These are account essentials, not modules.
- **Unnamed routes**: `/` (redirect), `/up` (no middleware), `_inertia/devtools/*` (development only).

## 3. Modes and allowlists

The boundary has three modes, resolved by `CustomerPilotGate::mode()` (section 5). In every mode an **operator** (an account
holding `portal.operator`), a guest and an unauthenticated external caller are untouched.

| | closed (default) | pilot | legacy |
|---|---|---|---|
| Applies to | customers | customers | nobody (restricts nothing) |
| Where honoured | everywhere | everywhere | only `APP_ENV` `local` or `testing`; elsewhere it resolves to closed with a warning |
| Customer dashboard | "the portal is not open to your account yet", no business data | Home: own requests and a project count | the pre-boundary dashboards |
| Helpdesk | no | yes | yes |
| Projects | no | list and reduced overview (section 6) | full pre-boundary pages |
| Account, password, two-factor, sign out | yes | yes | yes |
| SSO | deferred | deferred | as before |
| Finance, payments, time, tasks, board, comments, CMS viewer | no | no | yes |

### 3.1 Allowlists (exact route names, enforced by `EnforceCustomerPilotSurface`)

| Set | Route names | Extra capability the gate requires |
|---|---|---|
| `ACCOUNT_ROUTES` (closed and pilot) | `root`, `dashboard`; `logout`, `profile.show`, `profile.password.confirm`, `profile.sessions.destroy`, `profile.social.unlink`; `password.confirm`, `password.confirm.store`, `password.confirmation`, `user-password.update`, `user-profile-information.update`; `two-factor.enable`, `.confirm`, `.disable`, `.qr-code`, `.secret-key`, `.recovery-codes`, `.regenerate-recovery-codes`; guest routes `login`, `login.store`, `password.request`, `password.email`, `password.reset`, `password.update`, `two-factor.login`, `two-factor.login.store`, `invitation.show`, `invitation.register` | none |
| `PILOT_ROUTES` (pilot only) | `tickets.index`, `tickets.show`, `tickets.create`, `tickets.store`, `tickets.replies.store`, `tickets.attachment.download` | none (the routes' own `can:` middleware and `TicketPolicy` still decide) |
| | `projects.index`, `projects.show` | `projects.view` |

### 3.2 Denylist

Everything else, by default; the allowlist is the control. Named so a review can check them: `billing.*`, `time.*`, `tasks.*`,
`projects.board`, `projects.tasks.*`, `projects.time.index`, `projects.milestones.*`, `cms.*`, `sso.redirect` and `sso.callback`
for a signed-in customer, `operator.*`, `crm.*`, `organizations.*`, `roles.*`, `users.*`, `invitations.store`, `webhooks.stripe`
for a signed-in customer (Stripe itself is unauthenticated and unaffected), and every unnamed route. In closed mode the Helpdesk
and projects are denied as well.

### 3.3 Owner decisions applied (R0.9-CS1 remediation)

Project descriptions remain operator-only; milestone completion is withheld; `/pages` (published CMS content) stays outside the
pilot; profile, password, two-factor and sign-out stay available. These do **not** ratify H5 to H10 or any other release policy.

## 4. The boundary

### 4.1 Who is a customer: explicit operator eligibility

A **customer** is any signed-in account that does **not** hold the effective capability `portal.operator`
(`PermissionCatalogue::PORTAL_OPERATOR`). That is the only test.

- `portal.operator` is a **trust designation**, not a permission to do anything. It exempts an account from the customer
  boundary and grants nothing else: every endpoint still checks its own capability. Holding it alone reaches a 403, not a page.
- It is granted to the built-in `operator` role by the code-owned catalogue through `roles:sync` (section 10). The built-in
  `user` role never holds it. A custom role holds it only if an authorized operator (`roles.manage`) puts it there on the Roles
  screens, or a user is given it directly through the existing grant paths.
- Nothing implies it. An account holding `settings.view`, `tasks.view_all`, `time.view_all` or every other capability
  **except** `portal.operator` is a customer. The first design inferred operator from 11 operational capabilities; that made
  `settings.view` (which gates nothing) sufficient to leave the boundary entirely. `CustomerPilotGateTest` proves the new rule
  against real roles, including a role with every other capability.
- It is checked on every request through the live permission check, so granting or revoking it (directly, on a role, or by
  assigning or removing the built-in `operator` role) changes the classification on the next request of an existing session.
- An account with no roles is a customer (restricted).
- **Data-scope guarantees.** Eligibility is also what keeps an operational capability from widening a customer's data on the routes
  a customer is allowed. For any account without `portal.operator` in closed or pilot mode (closed allows none of them), these
  hold whatever else the account holds, `tickets.assign` and `projects.admin` included (found by the closure review, R-1):
  - *Tickets.* The account sees, replies to and downloads attachments of tickets it owns, exactly as under the customer Helpdesk
    policy. `TicketPolicy` stops treating `tickets.assign` as operator access for it, so a foreign ticket is refused (403, never
    a redirect into the operator application), internal notes and their attachments stay unavailable even on its own tickets, an
    "internal" flag on its reply is ignored (it is stored as an ordinary public reply), and `TicketService` treats its replies with
    the customer lifecycle (a closed ticket refuses a reply; a reply moves `pending_user` and `resolved` tickets on). Operator ticket
    routes are not on the allowlist at all.
  - *Projects.* The list and the overview require actual membership. `ProjectPolicy::administers()` and `Project::scopeVisibleTo()`
    stop treating `projects.admin` as "every project" for it. The operator project routes are not on the allowlist at all.
  - *Navigation.* The operator ticket queue and "New project" are not offered to it.
  - Eligible operators and legacy mode are unchanged: the same policies and scopes give them what they always gave.
  - `TaskPolicy`/`TaskRowAbilities` still read `projects.admin` directly; every task route is outside every customer allowlist, so
    this is unreachable for a restricted account, and the policy parity tests (legacy mode) are unchanged.
- The shell's Operational/Focused choice is unchanged and independent. An account that selects the Operational shell by its
  capabilities but lacks `portal.operator` is a customer to the boundary and receives the customer dashboard and filtered
  navigation inside the Operational frame; this is a known presentation inconsistency, not a leak.

### 4.2 Enforcement

`App\Http\Middleware\EnforceCustomerPilotSurface` is appended to the `web` group and prioritized **before** `SubstituteBindings`
(after `StartSession`), so a refused route loads no record and reaches no controller. For a signed-in customer in closed or
pilot mode:

| Route | Answer |
|---|---|
| on the mode's allowlist | passes; the route's own middleware and policy still decide |
| on the allowlist, needs a capability the customer lacks (`projects.*` without `projects.view`) | 403, before any record is loaded |
| anything else, **including unnamed routes** | 404, for every verb, identifier and `Accept` header |

404 (not 403) because the module is absent from the surface, and a 403 would confirm that a record at that URL exists.
Guests, operators, legacy mode and unauthenticated external callers (the Stripe webhook) pass untouched. Existing policies are
unchanged and still apply on top of every allowed route.

### 4.3 Where the data is controlled

The route boundary is the control. Further places carry the same decision so no data is serialized for an excluded module:

- **Dashboard.** `DashboardController` decides on the boundary first, never on the shell: a customer gets
  `dashboard/customer-unavailable` (closed, no props) or `dashboard/customer-home` (pilot, `invoices` is `null`). An account
  with operational capabilities and no `portal.operator` can therefore never receive the operator dashboard.
- **Project pages.** `ProjectController::index` and `::show` hand a customer to `Customer\CustomerProjectController`, which reads
  explicit columns and builds every prop through `CustomerProjectPresenter` (section 6). The decision is made before the policy,
  so a project the customer may not see answers exactly like one that does not exist (404, never 403).
- **Shared Inertia props.** `auth.permissions` is `[]` in closed mode and `tickets.view`, `tickets.create`, `projects.view` in
  pilot mode (the React timer provider keys off `time.log`). `navigation` is built by the one `NavigationBuilder`, which keeps
  `home` (closed) or `home`, `helpdesk`, `projects` (pilot) for both renderers, so error pages carry no link into an excluded module.

## 5. Mode configuration

| | |
|---|---|
| Setting | `config('portal.customer_surface_mode')`, from `PORTAL_CUSTOMER_SURFACE_MODE` (`config/portal.php`) |
| Default | **closed** |
| Values | `closed`, `pilot`, `legacy` (case and surrounding whitespace ignored) |
| Anything else | **closed**: unset, missing key, `null`, empty, whitespace, a boolean, a number, an array, a typo, `(null)`/`(empty)` |
| `legacy` | honoured only when `APP_ENV` is `local` or `testing`; anywhere else it resolves to **closed** and logs a warning once per value |
| Per-module switches | **none**: each mode's surface is code (`CustomerPilotGate::ACCOUNT_ROUTES`, `PILOT_ROUTES`) and a pinned test |

Fail-closed properties, each tested: a configuration cached before the key existed has no key (closed); the previous
`customer_pilot_gate` boolean is ignored (closed); dotenv conversions (`null`, `empty`, `true`, `false` and their parenthesised
forms) never name a mode (closed); and nothing in `app/` reads the environment variable, so a cached configuration cannot change
behaviour.

**Environments.** `.env.example` ships `closed`. The development stack sets `legacy` in `docker-compose.yml`
(`PORTAL_CUSTOMER_SURFACE_MODE=${PORTAL_CUSTOMER_SURFACE_MODE:-legacy}`) so the browser suite keeps its meaning, and
`phpunit.xml` sets `legacy` so the Pest suite's baseline is the pre-boundary behaviour; every test of the boundary states its
mode. `legacy` is not a supported production mode.

## 6. Project overview field exposure

Project data has no internal/public marker, so only what the owner contract names is treated as customer-safe.

| Sent to a customer (pilot) | Project list | Overview |
|---|---|---|
| Project id (to open it) | yes | no |
| Project name | yes | yes |
| Lifecycle status (`active`, `on_hold`, `completed`, `archived`) | yes | yes |
| Milestone name | no | yes |
| Milestone due date | no | yes |

**Withheld on purpose**, whatever the viewer holds: the description, budget, start and target dates, health and its reasons, task
counts and progress, project time, members and their names and roles, creator, client and company links, **milestone completion
state**, milestone descriptions, task counts per milestone, the completion timestamp and the completer's name, and every task,
comment, checklist item and activity record. Visibility stays membership (`Project::visibleTo`, `ProjectPolicy::view`); a company
link never widens it.

**Existence.** A project the customer is not a member of is answered exactly as one that does not exist, for every request form:
HTML, JSON, Inertia with the current asset version, Inertia with a stale or missing version.
- The decision is made in `CustomerProjectController` before the policy, and the customer boundary replaces the JSON 404 body for a
  restricted customer, because the framework puts route-model binding's own message (`No query results for model [...] 12`) in a
  JSON response even with debug off.
- Inertia answers 409 to a stale `X-Inertia-Version` as soon as its middleware runs. That used to be after route-model binding, so an
  existing project got 409 and an unknown id got binding's 404 (closure review R-2). `HandleInertiaRequests` is now prioritized
  before `SubstituteBindings` (after the customer boundary), so both answer 409 for a stale version, and for the current version
  both answer 404. A refused module is still a plain 404 whatever version is sent.
- The 403 for a customer without `projects.view` is decided before any record is loaded, so it cannot depend on the id.
- With `APP_DEBUG=true` the HTML 404 for an unknown id can differ; production runs with debug off.

## 7. SSO and the other entry points

- **SSO is deferred for the first pilot** and no new SSO implementation was written.
  - *Login:* a callback that resolves a customer account answers exactly as for an unlinked identity. Operators sign in as
    before. This also closes the path that signs a user in without the two-factor challenge.
  - *Invitation:* every invitation creates a customer account, so in closed and pilot mode SSO acceptance is refused at the
    redirect and the callback, no account is created and the invitation stays pending. The invitation page offers the password
    form only (`providersAvailable`). Password acceptance is unchanged. A **missing, empty, repeated (array) or unknown**
    `invitation` query value is answered at the redirect with the ordinary "This invitation is no longer available." sign-in
    redirect before anything is built from it (an empty token used to reach `route('invitation.show', '')` and return 500).
  - *Link:* `sso.redirect` and `sso.callback` are not on any allowlist, so a signed-in customer gets 404. The profile page lists
    no provider to connect; an already-connected provider is listed so it can be disconnected.
  - The login page keeps its provider buttons: a guest is not yet known to be a customer, and operators use them.
- **Stripe webhook.** Unauthenticated and signature-verified; the boundary does not touch it. A *signed-in customer* posting to
  it gets 404.
- **Tickets and attachments.** The only attachment download route is `tickets.attachment.download`.
  `TicketPolicy::downloadAttachment` allows the requester's own ticket and never an internal-note attachment; for an account
  without `portal.operator` that is true even when it holds `tickets.assign` (section 4.1), on foreign tickets and on its own. A
  refused download, reply or internal note changes no row, and a foreign ticket page never redirects to the operator application.
  Tested in pilot mode with real persisted roles, and in closed mode (every one of these routes is a 404 there).
- **`storage.local`.** The framework's signed-URL route for the `local` disk is registered outside the web group and serves only
  validly signed URLs; it is not part of this package.

## 8. Remaining owner decisions and limitations

1. **Decided for this pass (not ratified policy):** descriptions operator-only; no milestone completion; no `/pages`; account
   essentials allowed. Revisit when the pilot surface is widened.
2. **Not decided here (unchanged):** H5 to H10, Info-5 (suspended-status privacy), administrator recovery and operator MFA.
3. **Eligibility is a human decision.** Nothing grants `portal.operator` to a custom role automatically. A custom operator role
   that is not given it turns its members into customers (section 10 tells how to find them first).
4. **Closed is the safe default, not a launch.** `closed` customers cannot use the portal at all; opening the pilot is the
   deliberate act of setting `pilot` after the section 10 steps.
5. **Shell inconsistency** described in section 4.1 (an Operational-shell account without eligibility).
6. Customer finance is switched off, not removed: invoices, payments and Stripe code, data and tests are retained.
7. **Inherited foreign-ticket 403 versus 404 (accepted limitation, closure review I-b).** For the ticket routes a customer is
   allowed, a ticket that exists but belongs to someone else is refused with 403 by `TicketPolicy`, while an id that does not exist
   is a 404. A customer can therefore tell an existing foreign ticket from an absent one. This is the behaviour the Helpdesk
   customer policy already had before this package; R0.9-CS1 preserves it and only ensures the account cannot read, reply to or
   download anything of that ticket. Unlike the project overview (section 6), it is not converted to a uniform 404. Ticket ids are
   sequential integers, so this discloses that a ticket id exists, nothing about its content. Changing it is a separate decision
   that would alter Helpdesk behaviour for all customers.

## 9. Tests, and which mode each proves

| Mode | Where | What |
|---|---|---|
| legacy | the rest of the Pest suite (`phpunit.xml`), the full Playwright suite and its hosted job | the pre-boundary behaviour is intact |
| closed | `tests/Feature/Pilot/CustomerClosedModeTest.php`, `CustomerPilotRouteBoundaryTest.php` | no business route, API or prop; account routes work; operators unaffected; missing config is closed |
| pilot | `CustomerPilotProjectDisclosureTest.php`, `CustomerPilotSurfaceTest.php`, `CustomerPilotRouteBoundaryTest.php` | the approved surface; exact-key props; 404 existence protection; SSO deferral |
| eligibility | `CustomerOperatorEligibilityTest.php`, `CustomerPilotGateTest.php`, `CustomerSurfacePreflightTest.php` | real roles and persisted grants, promotion and demotion on a live session, mode parsing, legacy refusal in production |
| pilot, browser | `tests/Browser/customer-pilot.spec.ts` | the same boundary in Chromium; **required** (cannot skip) in the hosted `browser-pilot` job |

The route sweep walks every registered route for both restricting modes; mutation probes (listed in the package handback) remove
each piece of the boundary and every one makes a test fail.

## 10. Deployment: operator eligibility and `roles:sync`

Adding `portal.operator` changes the built-in role catalogue, so **the pilot cannot be opened until `roles:sync` has been applied**,
and until it has, every operator is a customer to the boundary. There is no automatic privilege migration and no competing
provisioning tool: `roles:sync` (R0.9-1D) is the only path.

**Deploy in maintenance mode, and resume traffic only once operator eligibility is established.** This describes a deployment **over a release that is already served**. A **first installation has no `current` release and does not use maintenance mode**:
nothing is served until the application is ready, because the document root is pointed at the release only after `roles:sync`, the first operator and the pilot-mode cache are done, which meets the intent of this order (and the cron wrapper and cross-context check cannot run under
maintenance mode at all). See [Staging First Install, why this order](../operations/staging-first-install.md#why-this-order) items 3 and 4. For every later release the order below is the control:

1. **Take the application down.** `php artisan down` (or the host's maintenance page) before the new code serves any request, so no
   operator or customer is served between the code and the grant. The first requests after code and before grant would treat every
   operator as a customer.
2. **Deploy the code and migrate.** The mode stays `closed` (the default) or unset; do not set `pilot` yet. Rebuild the configuration cache
   if one is used.
3. **Check readiness (read-only).** `php artisan customer-surface:preflight`. It reports the resolved mode, whether `portal.operator`
   exists, whether the built-in `operator` role holds it and the `user` role does not, how many active accounts hold it, and lists every
   **active account and custom role that holds operational capabilities without it**. `--expect-mode=<mode>` asserts the mode the
   application resolved, `--strict` fails on unresolved accounts and roles, `--json` is for tooling. It reads the database, not the
   permission cache, and writes nothing. Before `roles:sync` it fails by design.
4. **Preview the grant.** `php artisan roles:sync` (dry run). The plan creates `portal.operator` and grants it to the built-in `operator`
   role: two additions, no revocations. A plan that revokes anything is not this change; stop and read it
   ([Role Governance](./role-governance.md)).
5. **Decide the custom roles.** For every custom role the preflight lists, an authorized operator decides: it is an operator role (add
   `portal.operator` on the Roles screen) or it is not (leave it; its members remain customers). Do **not** add it because the role has
   operational capabilities, and never to a role customers hold. The built-in `user` role cannot be edited.
6. **Apply (an authorized operator).** `php artisan roles:sync --apply --plan=<fingerprint from step 4>`. The R0.9-1C
   last-administrator protection and the R0.9-1D approval behaviour are unchanged. Do **not** run `--apply` against development data you
   care about; use a disposable database (the hosted `browser-pilot` job uses a fresh seeded one).
7. **Verify eligibility is established, before any traffic.** Re-run `customer-surface:preflight --expect-mode=<mode>` with no
   failures and the expected operator count, and with the application still in maintenance mode confirm by CLI that an operator account
   holds the permission (`php artisan tinker`: `User::where('email', ...)->first()->can('portal.operator')`).
8. **Set the mode and resume.** Set `PORTAL_CUSTOMER_SURFACE_MODE=pilot` (or leave `closed`), rebuild the configuration cache, re-run
   the preflight with `--expect-mode=pilot`, then `php artisan up`. Sign in as an operator and as a test customer and confirm each sees
   what section 3 says.

**Deployment preflight is not onboarding preflight.** The two uses differ:

- *Deployment (steps 3 and 7).* The diagnostic `customer-surface:preflight` may run **before** `roles:sync` and is expected to fail
  then; the failure is the report of what is still missing, not a fault. It is read-only and safe in maintenance mode.
- *Provisioning.* `portal.operator` is created and granted to the built-in `operator` role only through `roles:sync` (steps 4 and 6),
  during the maintenance window. Afterwards, verify that an authorized operator account actually holds the permission (step 7).
- *Onboarding.* Before **any actual customer is onboarded**, `customer-surface:preflight --strict --expect-mode=pilot` must succeed:
  `--strict` additionally fails while any active account or custom role holds operational capabilities without `portal.operator`.
  A passing non-strict run is a deployment check, not clearance to onboard.

A deployment that never sets the variable is **closed**: safe, and unusable for customers. A rollback that removes the grant, or a
role change that removes an operator's eligibility, turns that operator into a customer on their next request.

## 11. Independent review record

First review (Opus 5.5, 2026-10-13): **NEEDS REMEDIATION** and the route boundary itself sound. Findings and where they are
closed here: F-1 the gate failed open and "off" meant unrestricted (sections 3 and 5); F-2 any one of 11 operational capabilities
exempted a customer (section 4.1); F-3 the pilot browser spec skipped on any non-404 probe (section 9 and `docs/testing/ci.md`);
and the lower-severity findings: an empty SSO `invitation` token returned 500 (section 7); `projects.show` distinguished an existing
project from an absent one (section 6); and test gaps for role transitions and environment-string parsing (section 9). The focused closure review is
pending.

Closure review (Opus 5.5): **NEEDS REMEDIATION** with the redesign otherwise sound. R-1 (medium): an operational capability widened
the data scope of allowed routes for an account without `portal.operator` (`tickets.assign`: foreign internal-note attachments,
internal notes, redirect to the operator view; `projects.admin`: every project listed and openable) is closed in sections 4.1 and 7.
R-2 (low): a stale Inertia version distinguished an existing unauthorized project from an unknown id, closed in section 6. R-3
(low): the hosted `browser-pilot` step lost `./dev`'s exit code to `tee`, closed by `pipefail` and a negative control in
`docs/testing/ci.md`.

Final closure review (Opus 5.5): **SAFE TO COMMIT**. R-1, R-2 and R-3 are closed. Two documentation items were recorded afterwards:
I-b, the inherited foreign-ticket 403 versus 404 (section 8, item 7), and I-c, deployment versus onboarding preflight (section 10).
