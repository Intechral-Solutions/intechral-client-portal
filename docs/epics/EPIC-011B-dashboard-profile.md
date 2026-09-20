# EPIC-011B: Dashboard and Profile Migration

**Status:** Implemented  
**Parent epic:** [EPIC-011: React Frontend Migration](./EPIC-011-react-frontend-migration.md)  
**Prerequisite:** [EPIC-011A: React Foundation and Coexistence Contract](./EPIC-011A-react-foundation-coexistence.md)  
**Decision record:** [ADR-007](../architecture/adr/ADR-007-inertia-react-frontend.md)

---

## 1. Goal

Migrate Dashboard and the authenticated Profile page from Blade to the implemented Inertia 3, React 19, and TypeScript foundation. These are the first production pages to exercise the persistent `AppLayout` with real aggregate data, permission-shaped content, Fortify-backed forms, named validation bags, sensitive security operations, redirect feedback, and mixed Blade/Inertia navigation.

This phase deliberately avoids the interaction and domain complexity of timers, allocation, drag-and-drop boards, Stripe, ticket workflows, and guest authentication. React replaces presentation; Laravel remains authoritative for routes, sessions, validation, authorization, tenant scoping, Fortify actions, and mutations.

Behavioral parity is required for valid behavior. Known broken behavior is repaired rather than copied. Pixel parity is not required.

## 2. Scope and Route Contract

### Pages converted to Inertia

| Method | URI | Name | Owner | Target component |
|---|---|---|---|---|
| `GET` | `/dashboard` | `dashboard` | `DashboardController::__invoke` | `dashboard/index` |
| `GET` | `/profile` | `profile.show` | `ProfileController::show` | `profile/show` |

Both remain behind `auth`. Existing route names and URLs remain stable.

### Existing mutation endpoints retained

| Method | Route name | Owner | Purpose |
|---|---|---|---|
| `PUT` | `user-profile-information.update` | Fortify | Update name/email with `updateProfileInformation` errors |
| `PUT` | `user-password.update` | Fortify | Update password with `updatePassword` errors |
| `POST` | `two-factor.enable` | Fortify | Create pending TOTP secret and recovery codes |
| `POST` | `two-factor.confirm` | Fortify | Confirm a pending TOTP code |
| `DELETE` | `two-factor.disable` | Fortify | Disable TOTP |
| `GET` | `two-factor.qr-code` | Fortify | Password-confirmed QR payload |
| `GET` | `two-factor.secret-key` | Fortify | Password-confirmed setup key |
| `GET` | `two-factor.recovery-codes` | Fortify | Password-confirmed recovery codes |
| `POST` | `two-factor.regenerate-recovery-codes` | Fortify | Generate replacement recovery codes |
| `DELETE` | `profile.sessions.destroy` | `ProfileController` | Revoke all sessions except the current session |
| `DELETE` | `profile.social.unlink` | `ProfileController` | Unlink Google or Microsoft |
| `GET` | `profile.password.confirm` | `ProfileController` | Enter the retained Blade password-confirmation flow and return to Profile |
| `GET` | `sso.redirect` | `SocialiteController` | Start a normal-document OAuth connection flow |

Fortify's `GET`/`POST user/confirm-password` routes remain Blade-owned in this phase. Sensitive Profile operations may cross that normal-document boundary when recent password confirmation is absent. Guest login, reset, invitation, and two-factor challenge pages remain Phase C work.

### Server-only behavior

The JSON QR, setup-key, recovery-code, and confirmed-password-status endpoints do not need React pages. They are focused authenticated security endpoints, not a separate API architecture.

### Blade views replaced

- `resources/views/dashboard.blade.php`
- `resources/views/profile/show.blade.php`

The shared Blade shell, authenticated navigation partial, timer partial/scripts, auth layouts, auth pages, and all other product views remain.

## 3. Current Behavior Inventory

### Dashboard

`DashboardController` currently performs bounded synchronous queries and renders:

