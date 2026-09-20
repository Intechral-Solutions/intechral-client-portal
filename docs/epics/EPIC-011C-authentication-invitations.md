# EPIC-011C: Authentication and Invitation Migration

**Status:** Implemented
**Parent epic:** [EPIC-011: React Frontend Migration](./EPIC-011-react-frontend-migration.md)
**Prerequisites:** [EPIC-011A: React Foundation and Coexistence Contract](./EPIC-011A-react-foundation-coexistence.md), [EPIC-011B: Dashboard and Profile Migration](./EPIC-011B-dashboard-profile.md)
**Decision record:** [ADR-007](../architecture/adr/ADR-007-inertia-react-frontend.md)

---

## 1. Goal

Migrate the remaining guest and authentication presentation surface from Blade to Inertia 3, React 19, and TypeScript. Laravel, Fortify, database-backed browser sessions, Socialite, invitation services, server validation, throttling, and authorization remain authoritative.

This phase removes the split between the React account-management experience delivered in EPIC-011B and the Blade login, recovery, confirmation, two-factor challenge, and invitation pages. Behavioral parity is required for valid behavior; known defects identified below must be corrected rather than copied. Pixel parity is not required.

## 2. Exact Scope and Route Contract

### Page responses migrated to Inertia

| Method | URI | Route name | Current owner/response | Current Blade view | Future Inertia page |
|---|---|---|---|---|---|
| `GET` | `/login` | `login` | Fortify `AuthenticatedSessionController::create` through `Fortify::loginView` | `auth.login` | `auth/login` |
| `GET` | `/forgot-password` | `password.request` | Fortify `PasswordResetLinkController::create` through `Fortify::requestPasswordResetLinkView` | `auth.forgot-password` | `auth/forgot-password` |
| `GET` | `/reset-password/{token}` | `password.reset` | Fortify `NewPasswordController::create` through `Fortify::resetPasswordView` | `auth.reset-password` | `auth/reset-password` |
| `GET` | `/user/confirm-password` | `password.confirm` | Fortify `ConfirmablePasswordController::show` through `Fortify::confirmPasswordView` | `auth.confirm-password` | `auth/confirm-password` |
| `GET` | `/two-factor-challenge` | `two-factor.login` | Fortify `TwoFactorAuthenticatedSessionController::create` through `Fortify::twoFactorChallengeView` | `auth.two-factor-challenge` | `auth/two-factor-challenge` |
| `GET` | `/invitation/{token}` | `invitation.show` | `InvitationController::show`, valid branch | `auth.register` | `auth/invitation-register` |
| `GET` | `/invitation/{token}` | `invitation.show` | `InvitationController::show`, unusable branch | `auth.invitation-invalid` | `auth/invitation-invalid` |

The Socialite callback can currently return `auth.invitation-invalid` directly. Prefer redirecting an invalid invitation callback to `invitation.show` so one controller path owns the safe unusable-invitation page. The OAuth callback itself remains a server endpoint, not an Inertia page.

No standalone Fortify registration or email-verification page exists. Fortify registration and email-verification features are disabled; registration remains invitation-only.

### Existing mutation endpoints retained

| Method | URI | Route name | Owner | Purpose |
|---|---|---|---|---|
| `POST` | `/login` | `login.store` | Fortify `AuthenticatedSessionController::store` | Authenticate local credentials and enter 2FA when required |
| `POST` | `/forgot-password` | `password.email` | Fortify `PasswordResetLinkController::store` | Request a reset notification |
| `POST` | `/reset-password` | `password.update` | Fortify `NewPasswordController::store` | Validate a reset token and replace a password |
| `POST` | `/user/confirm-password` | `password.confirm.store` | Fortify `ConfirmablePasswordController::store` | Record recent password confirmation |
| `POST` | `/two-factor-challenge` | `two-factor.login.store` | Fortify `TwoFactorAuthenticatedSessionController::store` | Complete login with TOTP or a recovery code |
| `POST` | `/invitation/{token}` | `invitation.register` | `InvitationController::register` | Create a local-password user from an invitation |

These routes remain Laravel/Fortify handlers. React supplies forms and feedback; it does not reproduce authentication, token, invitation, or authorization decisions.

### Supporting authenticated handoff

`GET /profile/confirm-password` (`profile.password.confirm`) is not a page. EPIC-011B added it to place `/profile` in `url.intended` and redirect to `password.confirm`. This narrow bridge must be generalized or retired as described in section 8 without weakening the `password.confirm` middleware.

## 3. Out-of-Scope Server Routes

The following stay ordinary server routes and must not become React pages:

| Method | Route | Reason |
|---|---|---|
| `POST /logout` | `logout` | Fortify invalidates the session and CSRF token, then redirects |
| `GET /auth/{provider}/redirect` | `sso.redirect` | Derives and stores explicit server-owned login/link/invitation context, then starts a full-document OAuth redirect |
| `GET /auth/{provider}/callback` | `sso.callback` | Validates OAuth state, consumes the application context once, performs only that operation, and redirects |
| `POST /forgot-password` | `password.email` | Password broker action only |
| `POST /reset-password` | `password.update` | Password broker action only |
| `POST /login` | `login.store` | Fortify authentication pipeline only |
| `POST /user/confirm-password` | `password.confirm.store` | Fortify confirmation action only |
| `POST /two-factor-challenge` | `two-factor.login.store` | Fortify challenge action only |
| `POST /invitation/{token}` | `invitation.register` | Invitation acceptance action only |
| `POST /invitations` | `invitations.store` | Operator invitation creation UI belongs to a later admin phase |
| `GET /user/confirmed-password-status` | `password.confirmation` | Focused authenticated JSON status endpoint used by Profile |

Profile-side 2FA setup, recovery codes, session management, social unlinking, and account details were completed in EPIC-011B and are not guest-auth pages in this phase.

## 4. AuthLayout Productionization

EPIC-011A created one React `AuthLayout` with a centered `max-w-md` form container and global `FlashRegion`. It is the correct foundation, but it is not yet production-complete.

