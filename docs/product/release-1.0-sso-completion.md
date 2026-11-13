# Release 1.0: Google and Microsoft SSO completion (design record)

**Status:** implemented on `feature/r1-sso-completion` from `main` at `34b8822` (R1-7, PR #66). Not committed until the hand-back is accepted, not merged, not deployed; Release 0.9 staging is untouched.
Scope (owner package): finish the approved Google and Microsoft sign-in, the customer SSO paths and organization-invitation acceptance for Release 1.0, with verified provider-email enforcement that fails closed, no account-takeover path, no MFA bypass without an owner decision, and an accurate `identity_taken` answer. No broad authentication rewrite, no secrets in the repository, no production provider activation.

## 1. Assessment: what already worked and what did not

| Area | Before | Finding |
|---|---|---|
| Intent model (`login`, `link`, `invitation`), one-time provider-bound context, 10-minute invitation age, hash-only invitation context | worked (R1-3B) | kept unchanged |
| Login by `(provider, provider_id)`, never by address; link only for the initiating user; suspended account answers as unlinked | worked | kept; the identifier is now the provider **subject** (below) |
| Invitation acceptance core (`OrganizationInvitations::acceptByHash`: locks, issuer authority, address comparison, single use, atomic account + role + identity + membership) | worked (R1-3A) | untouched |
| **Google** | driver present (`laravel/socialite`), claim `email_verified` exposed but **never checked** | gap: no verified-email enforcement |
| **Microsoft** | **no driver**: `Socialite::driver('microsoft')` threw (a 500 on initiation); the button was shown | gap: not functional |
| Verified provider email | **absent**: the callback compared the address with the invited one but never asked whether the provider verified it | gap (owner ruling 2026-11-12) |
| Customer SSO | **deferred in `closed` and `pilot`** (`CustomerPilotGate::ssoDeferred()`); Release 1.0 customers run in `pilot` | gap: nothing a customer could use |
| Two-factor | SSO login called `Auth::login()` directly: a **confirmed second factor was bypassed** for every account, operator or customer | gap: MFA bypass |
| Session | `Auth::login()` migrates the session; no regeneration on the challenge path (there was none) | adequate for the direct path |
| `identity_taken` | an already-linked provider account accepting an invitation was told "This invitation is no longer available", although the invitation stayed open | gap (F4) |
| Missing credentials | initiation reached Socialite and failed | gap: not a plain refusal |
| Display-name length | the provider's name was written as given | gap (obligation 4) |

## 2. What changed

- **`App\Auth\Sso\MicrosoftProvider`** (new, registered with `Socialite::extend('microsoft', …)`): Microsoft identity platform v2.0, authorization-code flow **with PKCE**, scope `openid profile email`. It reads the **signed ID token** and validates it before trusting a claim: signature against Microsoft's published keys (`firebase/php-jwt`, already a dependency of Socialite's Google driver; RSA signature keys only, **algorithm pinned to RS256** because Microsoft's key set carries no `alg`, which also rules out `none` and HS256 key confusion), **the signing key's published issuer bound to the token's issuer** (a personal-account key cannot vouch for a work tenant), `exp`/`nbf`, audience = this client id, `tid` and `oid` are GUIDs, issuer exactly `https://login.microsoftonline.com/{tid}/v2.0`, and the tenant is allowed by `MICROSOFT_TENANT_ID` (`common`, `organizations` = no personal accounts, `consumers`, or one GUID). The key set is cached for an hour and fetched again once when a token names an unknown key id. The identity is `{tid}.{oid}` (an object id is unique only within its tenant). A mistyped `MICROSOFT_TENANT_ID` makes the provider "not available" (visible) instead of refusing everyone. Any failure is an exception, which the controller treats as a provider failure.
  *Considered and not chosen:* the community `socialiteproviders/microsoft` driver reads Graph `/me`, which carries no verification statement, and adds a third-party package to the authentication path.
- **`App\Auth\Sso\ProviderIdentity`** (new): the one place provider data becomes a decision. `subject` finds or links an account; `verifiedEmail` is non-null only on the provider's own evidence (Google `email_verified` is `true`/`"true"`; Microsoft `xms_edov` is `true`/`1`/`"1"`/`"true"`) **and** a syntactically valid address. A missing, false or differently spelled claim, no address, or an address-like value read from anywhere else (`mail`, `preferred_username`, UPN are never read) is null: **fail closed**.
- **`App\Auth\Sso\SsoProviders`** (new): a provider is available only when its id, secret and redirect are configured. **Credentials are the activation switch**: nothing is activated by merging this code.
- **`SocialiteController`**: refuses an unconfigured provider with a plain message at initiation and at the callback (also for the invitation flow, pointing back at the link the request itself carried); uses the subject for every intent; requires `verifiedEmail` before the invitation is read (a refusal writes nothing and leaves the invitation open); gives `identity_taken` its own message; truncates the display name to 255; one `signIn()` for every session (below).
- **Customer mode.** `CustomerPilotGate::ssoDeferred()` is now true only in **`closed`** (the narrowest surface and the default). In `pilot` (the Release 1.0 customer mode) customer SSO sign-in, linking and invitation acceptance work; `sso.redirect` and `sso.callback` are on the pilot allowlist (a signed-in customer linking a provider; guests never reach the gate) and still answer 404 in `closed`. The profile offers the connect option to customers in `pilot` mode; the invitation page offers provider links only when at least one provider is configured. The login page and the profile list both providers either way, and an unconfigured one answers with the plain "not available" message (a UX point, not a security one).

## 3. Account-linking and takeover safeguards (unchanged rules, now proven for both providers)

- Sign-in finds an account **only** by `(provider, subject)`. A provider that claims a portal user's address, verified or not, signs nobody in (test: `does not sign in the account whose address the provider claims`).
- Invitation acceptance creates a **new** account for the **invited** address and only on a verified, equal address; an address that already has an account is the uniform "no longer available" answer; the row, not the provider, supplies the stored address.
- Linking needs a signed-in session and a context bound to that user; an identity already connected elsewhere is refused.
- Residual: linking asks no fresh password (a signed-in session is the proof). Recorded for the hardening package.

## 4. MFA decision (owner ruling needed only to **relax** it)

A provider sign-in is the **first factor**, like a password. An account with a **confirmed** second factor is sent to Fortify's own challenge (`two-factor.login`, rate-limited, suspended-account-aware) and is not signed in until the code or a recovery code is accepted. An account without a confirmed second factor signs in as before. This removes the unconditional bypass that Release 0.9 documented. Operators and customers are treated alike. Not changed: whether operators must **enrol** a second factor (not enforced anywhere today; a hardening decision), and the unconditional "remember me" on SSO sign-in.
**Owner decision available:** if Intechral wants a provider's own MFA to count in place of the portal's, that must be a recorded ruling; the code does not assume it.

## 5. Session integrity

`signIn()` regenerates the session identifier before establishing the session (a pending challenge included), then signs in (which migrates it again) or parks `login.id`/`login.remember` for Fortify's challenge, which regenerates on success. Tested against a session row planted before the provider round trip: it never receives the authenticated or pending-challenge state. `state` is verified by Socialite (a mismatch is a provider failure); Microsoft also uses PKCE. Invitation tokens, hashes and contexts keep the R1-3 handling.

## 6. Provider flows and configuration requirements

| | Google | Microsoft |
|---|---|---|
| Registration | OAuth client, type Web application; authorized redirect `{APP_URL}/auth/google/callback` | Entra app registration, web platform; redirect `{APP_URL}/auth/microsoft/callback`; **optional ID-token claim `xms_edov` added** |
| Environment | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` (`GOOGLE_REDIRECT_URL` defaults from `APP_URL`) | `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_TENANT_ID` (`common` by default) |
| Identity | `sub` | `{tid}.{oid}` |
| Verified-email evidence | `email_verified` | `xms_edov` on the signed ID token, with the `email` claim |
| Without the evidence | invitation acceptance refused; sign-in and linking of an existing identity still work | same |
| Customer mode | `PORTAL_CUSTOMER_SURFACE_MODE=pilot`; deferred in `closed` | same |

Operator-created accounts, password sign-in and optional 2FA are unchanged. A customer with no portal account can still never be created by SSO except through an invitation.

## 7. Tests: what is automated and what still needs a live provider

Automated (contract verification, no network): `SsoCompletionTest` (verified-email matrix for both providers, subject-only identity, `identity_taken`, customer pilot paths, unconfigured providers, MFA challenge and its completion, session planting, replay and provider mismatch), `MicrosoftProviderTest` (request shape and PKCE; a locally signed ID token accepted, and refused for a forged signature, wrong key, expiry, not-yet-valid, wrong audience, wrong issuer, bad tenant or object id, disallowed tenant, empty key set, key-set failure, state mismatch; evidence spelling matrix), the existing SSO, invitation and pilot suites updated for the new rules, and `tests/Browser/sso-providers.spec.ts` (both providers: offered, start is a redirect or a plain refusal, cancelled callback is a plain refusal). Mutation probes: each control (verified-email gate, evidence rule, MFA challenge, audience, tenant, stored subject, `identity_taken` message, pilot enablement, session regeneration) is caught by at least one test.

**Live end-to-end verification still required (needs real credentials; not run, not automatable here):**
1. Google: a real person signs in, links, and accepts an invitation; confirm `email_verified: true` for a Workspace and a consumer address.
2. Microsoft: confirm the app registration emits `xms_edov` in the ID token for (a) a work or school account and (b) a personal account. If a personal account does not emit it, those people use the password path (they are refused by SSO acceptance, by design).
3. The exact redirect URIs and the consent screens; the `common` tenant versus a chosen tenant.
4. A signed-in customer linking each provider on staging in `pilot` mode.
5. If the owner ever adds a claims-mapping policy to the Microsoft application, Entra signs with an app-specific key that is not in the common published set and every token is refused (fail closed). Adding the optional `xms_edov` claim does not do this.

## 8. Owner decisions and production dependencies

1. Register the two applications and supply credentials in the deployment environment only (never the repository). Nothing here activates a provider.
2. Decide the Microsoft tenant policy (`common` is the default; a single tenant or `organizations` is narrower).
3. Confirm the MFA position in section 4 (the safe default is implemented).
4. Run the live checks in section 7 before customer invitation SSO is announced.
5. `PORTAL_CUSTOMER_SURFACE_MODE=pilot` is already the Release 1.0 customer mode; `closed` keeps customer SSO off.

## 9. Independent review (Opus, 2026-11-13) and its remediation

Verdict **SAFE AFTER TARGETED REMEDIATION**: no account-takeover, unauthorised-creation or MFA-bypass path; the one blocking finding was reliability.
- **Blocking, fixed:** the driver parsed Microsoft's key set without a default algorithm, and Microsoft's real keys carry no `alg`, so php-jwt refused every real Microsoft token (fail closed, but Microsoft sign-in could never work; the tests used a fixture key that had `alg`). Fixed with the RS256 pin; the test fixture now has the real key set's shape (no `alg`, an `x5t`, an `issuer` template).
- **Should-fix, fixed:** signing key not bound to the issuer (now bound; key without an issuer refused); the "another key under a matching kid" test did not test that (now signs with a different key under the published kid, and `alg: none` and HS256-with-the-public-key cases were added); each refusal now asserts its reason.
- **Info, fixed:** the old session row is destroyed on sign-in (`regenerate(destroy: true)`); a mistyped tenant is visible; the key set is cached with a refetch on an unknown `kid`; stale comments corrected.
- **Info, recorded:** Google's and Microsoft's "verified" flags say the address was verified at some time, not that the holder controls it today (an address outside `gmail.com` without an `hd` claim; a personal Microsoft account). Exploiting it still needs the invitation token **and** that stale account, so it is accepted. The signing-key issuer binding was missing from the first residual list and is now implemented rather than a residual.
- **Controls the reviewer found unpinned, now pinned:** the real key-set shape, signature under a matching kid, `none`/HS256, key-issuer binding, destruction of the old session row. Still only fakes: the real Google and Microsoft drivers inside the controller flow (`email_verified` from `userinfo`, `xms_edov` from a real token), which is exactly the live check in section 7.

## 10. Recorded for the next (hardening) package, not done here

- A fresh-authentication requirement before linking a provider; a per-account throttle on the SSO routes beyond the invitation-initiation limiter.
- Stored provider tokens (`social_accounts.token`, `refresh_token`) are plaintext columns and are not used after sign-in; stop storing them or encrypt them.
- "Remember me" is unconditional on SSO sign-in.
- Whether operators must enrol a second factor.
- An ID-token `nonce` (state and PKCE already bind the request).
- Items 5 to 8 of the R1-3 preproduction list that are host checks (exception-argument redaction, proxy logs, the client-address assumption), and the reply throttle (release gate 6).