- Open-ticket count: all unresolved tickets for users with `tickets.assign`; otherwise only the authenticated user's tickets.
- Active-project count: all active projects for `projects.manage`; otherwise active projects where the user is a member.
- Current user's completed time this calendar month and all current unbilled billable minutes; running timers are excluded.
- Outstanding invoice count: all sent/overdue invoices for `billing.manage`; otherwise invoices whose `client_id` is the user.
- Six recent tickets: unresolved across the application for operators; the user's latest tickets for non-operators.
- Operator-only CRM counts for companies, contacts, and organizations.
- Permission-shaped stat cards and quick actions for tickets, projects, time, billing, Profile, and CRM.
- A recent-ticket empty state and optional “Submit a ticket” action.

The page has useful product information and no page-specific JavaScript. It is compact rather than a placeholder. The local/testing-only foundation-smoke link is temporary and must disappear with the smoke route.

Dashboard scoping and permission branches are behavioral contracts, even where they use direct ownership or membership rather than a generalized tenant service. This phase tests those branches but does not redesign domain scoping.

### Profile

The authenticated Profile page currently provides:

- Name and email update through Fortify; errors use `updateProfileInformation`.
- Password update only for users with a local password; it requires the current password, a 12-character mixed-case/number/symbol uncompromised password, confirmation, and no reuse among the last five password hashes. Errors use `updatePassword`.
- Two-factor enable/disable, recovery-code display, and recovery-code regeneration.
- Google and Microsoft connected-state display, OAuth connection links, and unlinking.
- A safeguard against unlinking the only social sign-in method when no local password exists.
- Password-confirmed “sign out all other sessions.” The page does not list devices or individual sessions.
- Redirect status and validation feedback.

Current Profile data is passed as an Eloquent model plus decrypted recovery codes. The React page must replace this with explicit DTOs and narrower sensitive-data handling.

### Behavior not reproduced literally

- Do not pass a `User` model or social-account models to React.
- Do not decrypt and send recovery codes on every Profile render.
- Do not reproduce the incomplete two-factor state machine.
- Do not preserve session deletion that targets the wrong storage backend.
- Do not preserve the successful-password-update call to a missing `tokens()` relation.
- Do not retain the instruction for a passwordless SSO user to “set a password”; no such Profile flow currently exists. Direct the user to connect another provider before unlinking their only sign-in method.
- Use the canonical Fortify POST route `two-factor.regenerate-recovery-codes`, rather than relying on the current Blade form's same-URI route-name coincidence.

## 4. Dashboard Design Direction

Choose **B: modest redesign using the established application design system**. Preserve all existing information and actions without inventing metrics.

Structure:

1. A restrained page header with “Dashboard,” a personalized supporting line, and no marketing-style hero.
2. A responsive metric grid containing only cards the user is authorized to see. Each card is a normal document link while its destination remains Blade.
3. A recent-tickets section with status text/badge, subject, optional requester, relative creation time, and an explicit empty state.
4. A compact quick-actions section using Lucide icons and existing destinations.
5. The operator CRM summary only when supplied by the server.

Use one column on narrow screens, two where useful, and a wider recent-ticket/main column plus utility column on desktop. Do not hide information behind hover. Status must not rely on color alone. Interactive cards need visible focus treatment and descriptive accessible names.

The existing query set is modest and all content shapes the first useful render. Use normal initial props, not deferred props. A later performance measurement may justify deferral, but adding loading skeletons now would add complexity without evidence.

## 5. Profile Information Architecture

Use one vertically structured page with a page header and five clearly titled sections:

1. **Profile information**: name and email.
2. **Password**: rendered only when `hasPassword` is true.
3. **Two-factor authentication**: disabled, pending confirmation, and confirmed states.
4. **Connected accounts**: Google and Microsoft connection status/actions.
5. **Sessions**: revoke all other sessions, subject to the session-driver decision in section 9.