Complete it as the only React auth shell:

- Use shared `app.name` for the brand/header and one page `h1`; the brand link returns to `/`, which remains the server-owned authenticated/guest router.
- Keep a constrained form width around the current 28rem maximum, with mobile-safe padding and no horizontal scrolling.
- Reuse `useAppearance` for a labeled icon theme toggle. This aligns auth pages with the local-storage preference and pre-paint script in `app.blade.php`.
- Keep `FlashRegion` between the heading and form container, with status/error live-region semantics and no duplicate page-local copy of global feedback.
- Keep one semantic form container. Invitation-invalid may use the same shell and container even though it has no form.
- Let pages supply contextual links such as forgot password, back to login, or provider continuation; do not fork layouts for individual flows.
- Ensure focus visibility, heading order, readable contrast, touch targets, and responsive spacing in both themes.

The old Blade auth layout uses a cookie-backed theme toggle while the Inertia root uses local storage plus system preference. Removing the Blade auth shell at the end of this phase also removes that duplicate theme mechanism.

## 5. Form Strategy

Use Inertia 3 `useForm` or `<Form>` directly. Do not add React Hook Form, Zod, or another form framework. Server validation remains authoritative.

| Form | Action | Fields | Error/processing behavior | Reset and redirect behavior |
|---|---|---|---|---|
| Login | `login.store`, `POST` | `email`, `password`, `remember` | Associate Fortify credential errors with email, show 429 feedback, disable submit while processing, focus first error | Always clear password on failure/finish; preserve email and remember choice; Fortify redirects to `url.intended` or `/dashboard` |
| Forgot password | `password.email`, `POST` | `email` | Show a generic, enumeration-safe status; surface format and rate-limit feedback without revealing account state | Preserve email on validation failure; optionally clear after accepted request; remain on page with announced status |
| Reset password | `password.update`, `POST` | `token`, `email`, `password`, `password_confirmation` | Map token/account failure to a page-level or email-associated error; map password rules/history to password; disable while processing | Clear both password fields on any completed attempt; success redirects to login with Fortify status |
| Confirm password | `password.confirm.store`, `POST` | `password` | Associate incorrect-password error; disable while processing | Always clear password; success follows Laravel's intended destination |
| 2FA TOTP | `two-factor.login.store`, `POST` | `code` | Associate Fortify TOTP error; disable while processing | Clear code after failure; success follows intended login redirect |
| 2FA recovery | `two-factor.login.store`, `POST` | `recovery_code` | Associate Fortify recovery error; disable while processing | Clear recovery code after failure; success follows intended login redirect |
| Invitation local registration | `invitation.register(token)`, `POST` | `name`, `password`, `password_confirmation` | Display field errors and a safe invitation-level conflict; disable all alternatives while processing | Preserve name only; clear both password fields; success logs in and redirects to Dashboard |

Use stable IDs, visible labels, `aria-invalid`, `aria-describedby`, and first-invalid-field focus. Preserve standard field names and autocomplete values so browsers and password managers recognize the flows.

## 6. Login Design

The supported local username is email: `fortify.username` and `fortify.email` are both `email`, and Fortify lowercases it before authentication. Preserve:

- Email with `autocomplete="username"`, autofocus, and email keyboard behavior.
- Password with `autocomplete="current-password"`.
- A native accessible remember-me checkbox posting `remember`.
- A link to `password.request`.
- Google and Microsoft links to `sso.redirect(provider)` as normal document navigations that initiate explicit `login` context for a guest.
- Fortify's generic invalid-credential message and five-attempts-per-minute limiter keyed by normalized email plus IP.
- `redirect()->intended('/dashboard')`, including the 2FA branch.

Provider buttons are sign-in options, not Inertia visits. Do not add registration, passkeys, magic links, usernames, or unsupported providers.

## 7. Password Reset Flows

### Forgot password

Submit email to `password.email`. The broker lowercases email, uses a 60-minute token lifetime, and throttles repeated token generation for 60 seconds. The current default Fortify failure response reveals unknown users and throttled states through email errors. Replace that visible contract with a generic accepted status for syntactically valid requests while retaining server-side throttling and observability.

For an SSO-only account with `password = null`, return that same generic accepted response and send no reset notification. Do not send provider guidance in this phase. Unknown, local-password, and SSO-only addresses must be indistinguishable in browser-visible behavior.

### Reset password

The Fortify reset-view closure should render `auth/reset-password` with explicit whitelisted `token` and `email` props. Do not have React parse `window.location`, route internals, or arbitrary query data. The token is necessarily present in the reset URL and form submission but must never enter shared props, logs, analytics, local storage, or unrelated component state.

Submit token, email, new password, and confirmation to `password.update`. Preserve the 12-character mixed-case, number, symbol, uncompromised, confirmed, and last-five-password rules in `ResetUserPassword`. On success Fortify rotates the remember token, dispatches `PasswordReset`, removes the reset token, and redirects to login with status. The application action also deletes database sessions for the user.

For a local-password user, history should record the replaced hash before saving the new one. `User::passwordHistories()` already applies `latest()`, so history pruning through that relationship is ordered; do not invent a missing-order defect.

For an SSO-only user, the current action attempts to insert a null hash into the non-null `password_histories.password` column. This is the actual defect. Reject password reset for passwordless accounts before the reset action can establish a password, including when an already-issued or stale token is presented. Merely skipping the history insert is forbidden because it would create an implicit local-password-enrollment path.

## 8. Password Confirmation and Profile Return

Fortify stores `auth.password_confirmed_at` in the server session after a valid password. Laravel's `password.confirm` middleware compares that timestamp to `AUTH_PASSWORD_TIMEOUT`, currently 10,800 seconds (three hours). The successful response uses `redirect()->intended(...)`.

The React page needs only normal shared props. It must not receive or manage an arbitrary return URL. Laravel's session-owned `url.intended` is the redirect authority, which avoids a client-side open-redirect input.

