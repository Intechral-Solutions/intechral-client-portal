# Release 1.0: pre-RC1 security hardening (record)

**Status:** implemented on `feature/r1-pre-rc1-security-hardening` from `main` at `8363a6b` (SSO PR #67). Not merged, not deployed; Release 0.9 staging is untouched.
**Scope (owner package, 2026-11-14):** close the application-level security and integrity obligations recorded by R1-3, R1-5, R1-6 and the SSO completion before RC1, without reopening architecture. Host-only matters are a checklist (section 6); nothing was changed on any host.

## 1. Confirmed-obligations inventory

Each row was checked against the code on `8363a6b`, not against the records.

| # | Obligation (source) | State on `8363a6b` | Disposition |
|---|---|---|---|
| 1 | Fresh authentication before linking a provider (SSO record 11) | **Open.** `SocialiteController::redirect()` started a link for any signed-in session | **Fixed** (section 2.1) |
| 2 | Provider access and refresh tokens (SSO record 11) | **Open.** `social_accounts.token/refresh_token/token_expires_at` were written on every sign-in, link and invitation acceptance, in plaintext, and **never read anywhere** (verified: no code path reads them, only writes) | **Fixed: no longer stored; existing values scrubbed** (2.2) |
| 3 | Unconditional remember-me on SSO (SSO record 11) | **Open.** `Auth::login($user, remember: true)` and `login.remember => true` | **Fixed** (2.3) |
| 4 | Per-account throttle on SSO initiation and linking (SSO record 11) | **Open.** Only invitation-initiated starts were limited, by address; ordinary start and the callback had none | **Fixed** (2.4) |
| 5 | Shared per-account reply throttle (R1-6, owner ruling 2026-11-12, release gate 7) | **Open.** The three reply routes carried no limiter | **Fixed** (3) |
| 6 | `PermissionCacheEpoch::current()` first-use defect (defect record) | **Open.** Reproduced against the unfixed code | **Fixed** (4) |
| 7 | Preload permissions before transactional ticket creation (R1-3 20.9) | Assessed | **Not needed after 6; not adopted** (4.2) |
| 8 | ID-token `nonce` (SSO record 11) | Open, **not exploitable alone**: `state` and PKCE already bind the request; the signed token is validated for signature, issuer, audience, tenant and expiry | **Accepted residual** (7) |
| 9 | Operator MFA enforcement | Not enforced anywhere | **Approved as a separate pre-RC1 package** (5, owner rulings) |
| 10 | Invitation-page `Referrer-Policy`, token URLs in access logs, exception-argument redaction, client IP and trusted proxies, `innodb_snapshot_isolation`, live Google and Microsoft verification, production mail and queue | Cannot be closed in application code | **Host checklist** (6) |

## 2. SSO and authentication

### 2.1 Fresh authentication before linking

Linking a provider adds a **second, durable way into the account**, so a hijacked or unattended session must not be able to do it silently. Starting a link now requires a **recent authentication, within 5 minutes** (`SsoFreshness::WINDOW_SECONDS = 300`):

- an account **with a password** proves it **only** by confirming the password (the framework's own `auth.password_confirmed_at`, set by Fortify's confirmation page). A provider sign-in does not stand in for it, so a session opened through a linked provider cannot link a further one without the password. Without a fresh confirmation the request is redirected to the existing confirmation page, which returns to the **profile**; the person then chooses Connect again (an Inertia form post cannot follow a redirect chain through a provider);
- an account **without a password** (created by an SSO invitation) proves it by **having completed a provider sign-in for that account, including its second-factor challenge where one is enabled, within the window**; the proof names the user it was made for. Otherwise the person is told to sign out, sign in again with their provider and connect within five minutes. (It is deliberately not "set a password first": that would be a product restriction for no gain.)
- The link context now carries the same 10-minute maximum age the invitation context always had, so a link started and abandoned cannot be completed later from a session that has since gone stale.
- Unchanged: the link is only for the signed-in initiating user, a provider identity already connected elsewhere is refused, and login and invitation intents are not affected (a guest has nothing to confirm; an invitation is its own credential).

### 2.2 Provider tokens: not stored

The portal uses the provider only to learn **who the person is** at sign-in. It never calls a provider API afterwards, so a stored access or refresh token is pure liability (a database read or a backup leak would hand out provider-scoped credentials). Decision: **do not persist them.**

- Sign-in, link and invitation acceptance no longer write `token`, `refresh_token` or `token_expires_at`; the identity row is `(user, provider, subject)` only.
- Migration `2026_11_14_090000_scrub_social_account_provider_tokens` **sets the three columns to NULL** for every existing row. It changes no schema: the columns stay (nullable) so that the pinned Release 0.9 rehearsal fixtures and `database:verify` are untouched, and a later migration may drop them. It is not reversible by design (the values are gone, which is the point).
- If a future feature needs to call a provider API it must add a deliberate, encrypted, scoped store; nothing is silently reserved for it.

### 2.3 Remember-me

SSO sign-in no longer sets remember-me. An SSO session lasts as long as any other session that was not "remembered" (`SESSION_LIFETIME`, 120 minutes of inactivity by default); a person who wants a long-lived browser can still tick "Remember me" on the **password** form, where the choice is visible. **Product impact:** people who sign in with Google or Microsoft will sign in more often than before. An explicit opt-in on the SSO buttons is possible later and is not needed for RC1. The two-factor challenge path carries `login.remember => false`.

### 2.4 Throttling

`SsoThrottle` adds two named limiters, in the manner of the other per-account limiters:

| Limiter | Route | Signed in (the account) | Guest (the client address) |
|---|---|---|---|
| `sso-start` | `GET /auth/{provider}/redirect` | **10 an hour** per account (a link attempt is rare; a refused attempt counts) | 30 a minute |
| `sso-callback` | `GET /auth/{provider}/callback` | **20 an hour** per account | 60 a minute |

An invitation-initiated start keeps its own limiter (`invitation-sso`, 30 a minute per address) and is not double-counted. A guest's sign-in cannot be limited **per account**, because the account is not known until the provider has proved who the person is; the per-address limit is the practical control there, and it depends on the host reporting the real client address (6, item 4).

### 2.5 Preserved

Verified-email evidence (fail closed), subject-only identity, the invitation hash context, the second-factor challenge after a provider sign-in, session regeneration, suspended-account handling and customer-mode gating are untouched and their tests still pin the original behavior. Four existing test files were edited only where a rule changed on purpose (a link start now needs the fresh proof, the guest start limit, tokens no longer stored); no assertion about those protections was relaxed.

**Existing remembered sessions.** Remember-me cookies issued by earlier SSO sign-ins stay valid until the account's `remember_token` changes (a password change, or the suspension rotation). With no production data this is accepted; backups taken before the scrub migration still contain the old provider tokens and should be treated accordingly.

## 3. Helpdesk: the shared reply throttle

`App\Support\TicketReplyThrottle`, limiter `ticket-reply`, attached to the three reply routes: `tickets.replies.store` (requester), `operator.tickets.replies.store` (operator) and `support.replies.store` (partner support).

- **One bucket per account across all three routes** (same limiter name, same key `user:<id>`): alternating routes does not multiply the allowance, and a person who is both a requester and partner staff has one bucket. Keyed by **account, never address**, so a shared proxy cannot make one person throttle another.
- **30 a minute and 300 an hour.** 30 a minute stops a script or a held-down submit and is far above a person typing. 300 an hour is five replies every minute for an hour, above what an operator working a queue writes (internal notes, canned replies and corrections included). Internal notes count as well: they are the same route, and they are exactly as cheap for a script as a public reply.
- Every attempt counts, including a refused or invalid one, and the limiter runs **before the controller**: an attempt past a limit validates, stores, uploads and mails nothing and answers 429. `auth` runs first; the framework's middleware priority then runs the throttle **before** `can:` and route binding, so an account that is not an operator and posts to the operator route spends its own bucket and answers 429 after 30 tries (harmless: the bucket is its own, and no ticket-existence or authorization answer is given for it). Tests pin that all three routes draw on the one bucket.
- Reading, status changes, assignment, bulk actions and every other operator route are not limited. Notifications, authorization and the reply lifecycle are untouched.
- If an operator is legitimately throttled the allowance returns within the minute; there is deliberately no reset route (`TicketReplyThrottle::clearFor()` exists for tests only).

## 4. Permission cache and concurrency

### 4.1 `PermissionCacheEpoch::current()` first use

Fixed; the full analysis, the reason the previously suggested locking read was **rejected** (it would let a stale snapshot mapping be filed under the epoch every later reader trusts, so a revocation could survive; and under `innodb_snapshot_isolation=ON` it is error 1020; the ephemeral path still performs the ignored insert, whose duplicate-key check takes a shared lock on the existing row, and **whether MariaDB 11.6.2 or later raises 1020 there is untested**), the tests, and the withdrawn recommendation are in the [defect record](./release-1.0-defect-permission-cache-epoch-first-use.md#resolution-2026-11-14). Summary: inside a transaction whose snapshot cannot see a row another transaction just committed, `current()` returns an ephemeral, never-stored token, so the decision is made from that snapshot and nothing stale is shared. No schema or lock-order change; the one difference is that this rare path now holds the shared lock of the ignored insert until the calling transaction ends (before, it threw and the transaction usually rolled back), so an epoch advance waits behind it. A load on this path also leaves one unreachable cache row under the database store (rare, harmless, expires with the cache lifetime).

### 4.2 Preload before ticket creation: assessed, not adopted

No non-HTTP caller of ticket creation exists; both controller paths are safe with 4.1; a preload would move cache-table I/O out of the creation transaction and perturb the lock structure that R1-3A's concurrency tests pin. Established lock ordering and the under-lock, current-row authorization decisions are unchanged. Rule for any future non-HTTP caller: in the defect record.

## 5. Operator MFA: recommendation (nothing imposed)

**Today.** Two-factor (TOTP plus recovery codes) is optional for everyone. A confirmed second factor is asked for after a password **and** after a provider sign-in (since the SSO completion). Nothing requires an account holding `portal.operator` to enrol, and no application path lets an operator or an administrator reset another person's second factor.

**Recommendation: require it, enforced at access to the operator surface, not at login, and only after a break-glass recovery exists.**

| Question | Recommendation | Why |
|---|---|---|
| Mandatory enrolment? | **Yes for `portal.operator` before production**; customers stay optional. | An operator session reaches every customer's data and every operator action; it is the highest-value credential in the portal, and a password (or a reused one) is the only barrier today. |
| At login or at privileged operations? | **At the operator surface**: an operator without a confirmed second factor may sign in, but is redirected from every operator route to the profile's security section until one is confirmed (profile, 2FA setup and sign-out stay reachable). | Blocking at login cannot work cleanly for SSO sign-in, makes a brand-new operator unable to see the page that explains what to do, and turns a bug into a total lockout. Gating the surface is the same protection with a visible way forward, and it is one middleware plus one test matrix. |
| Recovery and lockout | **Build first:** (1) recovery codes are already generated at enrolment (keep the existing "download them" step prominent); (2) a **CLI break-glass command** (`user:reset-two-factor`, shell access only, audited, refusing an account that is not named exactly) for a lost device and lost codes; (3) require **two** operators to exist before enforcement is switched on, so one lockout is never the whole team. | Without (2) the only recovery is editing the database. Without (3) a sole operator who loses the device is locked out of the product. |
| Provider MFA counted? | **No** (owner ruling 2026-11-13 stands). | Keeps the rule uniform; Microsoft or Google may not have MFA configured. |
| Grace | An operator enrolled before the switch-on date keeps working; one who is not sees the redirect at next navigation (no timed grace period). | Simple, testable, no state. |

**Smallest owner decision needed (one sentence):** *"Operators (`portal.operator`) must have a confirmed second factor to use the operator surface, enforced by redirect to enrolment, with a CLI recovery command; yes or no, and before RC1 or before production?"* On **yes**, the work is one small package (middleware, enrolment redirect, CLI reset, tests); I did not start it because it changes who can work on day one of RC1 validation.

## 6. Host and production checklist (no host was touched)

Classification: **Blocker** = must be verified or fixed before the stated step; **Residual** = accepted risk, recorded; **Gate** = release gate that is already owner-scheduled.

| # | Item | What to verify or do (exact check) | Class |
|---|---|---|---|
| 1 | Effective `Referrer-Policy` on the invitation page | On the real host: `curl -sI https://<host>/invitation/not-a-real-token` and `curl -sI 'https://<host>/auth/google/redirect?invitation=x'` must show `Referrer-Policy: no-referrer` and `Cache-Control: no-store` **after** the web server and any proxy have had their say (the application sets them; LiteSpeed or a CDN can add or replace headers). The page has no external links; the exposure is a link or script an intermediary injects. | **Blocker before customer invitations on production** (a five-minute check) |
| 2 | Token-bearing URLs in web-server access logs | The invitation token is in `/invitation/{token}` and `?invitation=`. The database stores only its hash, but the access log records the plaintext request line. Confirm: who can read the logs (account owner only), retention (7 days or less matches the invitation lifetime; the token is single-use, revocable and expires in 7 days), and that logs are not shipped to a third party. Where possible exclude or mask the path. | **Residual** if the log is readable only by the account owner and retained briefly; **blocker** if logs are shipped elsewhere or world-readable |
| 3 | Production exception-argument redaction | `php -i` for the **web and CLI** PHP of the site must show `zend.exception_ignore_args => On`, `display_errors => Off`, `log_errors => On`. Stack traces otherwise record function arguments (passwords, tokens) in logs. Not checked by `runtime:preflight` today; a profile check is a worthwhile follow-up, not a release prerequisite. | **Blocker for production** (readiness gate set by R1-3) |
| 4 | Client IP and trusted proxies | The application configures **no** trusted proxies. Verify on the host that `$request->ip()` is the visitor: if LiteSpeed or a proxy hides the visitor, every per-address limiter (login, two-factor, invitation, **and the new guest SSO limits**) degrades into one **global** bucket, which is a self-inflicted denial of service. Check the real behavior with a request from two networks; configure `TRUSTED_PROXIES` only for the actual proxy addresses. Per-account limiters (replies, SSO link, invitation issue, partner create) do not depend on it. | **Blocker for production** (observation); fix is configuration |
| 5 | MariaDB `innodb_snapshot_isolation` | Run `SELECT @@innodb_snapshot_isolation;` on the production database. 11.4.x (the pinned version) has the variable **off**, which is what the lock-based guarantees were written and tested against. From 11.6.2 it defaults to **ON** and a stale locking read becomes error 1020 (a closed 500). The first-use epoch fix no longer depends on the setting. | **Residual** on the pinned 11.4.13; **blocker** if the host is 11.6.2 or later until the concurrency suites are run against it (`DatabaseProfile` does not check it; a candidate check recorded by R1-5) |
| 6 | Live Google and Microsoft verification | The list in [SSO completion, section 7](./release-1.0-sso-completion.md): real sign-in, link and invitation acceptance on both providers; Microsoft `xms_edov` for work and personal accounts; second-factor challenge; the redirect URIs. Credentials supplied separately; inert without them. | **Gate**, required before SSO is announced to customers; **not** a blocker for RC1 validation of password sign-in |
| 7 | Production mail and queue readiness | A real sender domain with SPF, DKIM and DMARC aligned; a mail relay (Proton SMTP is proven on staging only); the queue worker's supervision on the shared host (cron-driven wrapper) with the 120/120/270+15 timing against `retry_after` 300; `failed_jobs` monitored and an owner for it; `APP_KEY` and `APP_PREVIOUS_KEYS` handled as in the R1-3 notes. Invitations, replies and every notification depend on it. | **Blocker for production** (already tracked by the pilot readiness audit) |

**Accepted residual risks (none blocks RC1):** the SSO ID-token `nonce` is absent (state and PKCE bind the request); a provider's "verified" flag means verified at some time, not that the holder controls the address today (needs the invitation token **and** a stale provider account); an invitation token sits in access logs for its 7-day life (2); per-address guest limits are only as good as the client address (4); passwordless accounts must sign in again to add a second provider (2.1).

## 7. Independent review

**Opus review (2026-11-14, read-only, independent): SAFE AFTER TARGETED REMEDIATION; no blocking finding.** No path to link a provider or take over or enter an account without the intended proof; no regression in verified-email, invitation-hash, MFA-challenge, suspended-account or closed-mode handling; the epoch reasoning judged sound. Remediated in this record and tree:

| Finding | Remediation |
|---|---|
| Doc said `can:` runs before the throttle (it does not) | Corrected (section 3) |
| Doc said the confirmation returns to the link start (it returns to the profile) | Corrected (2.1) |
| A provider sign-in also counted as proof for accounts that have a password | **Code tightened:** a password account needs the password confirmation; the provider proof is for passwordless accounts only; test added |
| The freshness proof was a bare timestamp | **Code tightened:** the proof names its user and is compared in `isFresh` |
| Test "still a failure outside a transaction" was vacuous (a missing table threw before the guard) | Rewritten on an existing empty-value row; the mutant that always returns the ephemeral token now fails it (verified) |
| Operator route sharing the bucket was not pinned | Test: a spent support account is refused on the operator route with 429, and an operator's allowance is spent across the requester and operator routes |
| "Not fresh while the challenge is pending" not pinned | Test: an abandoned challenge leaves no proof, and the next sign-in on that session does not inherit one |
| `innodb_snapshot_isolation` independence of the epoch fix unverified | Stated as untested on 11.6.2+ (4.1, checklist item 5) |
| Lock held longer; unreachable cache rows; remember cookies from older builds; misleading passwordless message; raw 429 on Inertia | Recorded (4.1, 2.5); message reworded; **Inertia shows its raw-response modal on a 429 (no styled message), a UX item for product convergence, not security** |


## 8. Tests and evidence

Local runs (Pest against the testing database, one suite at a time; **hosted CI is the full regression gate and has not run**):

| Run | Result |
|---|---|
| `SsoHardeningTest`, `TicketReplyThrottleTest`, `PermissionCacheEpochFirstUseTest` (new) | 36 passed, 803 assertions (after the review remediation) |
| `tests/Feature/Auth` + reply, partner-support-route and pilot-surface suites (after the remediation) | 386 passed, 5574 assertions |
| Adjacent: pilot surface and gate, organization invitation flow, `tests/Feature/Database`, `tests/Unit/Architecture`, `tests/Unit/Configuration` | 680 passed, 5235 assertions |
| Epoch suites (`PermissionCacheEpochTest`, `…MutationTest`, `…DatabaseStoreTest`, `…CommitOrderTest`) | 88 passed, **20 skipped** (the Redis-store variants: no Redis reachable from the test process; unchanged by this work) |
| `OrganizationInvitationConcurrencyTest`, the four "T and I arriving behind a frozen hook" variants, now requiring every worker `ok` | 4 passed, 132 assertions |
| Reply-route suites (`TicketReply*`, `TicketDueDateOverrideTest`, `PartnerSupportRoutesTest`, `PartnerParticipantNotificationsTest`) | 189 passed |
| Pint | clean on `app`, `tests`, `routes`, `database` |

**Mutation probes (each caught):** the old `current()` throws on the new two-session test; the transaction guard (always-ephemeral) fails the outside-transaction test; dropping the user binding of the freshness note fails the real-`Login` test (a first version of that test was vacuous and was rewritten after the probe survived it).
**Not run locally by design:** the full Pest suite, `./dev test:e2e`, the front end (no front-end file changed). **Not exercised anywhere:** live Google and Microsoft sign-in.

## Owner rulings (2026-11-14), recorded with the commit and PR authorization

1. **Operator MFA:** mandatory second-factor enrolment for accounts holding `portal.operator` is **approved as a separate package before RC1**. It is not part of this package and its implementation does not begin on a branch dependent on this one before this package merges. The recommendation in section 5 (gate the operator surface, CLI break-glass reset, two operators first) is the starting design; the package gets its own design check.
2. **SSO remember-me stays disabled** (section 2.3).
3. **Shared reply throttling at 30 a minute and 300 an hour is accepted** (section 3).
4. The optional `snapshot-` assertion in the broader invitation concurrency tests is **deferred**; the dedicated deterministic first-use test is the required proof.
5. Accepted residuals and the host-level release requirements (section 6) are unchanged.