Do not add tabs. Every section is independently scannable, anchorable if useful, and small enough for one page. Use unframed page layout with individual section panels; do not nest cards. Destructive actions receive clear separation and confirmation where accidental activation would be costly.

## 6. Form Strategy

Use Inertia 3 `useForm` or `<Form>` directly per operation. Do not add React Hook Form, Zod, or a generic form framework.

| Form | Wayfinder target | Data | Error bag | Success/reset behavior |
|---|---|---|---|---|
| Profile information | Fortify profile update action, `PUT` | `name`, `email` | `updateProfileInformation` | Preserve fields on error; reset defaults to returned user on success; announce status |
| Password | Fortify password update action, `PUT` | `current_password`, `password`, `password_confirmation` | `updatePassword` | Clear all password fields after success; clear confirmation/new password when appropriate; never preserve secrets across navigation |
| 2FA confirm | Fortify two-factor confirm action, `POST` | `code` | default or verified Fortify bag | Clear code after completion/error as appropriate; reload only security state |
| Session revocation | custom destroy action, `DELETE` | `password` | dedicated `destroySessions` bag to avoid collision | Clear password after success and announce status |
| Social unlink | custom unlink action, `DELETE` | none | dedicated/default provider error | Reload `connectedAccounts`; announce status/error |

Each submit button exposes processing state and is disabled while processing. Inputs use server validation as authority, stable IDs, `aria-invalid`, and `aria-describedby`. Use `preserveScroll` for section mutations. Focus the first invalid field after validation; successful operations retain section context.

Profile information and password forms submit as Inertia requests to existing Fortify endpoints and preserve redirect/status behavior. Error-bag behavior must be proven against Inertia's serialized `errors` shape before component implementation; do not flatten named bags into ambiguous shared keys.

Sensitive Fortify operations retain `password.confirm` middleware. If confirmation is stale, allow a full document transition to the existing Blade confirmation page and return to Profile after confirmation. Do not migrate that auth page in 011B.

## 7. Fortify Integration

Retain `UpdateUserProfileInformation`, `UpdateUserPassword`, Fortify's two-factor actions, password rules, throttling, events, password-reset-token deletion, and response contracts. No duplicate React-side validation replaces backend rules.

Fortify's non-JSON responses redirect back with standard status strings. Inertia follows those redirects and the existing global `FlashRegion` renders `status`. Validation exceptions return through Inertia's normal error propagation, including named bags.

Implementation must add successful-path tests for password changes before UI work. The current action calls `$user->tokens()->delete()` even though `User` has no token relationship or Sanctum trait. Since this application does not use token authentication, remove that invalid call rather than adding Sanctum. Session invalidation, if required after password change, must use the settled browser-session mechanism.

## 8. Two-Factor Authentication

### Current state and defect

Fortify is configured with both `confirm` and `confirmPassword`. Its sensitive routes require recent password confirmation. The Blade Profile page distinguishes only confirmed versus not confirmed. After `two-factor.enable`, a user has an encrypted pending secret but no confirmation timestamp; the page still shows “Enable 2FA” and never exposes QR, setup key, or confirmation-code UI. Recovery codes are decrypted on every confirmed Profile render. This is broken and in scope for correction.

The Alpine defect is separate: `auth/two-factor-challenge.blade.php` uses `x-data`, `x-show`, `x-click`, and `x-cloak` without Alpine. That affects the guest login challenge only and remains explicitly deferred to Phase C.

### React state machine

- **Disabled:** `enabled=false`, `confirmed=false`; offer Enable.
- **Pending:** `enabled=true`, `confirmed=false`; after password confirmation, fetch/display QR SVG and text setup key, accept a six-digit confirmation code, and offer Cancel/Disable.
- **Confirmed:** `enabled=true`, `confirmed=true`; show status and actions to reveal/regenerate recovery codes or disable.

The normal Profile prop contains booleans only. QR SVG, QR URL, decrypted secret key, and recovery codes are never global shared props and are not initial Profile props. Fetch them only from Fortify's password-confirmed endpoints in response to explicit user action. Keep them in component memory, clear them when the section closes or navigation occurs, and never persist them to local storage.