Generalize the 011B handoff as follows:

- Let protected requests redirected by `password.confirm` continue to populate Laravel's intended URL automatically.
- For proactive Profile entry, keep a small same-origin server handoff only if the UI must confirm before a request exists. Rename/generalize it only when another caller exists; do not build a query-string `return_to` mechanism.
- If the dedicated `profile.password.confirm` route has no remaining caller after Profile actions rely on middleware redirects, remove it and its generated helper.
- Test that an external URL can never be injected as the post-confirm destination.
- Preserve the timestamp and timeout behavior exactly.

Passwordless SSO users cannot satisfy Fortify password confirmation. Continue the EPIC-011B policy: local-password-dependent Profile workflows remain unavailable, Fortify confirmation is not weakened, and provider reauthentication is not introduced. The existing Profile connection workflow remains available because successful OAuth authentication is itself the explicit proof for linking that provider identity.

## 9. Guest Two-Factor Challenge

The current Blade page is functionally broken: it uses `x-data`, `x-show`, `x-click`, and `x-cloak`, but Alpine is not installed or initialized. The TOTP pane renders; the recovery pane remains hidden and the switch buttons do nothing.

Replace it with one `auth/two-factor-challenge` React page and local component state:

- Default to authenticator mode with `code`, `inputMode="numeric"`, `autocomplete="one-time-code"`, and a six-character visual constraint.
- Switch explicitly to recovery mode with `recovery_code`, `autocomplete="one-time-code"`, spellcheck disabled, and no numeric constraint.
- Use one active field per submission. Clear the inactive field and its client-displayed server error when switching.
- Move focus to the newly active field and expose the mode switch as a keyboard-operable button with clear text.
- Keep separate field errors because Fortify returns `code` or `recovery_code` according to the submitted mode.
- Disable submission and switching while a request is processing; announce failures.
- Do not request QR codes, setup keys, or Profile recovery-code lists. This page only proves a login challenge already established in session.

Fortify stores `login.id` and `login.remember` in the session after the password step. The GET redirects to login if there is no challenged user. POST is limited to five attempts per minute keyed by `login.id`; valid recovery codes are consumed and replaced. Success logs in with the saved remember choice, regenerates the session, and follows the intended login destination.

## 10. Invitation Registration Contract

For a valid token, return an explicit DTO rather than an `Invitation` model:

```text
invitation: {
    email: string,
    expiresAt: string
}
```

The token remains a page-level string only because it is required to generate the local-registration and provider-continuation URLs. Do not include invitation ID, status internals, inviter ID, user relationships, or raw models.

Display the invited email as immutable text or a disabled/read-only email control, plus a concise explanation that the invitation established it. Expiration may be shown in user-friendly form if it can be rendered consistently from `expiresAt`. Do not display organization, company, inviter, or selectable role because the current schema and acceptance path carry none of that context.

Current local registration accepts only name, password, and password confirmation. The email always comes from the revalidated invitation. It creates a password hash, initial password-history row, `invited_by`, `invitation_id`, and the global `user` role; it creates no organization/company membership. SSO registration creates the same identity and role with a null password and linked provider, without a password-history row.

The page should continue to offer all three existing choices: local password, Google, or Microsoft. This is current product behavior, not a new choice. Provider links are normal anchors and include the invitation token through the generated `sso.redirect` query API. The redirect handler validates the guest/invitation initiation conditions and stores an explicit `invitation` context; callback query parameters never choose OAuth intent.

Invitation `GET` and `POST` are guest-only because this workflow always creates a new account. Apply `guest` middleware so an authenticated visitor follows Laravel's safe guest redirect and can never submit an invitation that replaces the current authenticated identity. Existing-account invitations or membership attachment require a separate future workflow.

Evolve the existing `InvitationService` into the cohesive acceptance boundary instead of creating a parallel domain abstraction. Both local-password and SSO registration should delegate durable work to it. Inside one database transaction:

1. Find the invitation by token with `lockForUpdate` or an equivalent row lock.
2. Re-check pending status and expiration while locked.
3. Normalize and verify all email/invitation requirements.
4. Create the new user with `invitation_id` and `invited_by` provenance.
5. Create the initial password-history row for local registration only.
6. Assign the global `user` role.
7. Create the social account for SSO registration only.
8. Mark the invitation accepted with `accepted_at`.

Authenticate the new user only after the transaction commits. A race loser, duplicate email/provider identity, expired token, or other unusable state must roll back user, role, social-account, and password-history writes and return the safe invitation response rather than a database exception page.

Normalize invitation/account email inputs consistently with trim plus lowercase before lookup, pending-invitation invalidation, comparison, and creation. The current `InvitationService::invite()` invalidates using the raw input before storing a normalized value, allowing case/whitespace variants to escape invalidation; correct that ordering without changing otherwise valid email semantics.

Today `InvitationService` has only three narrow operations: `invite()` creates/notifies, `findValid()` performs an unlocked pending/expiry lookup, and `accept()` marks the invitation accepted before updating the user's `invitation_id`. `InvitationController` and `SocialiteController` separately create users, roles, password history, and social accounts with no encompassing transaction. Preserve `findValid()` as a presentation precheck if useful, but never treat it as the acceptance authorization check; the transaction must repeat the lookup and validity check while holding the lock.

Correct the provenance inverse while doing this work. `User::invitation()` belongs to the invitation through `users.invitation_id`, so `Invitation::acceptedUser()` must be `hasOne(User::class, 'invitation_id')`, not the current backwards `belongsTo`. Keep `invitedBy()` unchanged.

## 11. Invalid, Expired, and Used Invitations

Use one `auth/invitation-invalid` page for a missing token, expired invitation, accepted/used invitation, race loser, and otherwise unusable invitation. The page should say that the invitation cannot be used and direct the visitor to contact an administrator or return to login.

Do not distinguish the reason in browser-visible props or copy. Do not expose an invited email, account existence, internal status, or timestamps on this page. Operational logs may retain a structured server-side reason without the token value.

