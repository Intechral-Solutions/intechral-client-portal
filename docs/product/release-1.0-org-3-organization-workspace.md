# Release 1.0: ORG-3 the Organization workspace (design record)

**Status (2026-11-21): the design is APPROVED by the owner (recommended direction, with the three refinements of section 2). Only ORG-3a is implemented: on `feature/org-3a-portal-access-correctness`, reviewed independently (ACCEPT WITH CONDITIONS; the four conditions were closed by the final correction pass, section 4.1), and not merged until its pull request is (this record does not track the merge: the pull request and the [product README](./README.md) do). ORG-3b, ORG-3c and ORG-3d are PROPOSED, planned and unimplemented: nothing in sections 5 to 9 beyond ORG-3a exists, and none of those packages is authorized to start.** Written against `main` `11a6a73` (ORG-2, PR #75). DIR-3, DIR-4 and the [ORG-1 membership lock-order remediation](./release-1.0-org-1-membership-lock-order-followup.md) are independent of ORG-3a and are not started.

**What this record is.** It records the approved ORG-3 contract (the routed-tab Organization workspace), the owner's refinements, the four-package sequence, and, in section 4, what ORG-3a changed and how it was verified. Where it says **as built** it describes code; everything else is the plan. The governing decisions are [ADR-011 section 10](../architecture/adr/ADR-011-organization-consolidation-and-lifecycle.md) and the [ORG-0 record, ORG-3](./release-1.0-org-0-organization-consolidation.md#org-3-unified-organization-experience); this record refines them and, where it differs, says so.

## 1. The target experience (approved; proposed for 3b to 3d)

One Organization workspace under Directory → Organizations, four routed tabs, **one path per tab** (not `?tab=`): the same pattern the Projects pages already use (`page-tabs`, `aria-current="page"` links, no ARIA tablist). `organizations.show` stays the canonical identity URL and is the Overview tab.

| Tab | Route (proposed) | Shows | Ability |
|---|---|---|---|
| **Overview** | `organizations.show` | Details (website, phone, address, internal notes, created by), summaries of People, Projects and portal access, and the request count with a link to the existing `?organization=` queue filter | `manageDirectory`; each summary only for its own ability |
| **People** | `organizations.people` | Affiliated People (`CrmContact`), paginated. Microcopy: "Affiliation records where a person works. It grants no portal access." | `manageDirectory` |
| **Projects** | `organizations.projects` | Linked projects the viewer can already see (`Project::visibleTo`) | `manageDirectory` and `projects.view` |
| **Portal access** | `organizations.portal` | State, members, invitations, the managing relationship, the lifecycle action | `manageDirectory`; each subsection keeps its existing ability |

Around the tabs: one header built by one presenter (classification overline, name, portal-state badge, and a partner or managed fact only for an actor who passes `manageRelationships`, DIR-0 invariant S7); one breadcrumb, Directory / Organizations / name; the lifecycle actions live only on Portal access, so each act has one place. **System → Organization access** (`organizations.access`, proposed) stays as a filtered list of ever-enabled Organizations, each row linking to the Portal access tab, never an editor.

Affiliation, membership, support designation and project visibility stay four separate things: People are the only appearance of affiliation, Portal members the only appearance of membership, support designations appear only in the Members subsection, and no count of projects is ever shown beyond what the viewer can see (section 2).

## 2. Owner decisions and refinements

The owner reviewed the proposed contract and selected its recommended direction on every question: **D1** one path per tab; **D2** `manageDirectory` on every tab and both lists; **D3** route names (the Directory list becomes `organizations.index`, the System list `organizations.access`; a ruling for ORG-3b); **D4** the wording "Disable portal access" and "Re-enable portal access", and a state-refused control hidden behind one explanatory sentence rather than shown disabled; **D5** an operator-only "Linked organizations" section on the project page; **D6** ORG-3 delivered as four pull requests, amending the ORG-0 rule of one pull request per package. With these refinements:

1. **No disclosure of hidden Project associations or counts** in any later workspace work. The Projects tab and the Overview show only projects the viewer can already see, and **no** sentence such as "some linked projects are not visible to you" and **no** hidden or total link count. (The first contract draft had both in the Projects tab and the delete section; they are withdrawn. ORG-3c must reconcile the delete section's blocker text with this rule, defaulting to the service's existing generic refusal and a list of visible projects only.) The project page's Linked organizations prop is left out entirely for anyone who may not manage the Directory, so a customer never learns what other Organizations a project is linked to.
2. **The `manageDirectory` read-authorization gap is corrected now, in ORG-3a**, rather than with the workspace: on `main` `11a6a73` a customer holding a stray `crm.manage` could open the System list and an Organization's detail (members, approvals, invitations, support designations). Section 4.
3. **Broad internal Project wire-key renaming is deferred** (`companies`, `linkedCompanyIds`, `projects.companies.sync`, `CompanySelector`, `syncCompanies`) unless a change is demonstrably necessary. It is no longer part of the planned ORG-3d scope.

## 3. The planned four-package sequence

Order: **3a → 3b → 3c → 3d.** DIR-3's backend (migration, service, preflight and its required design review) can run in parallel with all four; DIR-3's interface touches go after 3b; DIR-4 goes after 3d and DIR-3. The ORG-1 membership-writer remediation is independent of all four. Each is its own pull request with its own independent review.

| Package | Scope | State |
|---|---|---|
| **ORG-3a** Portal access correctness | The System list shows only ever-enabled Organizations; one reusable portal-state badge on both lists and the detail; the detail offers only the controls the state allows, with an explanatory sentence where one is absent; a confirmation for re-enabling; `manageDirectory` on both pages. No route, service, policy, permission, migration or lock change. | **Implemented on its own branch; not merged until its pull request is** (section 4) |
| **ORG-3b** Workspace frame, routes and navigation | The canonical header and breadcrumb, the Overview, People and Portal access tabs, `<x-ui.page-tabs>`, the route names (D3), retirement of `crm.companies.*`, navigation patterns, the "Disable/Re-enable portal access" wording (D4), redirects of every portal act to the Portal access tab | **Proposed. Not started** |
| **ORG-3c** Projects, Classification and cross-links | The Projects tab and the Overview project and request summaries, the Classification selector (`manageDirectory`, through `OrganizationDirectory::update`, built-in values only), the Directory list's Classification and state filters, Linked organizations on the project page (operator-only, D5), the gated Organization link on the operator request page, the project selector hiding non-Enabled unlinked Organizations from non-operators (ORG-2 section 17.5) | **Proposed. Not started** |
| **ORG-3d** Closure | The ORG-0 browser journey (create, enable in the same workspace, rename, classify, disable, delete not offered and a forced delete refused), `AGENTS.md` terminology, the information-architecture document, ADR-011 Amendment 2, the as-built record | **Proposed. Not started** |

## 4. ORG-3a as built

**Scope held.** No route, route name, URI, service, policy method, permission, role, migration, lock order or server-side refusal changed. `OrganizationPolicy`, `OrganizationMembership`, `OrganizationInvitations`, `OrganizationRelationships`, `PartnerSupportGrants` and `OrganizationDirectory` are untouched. The labels are the existing ones ("Disable organization", "Enable organization", "Send invitation"…); D4's wording is ORG-3b's.

**The defects, verified by reading the code and then by the new tests.**

| # | On `main` `11a6a73` | Now |
|---|---|---|
| C1 | `OrganizationController::index` had no portal-state filter, and its badge drew "Enabled" for anything that was not Disabled, so a Directory-only Organization appeared on System → Organization access as **Enabled**. The one existing test only asserted that the text "Not enabled" was absent and never that the Directory-only row was. | The list is `PortalState::whereEverEnabled` (Enabled and Disabled). The badge has no default arm. |
| C2 | `organizations.show` derived its status from `disabled_at` alone: a Not enabled Organization read "Enabled" with a Disable button, and Not enabled and Disabled ones were offered Add member, Send invitation, Make Admin, Approve administrator, Grant support access, Link customer and Resend, all of which the services refuse. | Each control is drawn only where the service would accept it (matrix below). |
| C3 | `organizations.index` and `organizations.show` relied on the route's `can:crm.manage` alone. A customer holding a stray `crm.manage` could read the list and the detail. | Both ask `manageDirectory` first (below). |

**The badge.** `<x-organization.portal-state :state="…" />` (`resources/views/components/organization/portal-state.blade.php`) takes a `PortalState`, draws its own label (`PortalState::label()`), a glyph and a tone (Enabled: check, success; Disabled: triangle, warning; Not enabled: dashed circle, neutral) and a `data-portal-state` attribute. It has no fallback arm and throws on anything that is not a `PortalState`. It is used by the System list, the Directory Organizations list and the detail header. The `OrganizationListFacts::portalStatus()` map and its string constants are removed: they were the fail-open source (a missing key meant Enabled), and the state is now drawn from the row each list already holds.

**The controls, per state.** Compared exactly in `OrganizationAccessPresentationTest` as the set of (method, path) pairs of every form on the page:

| Control | Not enabled | Enabled | Disabled |
|---|---|---|---|
| Disable organization | no | yes | no |
| Enable organization (re-enable), with a confirmation | no | no | yes |
| Add member; Make Admin (promote); Approve administrator | no | yes | no |
| Make Member (demote); Remove; Revoke approval | yes | yes | yes |
| Grant support access | no | yes | no |
| Revoke support access | yes | yes | yes |
| Send invitation; Resend | no | yes | no |
| Revoke an open invitation | yes | yes | yes |
| Designate as partner; Link customer; Link to partner or Reassign | no | yes | no |
| Unlink; Remove partner designation | yes | yes | yes |

The right-hand state of every row is what the service does: the acts that give authority need an Enabled Organization (ORG-1, `requireEnabled`), and the acts that take it away ask nothing of the state, "cleanup must stay possible while Disabled". The page says why a control is absent (one sentence on the Disabled alert and on the card it concerns). A never-enabled Organization shows an explanation and a link to its Directory profile and offers no state change. Its Members card says there are no portal members; the Invitations card and the relationship panel are drawn only if it holds a row they would act on (the application cannot create such a row; the preflight reports one), so cleanup stays reachable and an empty box of refusals is not drawn. **Re-enabling** asks for confirmation and states that invitations revoked when the Organization was disabled do not return; **Disabling** states that its open invitations are **permanently** revoked and that enabling again does not restore them, which `disable()` does (`revokeOpenForDisable`; `enable()` writes only `disabled_at`). The Disabled alert says the same and no longer claims that "nothing else is removed": it names the revoked invitations and says that members, approvals and relationships are kept (the final correction pass, section 4.1).

**Authorization.** `OrganizationController::index` and `::show` call `$this->authorize('manageDirectory', Organization::class)` first, as `CompanyController` does. The ability is `portal.operator` and `crm.manage` in an active account; no ability, permission or policy method is new. The three answers stay apart because the ability is asked after route-model binding: an Organization a tenant scope hides is a 404 (hidden and unknown alike), a visible one is a 403 for an actor who may not, and in the closed and pilot customer modes a customer keeps the 404 they always got. `show` keeps its separate `manageMembership` and `manageRelationships` checks: the three abilities answer alike today and are separate on purpose, so the plain member list branch stays, reachable only to an actor a later release lets into the Directory without membership authority.

**Tests changed, and why none is weakened.** Seven existing tests described behaviour this package corrects and were rewritten to the corrected behaviour. Three of them are the **Membership, Relationships and Invitation** tests that asserted `200` for a customer holding a stray `crm.manage` on the Organization detail (`OrganizationMembershipTest`, `OrganizationRelationshipsTest`, `OrganizationInvitationOperatorTest`: one test each, now `assertForbidden()`, names and comments updated; their content-absence assertions are kept). Four are assertions of `OrganizationVisibilityTest`: the System list now also asserts that the Directory-only row is **absent**; the stray-`crm.manage` actor is now `403` on the System list (it was `200` with the relationship facts withheld, and still builds no relationship fact); the plain member list is reached through a policy that passes `manageDirectory` and not `manageMembership`; and the Directory list asserts the rendered state per row instead of the removed `portalStatus` map. `OrganizationReadersInventoryTest` reclassifies `OrganizationListFacts` from DECIDES to DISPLAY (it no longer decides a portal state) and updates the controller's reason. No security, vocabulary or lifecycle assertion was relaxed.

**Not changed, and reported.** The Project edit selector still lists a Disabled Organization to a member (ORG-3c). The Disabled-state explanations repeat, the last-approved-administrator hint on a Disabled Organization suggests approving another administrator, which is impossible in that state, and `organizations.show` has no browser coverage at 390 px, in dark mode or in the Disabled and Not enabled states: all three are non-blocking and left to ORG-3b. (The navigation gate, first reported here as unchanged, was aligned in the final correction pass: section 4.1.)

### 4.1 Independent review and the final correction pass

Chronology, kept in order. **(1)** ORG-3a was implemented and handed back with the verification of section 6. **(2)** An independent Opus High review of the branch before its first commit (2026-11-21) returned **ACCEPT WITH CONDITIONS**: the portal-state implementation, the authorization enforcement and the lifecycle behaviour were accepted, and four pre-commit findings were raised. **(3)** The owner authorized a narrow correction pass, and only that, which closed them:

| Finding | Closed by |
|---|---|
| **F1** status wording went stale at the commit, and the ORG-0 status line still said nothing of ORG-2 was implemented | The documents now say "implemented on its own branch; not merged until its pull request is"; the ORG-0 opening statement says ORG-1 and ORG-2 are implemented and merged; the three Membership, Relationships and Invitation tests are recorded above; the review and its test results are recorded in section 6. |
| **F2** the Disable confirmation did not say the revocation is permanent, and the Disabled alert said "Nothing else is removed" | Both rewritten (section 4, "The controls"); `OrganizationAccessPresentationTest` reads the confirmation from the form's `onsubmit`, checks the alert text, and proves against the services that the open invitation is revoked by disabling and stays revoked after enabling again. |
| **F3** `OrganizationLeakageTest` carried a stale comment and accepted any of 200, 302, 403 and 404 for the stray-`crm.manage` Organization page | The stale lines are removed. A new test, in the existing `expectedSurfaceStatus` convention, pins the exact status per customer surface mode for the System list and the detail of the actor's own Organization (legacy `403`; closed and pilot `404`), a hidden Organization and an unknown id (`404` in every mode), each against an operator control that gets `200`; and asserts that the refused body holds no member name or email, invitation address, partner name or administration control. |
| **F4** the System item was keyed on `crm.manage`, so a customer with a stray grant was offered a link that ended in a `403` (the Directory item had the same dead end since ORG-2) | `NavigationBuilder` draws both **Directory** and **System → Organization access** under `$user->can('manageDirectory', Organization::class)` (`portal.operator` AND `crm.manage`, active account), the ability the pages ask. An operator and a custom-role operator with both capabilities keep both entries; a customer with `crm.manage` alone, an account with `portal.operator` alone and a suspended operator see neither; an ordinary customer's navigation is unchanged. No permission, role, policy method, route or customer route is new. |

Mutation probes of the correction pass (each production file restored and its sha256 compared): reverting the navigation gate to `crm.manage` fails six `NavigationBuilderTest` tests; removing the controller `authorize` calls fails the legacy case of the new leakage test.

## 5. ORG-3b to ORG-3d, as proposed

The routes, controllers, presenter, queries per page, tab visibility, error handling and per-state behaviour of the workspace are the approved contract of sections 1 to 3 and the [ADR-011 section 10](../architecture/adr/ADR-011-organization-consolidation-and-lifecycle.md) text. They are **not** repeated here as specification because none of it is authorized; each package will carry its own design review and its own owner authorization. Constraints that bind them already:

* every workspace route and both lists require `manageDirectory`; a tab renders only when its route would succeed; a direct URL to a hidden tab is 403;
* the security-reviewed act routes keep their names, URIs and `{organizationId}` parameters; only their redirect target changes;
* new files that read Organizations are classified in `OrganizationReadersInventoryTest`;
* the Classification is never an authority input and no customer, partner or mail response carries it;
* no schema change; no second editor, service or security path;
* converting the Directory or System pages to React, a merged People-and-members roster, modal editing of related records, relationship views and any rename of `crm.contacts.*` stay out.

## 6. Verification (ORG-3a)

All runs used a disposable MariaDB 11.4.13 on its own network (`org3a_net`, container `org3a_db`, database `intechral_client_portal_testing` inside it), one Pest process at a time. **The original development database (`portal_*`, subject to Q6) was not read, migrated, cleared or rebuilt, and Release 0.9 staging was not touched.** The `*Concurrency*` and `PermissionCache*` suites and the Playwright specifications were not run locally (they hang locally or need a queue worker, Mailpit and a seeded database); hosted CI is their gate and is mandatory before merge. Browser specifications were inspected for changed labels. A visual inspection of rendered pages (headless Chromium, 1280 px) is not a test; 390 px and dark mode were not inspected.

**Implementation run (the implementing session, before review).** Crm without the Concurrency files, PartnerSupport without its concurrency file, `Feature/Ui`, `NavigationBuilderTest`, `TicketOrganizationSurfacesTest`, `Unit/Architecture` and `Unit/Configuration`: 1333 passed, 3 failed (the three stray-`crm.manage` tests above, then rewritten); the key suites rerun: 401 passed; Accounts, Admin, Auth, Shell, Pilot and Console files rerun after the change. `pint --test`: 672 files pass. These are the implementer's own runs.

**Independent review run (the Opus review, separate from the above).** Focused Pest on the isolated `org3a_db`, sequentially, against the code as submitted for review: 1,152 passed across 30 files plus `BladeThemeGuardTest`; 157 passed across the rest of `Crm`, `Ui` and `Unit/Architecture` without the Concurrency files; Pint passed (1,309 tests in all). The reviewer's exact command lines are in the review hand-off, not reproduced here.

**Correction pass (final).** Run sequentially, one file or directory per Pest process, on the same isolated database: `NavigationBuilderTest` 84, `FocusedNavigationTest` 112, `ShellPresentationDriftGuardTest` 43, `BladeShellTest` 27, `OrganizationLeakageTest` 16, `OrganizationAccessPresentationTest` 30, `OrganizationVisibilityTest` 17, `DirectoryVocabularyTest` 7, `DirectoryRouteBindingTest` 9, `CustomerClosedModeTest` 9, `CustomerPilotGateTest` 64, `CustomerPilotSurfaceTest` 28, `TicketOrganizationSurfacesTest` 11, `BladeThemeGuardTest` 133 and `Unit/Architecture` 80 (670 passed); then `OrganizationMembershipTest` 40, `OrganizationInvitationOperatorTest` 67, `OrganizationRelationshipsTest` 25, `PortalStateTest` 6, `OrganizationDisableLifecycleTest` 25 and `Feature/Ui` 102 (265 passed). No failures and no skips; `pint --test` passes on 672 files; `git diff --check` is clean. A full Pest run was not repeated for this narrow pass.