Render Fortify's SVG response only from this trusted server endpoint. Also show the setup key as selectable text and provide meaningful text explaining the QR purpose. Recovery codes are concealed until requested, presented in a copy-friendly list, and replaced immediately after regeneration. Do not log sensitive responses or include them in test snapshots/traces.

Enable, confirm, regenerate, and disable use generated Wayfinder actions/routes. After each mutation, use an Inertia partial reload of the non-sensitive `twoFactor` state where sufficient. Tests must prove password-confirmation middleware, invalid confirmation codes, state transitions, and absence of secret/recovery fields from shared and initial page props.

## 9. Session Management

Current UI supports only “sign out all other sessions”; there is no device list or revoke-one behavior. Do not invent either in this phase.

The implementation is currently ineffective in normal environments: `.env.example` selects Redis sessions, while `destroyOtherSessions()` deletes rows from the `sessions` database table. Tests force the `array` driver and do not cover a successful revocation. With encrypted Redis session payloads, the current application has no reliable user-to-session index for targeted deletion.

Browser sessions are standardized on Laravel's database session driver, using the existing indexed `sessions.user_id` column and retaining the current session by ID. This makes user-scoped revocation deterministic and observable without inventing a Redis session index. Redis remains available for cache and queues. Existing environments may require users to sign in once after this configuration change; live Redis sessions are intentionally not migrated.

Use a dedicated validation bag, require `current_password`, delete only other sessions, preserve the current session, reload no domain data, clear the entered password, and announce success. Users without a local password cannot complete this form; hide it or explain its unavailability rather than presenting an impossible action.

## 10. Connected Social Accounts

Expose only:

```text
connectedAccounts: [{ provider: "google" | "microsoft", connected: boolean }]
```

Never serialize provider IDs, access tokens, refresh tokens, or expiry values. Google and Microsoft are the only supported providers. Connection remains a normal document navigation to OAuth. Callback/login/link semantics remain in `SocialiteController`.

Unlink remains a server-authorized delete. Preserve the lockout safeguard: a passwordless account with one connected provider cannot unlink it. Because no set-password flow exists for an SSO-only account, correct the current error message to instruct the user to connect another provider first. Validate the provider against the supported allowlist and return a meaningful error for unsupported providers; do not silently report success. A user with a password, or another connected provider, may unlink. Confirm unlink in a small accessible dialog because it changes a sign-in method.

## 11. Shared and Page Components

Create only components justified by these pages:

- App-wide: `PageHeader`, `FormFieldError`, and a simple `SectionPanel` if repeated Profile sections and later operational pages genuinely share the markup.
- App-wide: `StatusBadge` only if Dashboard ticket statuses and 2FA state can use one semantic variant contract without domain leakage.
- Profile-specific: profile-information form, password form, two-factor section, connected-accounts section, and sessions section.
- Dashboard-specific: metric card, recent-ticket list, quick actions, and CRM summary.

Do not create a generic form schema, generic data card framework, generic mutation service, or dashboard widget system.

## 12. shadcn Additions

Already present after 011A:

- `Button`
- `Alert`
- Radix-backed dialog and dropdown behavior used directly by `AppLayout`

Minimum additions for 011B:

- `Input`
- `Label`
- `Badge` or a narrowly scoped status primitive
- `Dialog` wrapper for social unlink and destructive confirmation

Use CSS borders for section separation; a Separator package is not required. Recovery codes can use ordinary semantic markup. Do not install a bulk component set, Tabs, a form library, or a toast framework.

## 13. Inertia Data Strategy

### Dashboard initial props

- `metrics`: only authorized metric objects with key, label, numeric/display value, supporting text, href, and `visit: document`.
- `recentTickets`: whitelisted `id`, `subject`, `status`, `statusLabel`, `requesterName|null`, `createdAtHuman`, and destination.
- `quickActions`: server-authorized action DTOs or a page-local derivation from shared permissions plus Wayfinder routes; prefer server omission where destination differs by permission.
- `crmSummary|null`: counts and CRM destination only for `crm.manage`.