The current `findValid` method already collapses missing and non-pending invitations. Preserve that privacy property while making acceptance atomic.

## 12. Explicit OAuth Intent and Invitation Continuation

The same `sso.redirect` and `sso.callback` route pair currently serves three operations and infers behavior from incidental session/authentication state. Replace that callback inference with one explicit application OAuth context stored only in Laravel's encrypted session.

The redirect handler clears any earlier application context, validates the provider and initiation conditions, then stores a context equivalent to:

```text
oauthContext: {
    intent: "login" | "link" | "invitation",
    provider: "google" | "microsoft",
    initiatingUserId: number | null,
    invitationToken: string | null,
    startedAt: string
}
```

Do not send this object to React or the provider. The callback pulls it once before provider retrieval, verifies that it matches the callback provider, and performs exactly one branch. No intent is accepted from callback query parameters. Missing, stale, malformed, or mismatched context fails safely rather than guessing.

This application context complements Socialite's own stateful OAuth `state` validation; it does not replace it. Keep Socialite state validation enabled and never call `stateless()`. Pulling the application context before provider retrieval guarantees cleanup when state validation, provider retrieval, or later callback handling fails. Starting a new redirect replaces stale context, and replaying a callback finds no usable context.

### Login intent

Initiation requires a guest, no invitation, and a normal Google/Microsoft sign-in action. On callback:

- Resolve only by `(provider, provider_id)`.
- If linked, update provider metadata as appropriate, authenticate that link's user, and follow the intended login redirect.
- If unlinked, fail safely and return to login.
- Never look up a portal user by provider-returned email.
- Never create a social link or attach the identity to an email-matching account.

An unlinked user can authenticate through another supported method and explicitly connect the provider from Profile.

### Link intent

Initiation requires an authenticated user following Profile's Connect action. Store that user's ID in the context. On callback:

- Require the current authenticated user to still match `initiatingUserId`.
- If the provider identity belongs to that same user, treat the callback idempotently and update metadata as appropriate.
- If it belongs to another user, reject without changing either account.
- If it is unowned, create the link only for the initiating user and return to Profile.
- Never log in or switch to another portal account, transfer provider ownership, or infer the target from provider email.

The provider email does not need to equal the portal account email for this explicit authenticated link. Completing provider authentication is the user's proof that they control the provider identity.

### Invitation intent

Initiation requires a guest following a provider link from a valid invitation page. Store the token only in server-side context. On callback:

1. Consume the context exactly once and do not fall through to login or link behavior.
2. Revalidate and lock the invitation through `InvitationService`.
3. Require the normalized provider email to match the normalized invitation email case-insensitively.
4. Reject an already-owned provider identity or existing portal email as a safe unusable/conflicting invitation.
5. Atomically create the passwordless user, provenance, `user` role, social account, and accepted invitation.
6. Authenticate only that newly created user after commit and redirect to Dashboard.

Provider cancellation/failure returns to the appropriate safe React surface. Invitation mismatch returns to the invitation page with safe feedback; an unusable token routes through the unified invalid page. No organization/company membership is created.

### Provider ownership and stored tokens

Enforce both cardinality invariants in the database:

1. `(provider, provider_id)` belongs to at most one portal user; the existing unique constraint already provides this.
2. A portal user has at most one linked identity per provider; add a unique constraint on `(user_id, provider)` instead of the current ordinary index.

Before applying the second constraint in any environment, query for duplicates. If any exist, stop and report them; do not silently delete rows or choose a survivor. The planning database had no duplicates when this amendment was prepared, but every target environment still requires its own preflight. Linking must use ownership-aware create/update logic and must never use `updateOrCreate` in a way that can reassign an existing identity's `user_id`.

The repository currently writes/refreshes provider access tokens, refresh tokens, and expiry metadata in `SocialiteController`, but no application feature reads those values after linking. Admin and Profile surfaces use provider names/connection state only. Record the unused token storage as security/maintenance debt; do not expand EPIC-011C into a token-at-rest migration unless implementation discovers a real consumer or the existing storage blocks secure authentication.

## 13. SSO-Only Users

SSO invitation registration must continue creating `password = null`; no generated, placeholder, or hidden password is permitted. Login accurately offers both local credentials and the two supported providers. Local invitation registration continues creating a password.

Current password-reset behavior for an SSO-only account is broken:

- The broker finds the user and sends a normal reset link because password nullability is not considered.
- A valid reset reaches `ResetUserPassword` and validates the new password.
- `storeHistory()` tries to insert the existing null password into the non-null password-history column before saving the new password.
- The reset therefore fails at persistence time instead of completing cleanly.
- If that insert were merely skipped, reset would become an undocumented local-password enrollment path, contrary to the settled product scope.

The settled behavior is to return the same generic forgot-password status for every syntactically valid address while sending no reset email and no provider-specific guidance email to an SSO-only account. A passwordless account must also be rejected if it presents an already-issued token. Local-password enrollment remains a separate future product decision.

## 14. Shared Props and Privacy

`HandleInertiaRequests` currently shares app name; authenticated user name/email; effective permissions; navigation; and success/error/status/warning flash values. For guests it resolves to `auth.user = null`, empty permissions, and empty navigation. Keep the stable shared shape unless measurements justify a split, and add tests proving guest auth pages receive no authenticated navigation or account data.

Page-specific contracts must be minimal:

- Reset page: `token` and prefilled `email` only.
- Invitation registration: explicit email and expiry DTO plus the route token needed by that page.
- Other auth pages: no page props unless a concrete rendering need appears.
- Invalid invitation: no token, email, status reason, or account data.

Never serialize password hashes, password-history hashes, OAuth access/refresh tokens, provider IDs, raw Fortify challenge state, TOTP secrets/recovery codes, database session IDs/payloads, invitation models, inviter models, or unrelated user data.

## 15. Wayfinder Usage

Regenerate Wayfinder before TypeScript checks. Generated named-route helpers already cover the needed contracts:

- Root `login()` and `logout()` for the login page and authenticated logout.
- `login.store()` for local login.
- `password.request()`, `password.email()`, `password.reset(token)`, `password.update()`, and `password.confirm()`.
- `password/confirm.store()` for confirmation POST because GET and POST have distinct route names.
- `two-factor.login()` and `two-factor/login.store()` for challenge GET and POST.
- `invitation.show(token)` and `invitation.register(token)`.
- `sso.redirect(provider)` for guest login and authenticated Profile linking; the server derives and stores explicit intent from validated initiation state.
- `sso.redirect(provider, { query: { invitation: token } })` for invitation initiation only; the token is validated and moved into server-owned context before leaving the application.

Fortify action helpers are also generated, but prefer named-route modules where they express the public route contract clearly. Use controller action helpers only when a named helper is absent or ambiguous. Keep OAuth links as normal anchors and do not hand-build query strings.

## 16. Components

Reuse the implemented `AuthLayout`, `Button`, `Input`, `Label`, `Alert`, `FormFieldError`, and `FlashRegion`. `Badge` and `ConfirmationDialog` are not needed by these guest flows.

No new shadcn package is required. Use a native checkbox for remember-me and a CSS border with text for provider separators. Create only narrow page/shared components if duplication proves real, such as a provider-link row or password-requirements text. Do not create a schema-driven form system, generic auth state machine, or provider abstraction beyond the two supported providers.

## 17. Theme and Renderer Coexistence

After this phase, Dashboard, Profile, and all authentication/invitation product pages are Inertia; most domain modules remain Blade.

- The Inertia root pre-paint script and `useAppearance` remain the single React theme contract.
- Guest auth works on a cold first visit without `AppLayout` or authenticated navigation.
- Successful local/2FA/SSO/invitation login enters the Inertia Dashboard cleanly.
- OAuth leaves the application through a normal document navigation and returns through Laravel.
- Logout from `AppLayout` posts to Fortify and returns through `/` to the React login page.
- Expired authenticated sessions redirect to React login; intended destinations remain server-owned.
- Any auth-to-legacy link uses a normal document navigation. Later Blade product routes are unaffected.

## 18. Session Behavior

Database sessions are canonical and `.env.example` enables session encryption.

- Fortify local login runs `PrepareAuthenticatedSession`, which regenerates the session and clears the login limiter.
- `SessionGuard::login` regenerates the session during 2FA, Socialite, and invitation login; the 2FA controller performs an additional regeneration.
- Fortify logout logs out, invalidates the session, and regenerates the CSRF token.
- Remember-me is carried from login into `login.remember` during a 2FA challenge; SSO currently always uses remember mode.
- Password confirmation stores only its server timestamp and uses the configured three-hour timeout.
- The 2FA intermediate user ID and remember choice stay server-side.
- Socialite OAuth state and the explicit application OAuth context stay in the Laravel session; React does not mirror them.
- Starting a redirect clears stale application context. Callback handling pulls context once before provider retrieval, so success, provider exceptions, validation failures, and replay all leave no reusable context.
- `login` context stores no user or invitation target, `link` context binds the initiating authenticated user ID, and `invitation` context stores only the invitation token needed for server revalidation.

No session-fixation defect was found in the login calls themselves. Tests should lock these guarantees before controller hardening.

## 19. Security Analysis and Remediation

### Preserve

- Login limiter: five attempts per minute by normalized email plus IP.
- 2FA limiter: five attempts per minute by challenged user session ID.
- Stateful Socialite OAuth state/CSRF validation; do not call `stateless()`.
- Reset-token expiry of 60 minutes and creation throttle of 60 seconds.
- Server-side password rules, history, invitation validation, provider allowlist, authorization, CSRF, intended redirects, and session regeneration.
- Unified invalid invitation messaging and one-time recovery-code consumption.

### Correct before release

- Make forgot-password responses enumeration-safe; current Fortify defaults distinguish unknown users.
- Return generic success and suppress reset notification for SSO-only accounts; reject any existing token before it can enroll a password.
- Make invitation acceptance transactional with a lock/recheck to prevent concurrent reuse and partial users.
- Normalize email before invalidating earlier invitations; current ordering can leave case/whitespace variants pending.
- Handle existing-user and provider uniqueness conflicts without a 500 or account disclosure.
- Replace inferred callback behavior with explicit one-time `login`, `link`, or `invitation` context and clear it on every callback outcome.
- Remove email-based SSO user lookup and automatic linking from normal login.
- Ensure link intent cannot switch authenticated users or transfer a provider identity, and invitation intent cannot fall through to another branch.
- Add `(user_id, provider)` uniqueness after a mandatory duplicate preflight; retain existing `(provider, provider_id)` uniqueness.
- Correct `Invitation::acceptedUser()` to the inverse `hasOne(User::class, 'invitation_id')` relationship and cover it.
- Apply `guest` middleware to invitation registration GET/POST so an authenticated session cannot be silently replaced.
- Avoid logging reset tokens, invitation tokens, OAuth credentials, challenge session data, or form secrets.
- Record unused stored provider tokens as technical debt without expanding this epic into token-at-rest redesign.

All redirect destinations remain generated server routes or Laravel `url.intended`. Do not accept an arbitrary client return URL.

## 20. Accessibility

- Exactly one `h1` per page and logical explanatory text.
- Visible labels for every control; placeholders never replace labels.
- Correct `username`, `current-password`, `new-password`, `name`, and `one-time-code` autocomplete values.
- Password-manager-friendly names and separate password/confirmation controls.
- Keyboard access and visible focus for links, provider actions, remember checkbox, submit controls, and 2FA mode switch.
- Focus the first invalid field after a failed Inertia submission; focus the active challenge input after mode changes.
- Associate errors/help with fields and announce global status/errors through live regions.
- Communicate processing states without changing control dimensions or trapping focus.
- Make invalid invitation and provider failure messaging understandable without color or icon alone.
- Keep the shell readable at mobile widths and in both themes with no horizontal scroll.