No full models, permission names beyond existing shared effective permissions, ticket bodies, client internals, or hidden tenant data. All are normal initial props; none are deferred.

### Profile initial props

- Shared: `auth`, `navigation`, `flash` already exist.
- Page-specific `profile`: `name`, `email`, `hasPassword`.
- Page-specific `twoFactor`: `enabled`, `confirmed` only.
- Page-specific `connectedAccounts`: provider and connected boolean only.
- Page-specific `sessionManagement`: availability derived from the settled driver and `hasPassword`, if needed.

Do not send password hashes, confirmation timestamps unless represented as booleans, two-factor secret/codes, raw session IDs/payloads, social provider IDs/tokens, relationships, or user models. Sensitive 2FA payloads are explicit password-confirmed responses only.

## 14. Wayfinder Usage

Regenerate Wayfinder before type checking. Use generated named routes where one controller serves multiple URIs and generated actions for unambiguous mutations.

- Dashboard/Profile page navigation: `dashboard`, `profile.show`.
- Dashboard links: generated ticket, project, time, billing, CRM, and Profile routes; all legacy destinations remain document visits.
- Profile information/password: generated Fortify actions or named-route exports.
- 2FA: enable, confirm, disable, QR, secret key, recovery-code index, and canonical regenerate action.
- Sessions/social: generated `ProfileController` actions, including provider parameter objects where generated signatures require them.
- OAuth connect: generated `sso.redirect(provider)` normal-document URL.

Update the navigation DTO so Dashboard/Profile destinations carry `visit: inertia` after conversion while legacy destinations stay `document`. The AppLayout logo and Profile menu entry must use Inertia `Link`/`NavigationLink`; Blade links to either page remain normal anchors. Do not hand-build URLs.

## 15. Blade/Inertia Coexistence

After 011B:

- Dashboard to Profile and Profile to Dashboard are client-side Inertia visits preserving `AppLayout`.
- Dashboard/Profile to any remaining product route is a normal document visit and restores the Blade shell and timer.
- Legacy Blade pages link normally to Dashboard/Profile and receive an Inertia document response.
- AppLayout's timer slot remains empty. The React pages do not call timer endpoints.
- Blade navigation continues using the shared navigation model; no global middleware assumes all routes are Inertia.

The permanent Dashboard/Profile browser tests replace the temporary smoke proof. Remove `/inertia-smoke`, its flash route, smoke page, smoke-specific Pest/Playwright coverage, and the local/testing Dashboard link only after equivalent bootstrap, layout, navigation, flash, theme, and mixed-renderer assertions exist on production pages.

## 16. Blade Cleanup

After parity tests pass:

- Delete `resources/views/dashboard.blade.php`.
- Delete `resources/views/profile/show.blade.php`.
- Remove their page-specific inline markup and the temporary foundation link with those files.
- Remove temporary smoke routes/page/tests as described above.

Before deletion, search for `view('dashboard')`, `view('profile.show')`, Blade `@extends` references, smoke route names, and test assertions. Do not remove `layouts/app.blade.php`, navigation/timer/footer partials, auth layouts/views, `app.js`, `timer-overlay.js`, `allocation-chart.js`, or other legacy assets.

## 17. Testing Strategy

### Pest

Dashboard:

- Guest redirect remains intact.
- Authenticated response uses `dashboard/index`.
- Operator versus user ticket counts/recent-ticket destinations and visibility.
- Project-manager versus member-scoped active counts.
- Current-user time totals exclude active timers.
- Operator/client invoice counts and destinations.
- CRM summary appears only with `crm.manage`.
- Empty collections serialize predictably; no full models or unrelated fields leak.

Profile and mutations:

- Authenticated response uses `profile/show`; guest redirect remains.
- Minimal profile, 2FA booleans, provider booleans, and session capability props.
- Profile update success, uniqueness/format validation, lowercase email, and `updateProfileInformation` bag.
- Password update success, current-password failure, complexity, confirmation, history rejection, password-field privacy, and `updatePassword` bag.
- Regression proving successful password update no longer calls a missing token relation.
- 2FA password-confirmation requirement, enable/pending/confirm/confirmed/disable states, invalid code, recovery reveal/regeneration, and sensitive-prop absence.
- Session revocation deletes another user's current account sessions only as intended, preserves the current session, rejects incorrect passwords, and follows the settled driver.
- Social provider DTO privacy, supported-provider unlink, only-login-method refusal, valid unlink, and unsupported-provider rejection.
- Existing Fortify redirects/status strings remain compatible with Inertia.
- Shared middleware still omits secrets, recovery codes, tokens, provider IDs, and session IDs.

Convert only Dashboard/Profile Blade response assertions to `assertInertia`. Do not rewrite unrelated Blade tests.

### Vitest and React Testing Library

- Dashboard metric visibility, recent-ticket present/empty states, operator CRM section, status semantics, and document-link behavior.
- Profile section visibility for password/passwordless accounts.
- Each form maps named errors to associated fields and disables while processing.
- Password fields reset after success and are not retained.
- 2FA disabled/pending/confirmed rendering, secret reveal/conceal behavior, recovery-code replacement, and confirmation controls.
- Connected-account rendering and unlink confirmation focus behavior.
- Session form availability and validation feedback.
- Any shared `PageHeader`, `FormFieldError`, badge, or dialog behavior not already covered through page tests.

Mock Inertia/Wayfinder boundaries narrowly; do not duplicate Laravel validation rules in JavaScript tests.

### Playwright

Use isolated seeded users and a small set of critical flows:

1. Login lands on the Inertia Dashboard; Dashboard to Profile and back are client-side visits.
2. Profile to a legacy Blade page performs a document transition; returning to Dashboard/Profile works and theme persists.
3. Update profile information successfully, then exercise one representative validation error.
4. Update password only with a disposable user, or cover it in Pest if rotating browser credentials makes the flow brittle.
5. Exercise the complete 2FA setup/confirm/recovery/disable flow with an isolated user and deterministic TOTP generation if practical; never record secrets in traces. If secure deterministic E2E is not practical, cover transitions in Pest/RTL and retain one browser-level password-confirmation boundary test.
6. Verify Dashboard/Profile shell and Profile sections at a mobile viewport.

Do not create exhaustive browser tests for every validation rule or provider OAuth callback.

### Static and regression gates

- `php artisan test --compact`
- `npm ci`
- `npm run check`
- targeted `npm run test:e2e`
- Pint for touched PHP
- production Vite build and explicit Wayfinder generation

## 18. Accessibility

- One page `h1`, logical section headings, and landmarks.
- Every input has a visible label, correct autocomplete, stable error association, `aria-invalid`, and an actionable server message.
- On validation failure, focus the first invalid field; status changes use the existing live-region feedback.
- Processing and disabled states remain perceivable and do not trap focus.
- Dialogs trap/restore focus, close with Escape, and identify title/description.
- Metric cards, quick actions, provider controls, and ticket statuses are keyboard operable and understandable without color.
- TOTP code input uses numeric input mode and one-time-code autocomplete; setup key is available as text when QR cannot be used.
- Recovery codes use semantic list/text markup and a clear warning to store them securely.
- Password inputs preserve password-manager-compatible names and autocomplete values.
- All sections remain usable without horizontal scrolling at mobile widths.

## 19. Security and Privacy

- Whitelist every Dashboard/Profile prop; never serialize Eloquent models directly.
- Keep passwords entirely in request state and clear them promptly.
- Keep QR SVG/URL, TOTP secret, and recovery codes out of shared and initial props; require recent password confirmation and explicit retrieval.
- Never send session IDs/payloads, provider IDs, OAuth tokens, refresh tokens, or expiry metadata to React.
- Preserve Laravel validation, authentication, CSRF, password-confirmation middleware, rate limits, Fortify actions, and server authorization.
- Escape ordinary text. Treat only Fortify's server-generated QR SVG as trusted content, and isolate its rendering.
- Social unlink validates provider allowlists and lockout protection server-side; UI confirmation is not the security boundary.
- Dashboard permission-based omission is presentation/privacy defense; policies and server query scopes remain authoritative.

## 20. Acceptance Criteria

- [x] Dashboard is served through Inertia as `dashboard/index` with existing scoped metrics, links, and empty states.
- [x] Profile is served through Inertia as `profile/show` with all valid current workflows.
- [x] Dashboard receives a modest responsive redesign without speculative metrics.
- [x] Profile uses one clear sectioned page, not unnecessary tabs.
- [x] Relevant Dashboard/Profile Blade views and temporary smoke proof are removed after replacement.
- [x] Profile information and password updates retain Fortify validation, named bags, redirects, and status feedback.
- [x] Successful password update works without Sanctum or a missing token relationship.
- [x] Two-factor setup supports disabled, pending, and confirmed states and corrects the current broken Profile flow.
- [x] The guest two-factor challenge Alpine defect remains documented for Phase C and is not pulled into this phase.
- [x] Sensitive 2FA, session, and provider data is neither globally shared nor included in initial Profile props.
- [x] Session revocation is correct for the selected session backend and preserves the current session.
- [x] Social connection/unlink behavior and lockout protection remain intact.
- [x] Dashboard/Profile navigation is client-side; boundaries to remaining Blade routes are full document visits.
- [x] Theme and accessible feedback persist across both renderer types.
- [x] AppLayout's future timer slot remains unused and legacy Blade timer/allocation behavior is untouched.
- [x] No TanStack, form framework, SSR, Sanctum, API split, CI, or PDF work is introduced.
- [x] Focused Pest, Vitest/RTL, Playwright, TypeScript, lint, formatting, Wayfinder, Pint, and production-build gates pass.

## 21. Implementation Work Packages

### Work Package 1: Defect guards and route contracts

Intent: write failing tests for successful password update, pending 2FA, session revocation, provider validation, Dashboard scopes, and sensitive-data boundaries before presentation changes.

Likely files: existing/new Feature tests, Fortify action tests, `ProfileController`, environment documentation only if session decision is settled.

Gate: defects are reproduced by focused tests; no UI migration has begun; session backend decision is explicit.

### Work Package 2: Dashboard response and DTOs

Intent: convert `DashboardController` to `Inertia::render`, preserving all query semantics while returning whitelisted page props and generated destinations.

Likely files: `DashboardController`, Dashboard feature tests, Dashboard TypeScript types.

Gate: all operator/user permission and scoping cases pass with `assertInertia`; no model leakage.

### Work Package 3: Dashboard React page

Intent: implement the modest redesign, normal-document links to Blade destinations, empty states, and responsive/accessibility behavior.

Likely files: `pages/dashboard/index.tsx`, Dashboard-specific components/tests, minimal shared page primitives.

Gate: RTL states pass; production build renders Dashboard; legacy destination transitions remain full loads.

### Work Package 4: Profile data contract and shell

Intent: replace the Eloquent payload with minimal Profile/2FA/provider/session capability DTOs and create the sectioned React page.

Likely files: `ProfileController::show`, Profile feature tests/types, `pages/profile/show.tsx`, section components.

Gate: Profile Inertia response is minimal; all sections render correctly for password and passwordless users.

### Work Package 5: Profile information and password forms

Intent: connect Inertia forms to existing Fortify endpoints, prove named bags, processing states, resets, and repair the invalid token call.

Likely files: Profile form components/tests, `UpdateUserPassword`, existing Auth password tests.

Gate: successful and failing operations pass at PHP and RTL layers; secrets clear after submission.

### Work Package 6: Two-factor state machine