QR presentation is not part of the guest challenge. It remains an authenticated Profile setup concern.

## 21. Blade Cleanup

Delete these files only after their Inertia replacements and coverage are complete:

- `resources/views/auth/login.blade.php`
- `resources/views/auth/forgot-password.blade.php`
- `resources/views/auth/reset-password.blade.php`
- `resources/views/auth/confirm-password.blade.php`
- `resources/views/auth/two-factor-challenge.blade.php`
- `resources/views/auth/register.blade.php`
- `resources/views/auth/invitation-invalid.blade.php`
- `resources/views/components/layouts/auth.blade.php`

Before deletion, search application, tests, and documentation for each view name and `x-layouts.auth`. Replace the Fortify view closures and both invitation response branches first. Retain `resources/views/app.blade.php`, the authenticated/product Blade shell, framework/error pages, mail views, and all later-phase product views.

Removing the Blade auth layout also removes its `resources/js/app.js` dependency and cookie-only theme script from the auth surface; do not delete a legacy entrypoint if remaining Blade pages still reference it.

## 22. Testing Strategy

### Pest feature/integration matrix

| Area | Required coverage |
|---|---|
| Page contracts | Every migrated GET uses the expected Inertia component; guest/auth middleware behavior; reset and invitation props are whitelisted |
| Login | Success, generic invalid credentials, lowercase email, five-attempt throttle, remember cookie/session behavior, intended redirect, authenticated-user redirect, session ID regeneration |
| Logout | Session invalidation, CSRF regeneration where practical, redirect through root/login |
| Forgot password | Valid local account, invalid email syntax, unknown account with identical browser-visible response, broker throttle without enumeration, local notification dispatch, SSO-only generic response with no notification |
| Reset password | Explicit page token/email props, valid reset, invalid/expired token, confirmation/complexity/uncompromised rules as appropriate, ordered last-five history, session revocation, remember-token rotation, passwordless account rejected even with a previously issued token |
| Password confirmation | Valid confirmation timestamp, invalid password, three-hour recent-confirmation behavior, intended return, Profile handoff, no external return injection, passwordless behavior |
| 2FA challenge | GET requires challenged user; TOTP success/failure; recovery success/failure and one-time consumption; limiter; remember propagation; intended redirect; session regeneration; no Profile setup secrets |
| OAuth context | Explicit login/link/invitation intents; missing/invalid context; stale-context replacement; provider binding; cleanup on provider exception; one-time consumption and replay denial; no callback-query intent |
| Normal SSO | Linked provider logs in its owner; unlinked identity fails; an email matching an unrelated portal user neither authenticates nor auto-links; provider allowlist; intended redirect |
| Profile linking | Unowned identity links only to initiating user; same-user link is idempotent; another user's identity is rejected; mismatched initiating/current user is rejected; callback cannot switch users; email need not match |
| Invitation page | Valid DTO, missing/expired/accepted token unified invalid page, no sensitive props, `guest` middleware on GET/POST, authenticated visitor cannot accept as a new account |
| Local invitation | Validation, normalized invitation email only, password history, provenance, `user` role, no fabricated membership, lock/transaction, local-vs-SSO race accepts at most one, duplicate email rollback, post-commit login |
| SSO invitation | Google/Microsoft intent; matching/mismatching email; invalidated token; owned-provider conflict; cannot fall into linked login; cannot authenticate unrelated identity; atomic user/provider/role/invitation writes; null password; context cleanup |
| Database/relationships | Existing `(provider, provider_id)` uniqueness; duplicate preflight then `(user_id, provider)` uniqueness; migration stops on duplicates; corrected `acceptedUser` inverse returns the user by `invitation_id` |
| Privacy | Guest shared props are empty/minimal; no model, hashes, OAuth tokens, provider IDs, raw challenge state, session IDs, or invitation internals |

Convert existing Blade assertions in `LoginTest`, `PasswordTest`, and `InvitationTest` to `assertInertia` only where page responses changed. Add focused tests rather than rewriting unrelated authentication business tests.

### Vitest and React Testing Library

- `AuthLayout` brand, title hierarchy, flash/status region, theme toggle, and responsive semantic structure.
- Login field associations, remember checkbox, processing state, password clearing, errors, and provider links.
- Forgot-password accepted/status and validation states.
- Reset token submission boundary, field errors, and password clearing.
- Password-confirmation error and processing behavior.
- TOTP/recovery switch, focus movement, inactive-field clearing, correct submitted key, and both error states.
- Invitation DTO rendering, immutable email, local form states, provider URLs, and unified invalid state.

Mock Inertia and generated routes narrowly. Do not duplicate password, token, throttle, OAuth, or invitation business logic in JavaScript tests.

### Playwright

1. Local login reaches the Inertia Dashboard; logout returns to React login.
2. Invalid login feedback and one representative processing state remain accessible.
3. Forgot-password submission with the array/mail test setup if deterministic.
4. Password confirmation returns to a Profile-sensitive workflow using server intended semantics.
5. TOTP login with an isolated user and deterministic code generation; add recovery-code login if stable and prevent secrets from entering traces.
6. Valid local invitation registration reaches Dashboard with a disposable invitation/user.
7. Auth pages work in light/dark themes and at a mobile viewport.
8. One SSO flow only when local provider/test infrastructure is reliable; otherwise cover redirect/callback contracts in Pest and never automate third-party Google/Microsoft pages.

### Final gates

- Focused auth/invitation Pest suites, then `php artisan test --compact`.
- `npm run wayfinder:generate`, `npm run typecheck`, lint, formatting, Vitest, and production build through `npm run check`.
- Targeted Playwright auth/invitation/coexistence suite.
- Pint for touched PHP.
- Reference search proving no deleted Blade view remains in use.

### Acceptance criteria

- [x] Login, forgot password, reset password, password confirmation, guest 2FA challenge, invitation registration, and unusable invitation responses are Inertia pages using one `AuthLayout`.
- [x] Existing route names, methods, middleware, Fortify actions, Laravel sessions, Socialite redirects/callbacks, and invitation-only registration remain authoritative.
- [x] Login preserves email, password, remember-me, throttling, 2FA branching, and intended redirects.
- [x] Forgot-password responses do not disclose whether an address is unknown, local-password, or SSO-only.
- [x] Local password reset preserves token expiry, validation, password history, remember-token rotation, session revocation, status, and login redirect.
- [x] SSO-only reset returns generic browser success, sends no email, and rejects old/current reset tokens without enrolling a local password.
- [x] Password confirmation preserves the three-hour server timestamp and same-origin intended redirect, including the Profile handoff.
- [x] TOTP and recovery-code login both work without Alpine and retain Fortify throttling, session state, remember choice, and one-time recovery-code use.
- [x] Invitation page props are explicit DTOs and unusable invitation states share one non-enumerating page.
- [x] OAuth operation intent is explicit, server-owned, provider-bound, and limited to `login`, `link`, or `invitation`.
- [x] Socialite's state validation remains enabled; application OAuth context is consumed once and cleaned on every callback outcome.
- [x] Normal provider login resolves only an existing provider identity and never auto-links by email.
- [x] Profile linking cannot transfer provider ownership, infer a target by email, or switch the authenticated user.
- [x] Invitation SSO cannot fall through to normal login/link or authenticate an unrelated linked identity.
- [x] Local and SSO invitation acceptance share a cohesive service contract, are transactional and row-locked, set provenance, and leave no partial state on failure.
- [x] Invitation GET/POST are guest-only and cannot replace an authenticated user's identity.
- [x] Existing `(provider, provider_id)` uniqueness remains and `(user_id, provider)` uniqueness is added only after a duplicate preflight that stops on conflicts.
- [x] `Invitation::acceptedUser()` is corrected to the `hasOne` inverse of `User::invitation()` and covered by regression tests.
- [x] Guest/shared/page props contain no password/history hashes, OAuth credentials, provider IDs, TOTP material, session internals, raw models, or unnecessary invitation details.
- [x] Auth pages use generated Wayfinder helpers; OAuth navigation remains a full document redirect.
- [x] Theme, flash/status feedback, accessibility, mobile layout, login-to-Dashboard, logout-to-login, expired-session redirect, and Blade/Inertia coexistence are verified.
- [x] All superseded auth Blade views and the duplicate Blade auth layout are removed only after reference searches and replacement coverage pass.
- [x] No local-password enrollment feature, API/Sanctum, SSR, TanStack, extra form framework, CI, or unrelated product migration is introduced.
- [x] Focused Pest, Vitest/RTL, Playwright, Wayfinder, TypeScript, lint, formatting, Pint, and production-build gates pass.

## 23. Ordered Work Packages

### Work Package 1: Characterization and security contracts

Intent: lock current valid Fortify/session behavior, reproduce defects, and encode the settled passwordless, OAuth-intent, provider-ownership, and invitation-atomicity contracts before changing responses.

Likely files: `tests/Feature/Auth/*`, focused Socialite/invitation tests, relationship tests, and migration preflight notes.

Tests first: session regeneration, intended redirects, enumeration, passwordless reset/no notification/stale token, three OAuth intents/context lifecycle, provider ownership, invitation races/rollback, and relationship inverse.

Gate: each defect has a failing regression test and no product decision remains open.

### Work Package 2: AuthLayout productionization

Intent: complete the shared brand, theme, feedback, responsive, and accessibility shell used by every migrated page.

Likely files: `resources/js/layouts/auth-layout.tsx`, `use-appearance`, focused layout tests, shared types only if required.

Tests first: heading, flash live region, theme control, brand destination, and mobile structure.

Gate: one production-ready AuthLayout covers form and non-form states without page-specific forks.

### Work Package 3: Login and logout boundary

Intent: replace the login Blade response and preserve local, linked-provider, remember, throttle, intended, and logout behavior without email-based auto-linking.

Likely files: `FortifyServiceProvider`, `pages/auth/login.tsx`, Login Pest/RTL tests, generated route imports.

Tests first: Inertia response, successful/failed/throttled local login, linked/unlinked provider behavior, no email fallback, remember and intended redirects, provider links, regeneration, and logout return.

Gate: local login and logout pass backend, component, and one browser critical path.

### Work Package 4: Forgot and reset password

Intent: migrate both pages, pass explicit reset props, make response messaging enumeration-safe, and enforce the settled no-email/no-enrollment passwordless behavior.

Likely files: `FortifyServiceProvider`, reset response/action bindings or user notification policy, `ResetUserPassword`, `pages/auth/forgot-password.tsx`, `pages/auth/reset-password.tsx`, tests.

Tests first: page props, generic responses, throttle, valid/invalid reset, history, session deletion, SSO-only no-notification, and rejection of old/current tokens for passwordless accounts.

Gate: no account class is disclosed; local reset works; SSO-only behavior is deliberate and tested.

### Work Package 5: Password confirmation integration

Intent: migrate the confirmation page and use server-owned intended redirects consistently with Profile security operations.

Likely files: `FortifyServiceProvider`, `pages/auth/confirm-password.tsx`, `ProfileController`/route only if the narrow bridge can be removed, Profile/auth tests.

Tests first: valid/invalid confirmation, timestamp timeout, intended return, open-redirect denial, and passwordless handling.

Gate: a protected Profile operation round-trips through React confirmation without weakening middleware.

### Work Package 6: Guest two-factor challenge

Intent: replace the broken Alpine interaction with explicit React TOTP/recovery modes while retaining Fortify state and throttling.

Likely files: `FortifyServiceProvider`, `pages/auth/two-factor-challenge.tsx`, Pest/RTL tests.

Tests first: challenged-user requirement, both success/failure modes, switch behavior, limiter, remember/intended/session behavior.

Gate: both modes are keyboard-accessible and complete login; no Profile setup endpoint is called.

### Work Package 7: Invitation integrity and DTOs