Intent: implement password-confirmed enable, QR/setup, confirm, recovery, regenerate, and disable behavior without initial/global secret serialization.

Likely files: 2FA Profile component/tests, narrowly scoped page-data/controller support if required, Fortify integration tests.

Gate: all three states and security boundaries pass; guest challenge remains untouched.

### Work Package 7: Sessions and connected accounts

Intent: make revoke-other-sessions correct for the settled backend and migrate provider connect/unlink with lockout safeguards.

Likely files: `ProfileController`, environment/session documentation as approved, Profile section components/tests, provider validation.

Gate: current session survives, other sessions are revoked, provider secrets never serialize, and OAuth remains a document boundary.

### Work Package 8: Navigation and coexistence transition

Intent: mark Dashboard/Profile as Inertia destinations, update AppLayout links, preserve Blade links, and replace smoke-test coverage with permanent-page coverage.

Likely files: `NavigationBuilder`, `AppLayout`, navigation tests, routes, Playwright tests.

Gate: Dashboard/Profile client visits and both mixed-renderer directions pass with persistent theme and correct timer ownership.

### Work Package 9: Blade/smoke cleanup and final hardening

Intent: remove superseded views and temporary proof code only after permanent coverage exists; run all local release gates.

Likely files: Dashboard/Profile Blade views, temporary smoke route/page/tests, documentation implementation notes.

Gate: repository searches find no stale view/smoke references; all backend, frontend, browser, static, and production-build checks pass.

## 22. Rollback and Coexistence Safety

- Keep route names, controllers, and backend mutation contracts stable so page GETs can be reverted to Blade during development.
- Land backend tests and DTOs before deleting views.
- Do not change global middleware to assume every authenticated route is Inertia.
- Do not duplicate or replace Fortify actions merely for React.
- Navigation carries explicit renderer visit mode; legacy destinations remain documents.
- Shared shell changes require both React layout tests and representative Blade navigation tests.
- Timer and allocation files/endpoints remain untouched; Blade owns active timer UI until its later phase.
- Sensitive 2FA retrieval remains independently protected even if Profile rendering is reverted.
- Production continues to serve PHP plus static Vite assets; no Node application process is added.

## 23. Out of Scope

- Guest login, logout presentation, forgot/reset password, password-confirmation migration, and guest two-factor challenge migration
- Invitation registration and invalid/expired invitation views
- Guest two-factor Alpine repair except documenting it for Phase C
- Timer, time tracking, allocation, or reports
- Projects, tasks, boards, or milestones
- Tickets and operator ticket workflows
- Billing, Stripe, invoices, or payments
- CRM, organizations, CMS, users, or roles
- PDF/document generation
- New dashboard metrics or speculative product widgets
- Per-device session listing or revoke-one functionality
- SSO/provider architecture redesign
- TanStack, Redux, Zustand, React Hook Form, Zod, SSR, Sanctum, or an API split
- CI configuration or deployment automation
- Broad Fortify/backend refactoring

## 24. Risks and Genuine Open Questions

### Defined risks

- **Fortify error bags:** prove actual Inertia serialization before composing forms; keep each form isolated.
- **Password-confirmation boundary:** test redirects from an Inertia security action to the retained Blade confirmation page and back.
- **2FA leakage:** initial/shared prop tests deny secrets and recovery codes; browser traces must not retain them.
- **Mixed navigation:** explicit visit mode and browser coverage prevent Inertia from requesting Blade destinations as Inertia pages.
- **Dashboard query drift:** tests lock operator/user scopes before DTO refactoring.
- **Smoke removal timing:** remove temporary proof only after Dashboard/Profile cover every coexistence contract.

### Settled session decision

The maintainer selected Laravel's database session driver as the canonical browser-session store. No Redis session index or live-session migration is part of this epic. Redis remains available for non-session infrastructure.

No product decision blocks implementation. The current Dashboard is sufficiently useful to migrate without inventing new requirements, and the Profile information architecture follows existing functionality.