Intent: make valid/unusable page responses explicit, correct the inverse relationship, and evolve `InvitationService` into the shared locked/transactional acceptance boundary before wiring the React form.

Likely files: `InvitationController`, `InvitationService`, `Invitation` model, invitation/relationship tests.

Tests first: DTO privacy, guest middleware, all unusable states, normalization, provenance, local-vs-SSO race, transaction rollback for every durable artifact, relationship inverse, role/no-membership contract, and post-commit authentication.

Gate: local and SSO acceptance can share one atomic, revalidated domain operation.

### Work Package 8: Invitation React pages and local registration

Intent: implement valid registration and unified invalid pages using the hardened server contract.

Likely files: `pages/auth/invitation-register.tsx`, `pages/auth/invitation-invalid.tsx`, `InvitationController`, RTL/Pest tests.

Tests first: immutable DTO display, form errors/reset, provider links, invalid state, and sensitive-prop denial.

Gate: local invitation registration is complete from emailed URL to Dashboard and cannot reuse a token.

### Work Package 9: Explicit OAuth intents and provider cardinality

Intent: implement the one-time application OAuth context, remove email auto-linking, enforce login/link/invitation branch isolation, and align database cardinality with Profile's one-provider boolean model.

Likely files: `SocialiteController`, existing `InvitationService`, `social_accounts` uniqueness migration, Socialite tests with provider fakes/mocks, and Profile linking tests.

Tests first: all context lifecycle cases; linked/unlinked login; same-user/other-user/unowned link; user/session mismatch; both invitation providers; linked identity with invitation; invalidated token; provider exception; branch fallthrough denial; uniqueness and duplicate-preflight behavior.

Gate: every callback consumes one explicit intent, cannot create/link/authenticate the wrong identity, and both provider ownership invariants are database-enforced without silent data repair.

### Work Package 10: Cleanup and coexistence hardening

Intent: remove superseded Blade auth files/layout, validate theme and mixed rendering, and run all release gates.

Likely files: Blade auth views/layout, stale asset references, Playwright suite, lifecycle documentation after implementation.

Tests first: reference inventory and browser paths before deletion.

Gate: no auth product response references deleted Blade; full PHP/frontend builds and targeted browser checks pass; later Blade modules remain unchanged.

## 24. Rollback and Coexistence Safety

- Keep route names, methods, middleware, Fortify actions, and mutation contracts stable.
- Land characterization tests and server hardening before deleting views.
- A page closure/controller can be reverted from `Inertia::render` to its prior Blade view without reverting Fortify, Socialite, or invitation domain behavior.
- Legacy product routes remain ordinary Blade document responses and do not depend on `AuthLayout`.
- Timer, allocation, and their layout ownership remain untouched.
- `AppLayout` changes only if a verified login/logout navigation contract requires it; Profile and Dashboard remain implemented.
- AuthLayout remains independent of AppLayout and authenticated navigation.
- Production still runs PHP plus built static assets; no Node server, API authentication, Sanctum, or SSR is introduced.

## 25. Out of Scope

- Local-password enrollment for SSO-only users
- Dashboard or Profile migration, which EPIC-011B completed
- Authenticated Profile 2FA setup/recovery implementation except confirmation-flow compatibility
- Timer, personal time, allocation, or operator time reports
- Projects, boards, milestones, or tasks
- User/operator tickets
- Billing, invoices, Stripe, or payments
- CRM, companies, contacts, organizations, or memberships
- CMS, users, roles, or other admin UI
- PDF/document generation
- New identity providers, passkeys, magic links, or Fortify registration
- Redesign of Fortify, Socialite, OAuth, or invitation-only authentication architecture
- Provider reauthentication for passwordless Profile security operations
- Provider access/refresh-token encryption, removal, or broader token-at-rest redesign unless a discovered consumer blocks secure 011C behavior
- TanStack, React Hook Form, Zod, Sanctum/API, SSR, CI, or deployment changes

## 26. Resolved Decisions and Open Questions

The authentication-domain review resolved all product decisions required to begin implementation:

- SSO-only reset uses the generic browser response, sends no email or guidance notification, and cannot enroll a password through an existing token.
- Password-confirmed Profile operations remain unavailable to passwordless users; provider reauthentication is a future capability.
- Invitation registration remains a guest-only new-account choice among local password, Google, and Microsoft.
- Normal unlinked SSO never auto-links by email; it fails safely.
- Provider linking is explicit from authenticated Profile and is bound to that initiating user without email matching.
- OAuth intent is explicit, one-time, and server-owned; Socialite state remains enabled.
- Invitations contain no organization/company/role context beyond assignment of the global `user` role; this migration does not invent tenant membership.
- Invalid, expired, accepted, raced, and conflicting invitations use one non-enumerating page.
- OAuth redirect/callback endpoints remain server-only.

No product decision currently blocks EPIC-011C. Stop and report rather than inventing behavior if implementation discovers a conflicting live-data condition or a new domain requirement.

## 27. Implementation Record

- Fortify page closures now return the `auth/*` Inertia components, while Fortify continues to own every authentication mutation.
- The application OAuth context is stored under the server-session key `oauth_context` with `intent`, `provider`, an ISO-8601 `startedAt`, and nullable `initiatingUserId` and `invitationToken` targets. Callbacks validate the shape and pull it before provider retrieval.
- `InvitationService::acceptWithPassword()` and `acceptWithSocialAccount()` share the locked transaction boundary. Expected email/provider conflicts roll back before the pending invitation is expired.
- Migration `2026_09_19_000000_enforce_one_social_account_per_provider.php` aborts on duplicate `(user_id, provider)` groups, adds the unique key, then removes the superseded ordinary index.
- Provider access/refresh tokens and expiry metadata remain stored for compatibility. Repository usage search found no consumer outside authentication writes and privacy regression fixtures; token-at-rest redesign remains later security debt.
- The Profile password-confirmation handoff remains because proactive two-factor setup requests still require it.
