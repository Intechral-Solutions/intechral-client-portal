# Operator second factor (Release 1.0)

Policy (owner-approved, 2026-11-14): an account holding `portal.operator` must have a **confirmed second factor** before it reaches privileged
functionality. Enforced at the privileged application boundary, not at login; customers keep optional MFA; SSO is the first factor only and provider-side
MFA does not substitute; there is **no grace period** once enforcement is active.

Code: `App\Shared\Mfa\OperatorMfaGate` (policy), `App\Http\Middleware\EnforceOperatorMfa` (enforcement), `User::hasConfirmedTwoFactor()`,
`App\Services\TwoFactorReset` and `App\Console\Commands\ResetTwoFactor` (recovery). Tests: `tests/Feature/Auth/OperatorMfaEnforcementTest.php`,
`tests/Feature/Console/ResetTwoFactorCommandTest.php`.

## 1. Enforcement architecture

**One global web middleware, deny by default**, modelled on the customer-surface gate (`EnforceCustomerPilotSurface`). For a signed-in user it asks:
confirmed second factor? (a column test; an account that has one never pays for the permission lookup) and, only if not, holds `portal.operator`
(`CustomerPilotGate::isOperator()`, the one test of operator eligibility, so a custom role or direct grant counts). A blocked operator may reach only
the route names in `OperatorMfaGate::ALLOWED_ROUTES`; every other route, **named or unnamed, under `/operator` or not**, is refused. A route added later is
refused until a human adds it to that list and the pinned test changes.

*Why an allow-list over the whole authenticated app rather than a list of privileged routes.* An operator uses the shared pages (`/tickets`,
`/projects`, `/time`, `/tasks`, the dashboard) with operator-wide data behind them, and several privileged groups live outside `/operator` (`/admin`,
`/billing`, `/crm`, `/organizations`). Enumerating "privileged" routes would fail open on the next route added; the allow-list fails closed.

| Caller | Refusal |
| --- | --- |
| JSON (`Accept: application/json`) | `403 {"message": "...", "code": "operator_two_factor_required"}`; nothing else |
| Browser page, form post, download | redirect to `profile.show`, reason flashed (`error`) |
| Inertia visit | same redirect; **303** for PUT/PATCH/DELETE (Inertia's response handling, which is why the middleware is registered *after* `HandleInertiaRequests`) |

The refusal happens **before route-model binding** (`prependToPriorityList(before: SubstituteBindings)`), so no record is loaded, no controller runs, no
mutation happens, and an unknown id answers exactly like a real one. The body carries no privileged information.

**Allowed while unconfirmed** (see the constant for the exact list): `root`, `logout`, `profile.show`, the profile's password-confirmation step and
Fortify's `password.confirm*`, password change (needs the current password), session revocation, and Fortify's `two-factor.*` enrolment endpoints.
Profile-**information** update (name and email) is deliberately **not** allowed: the email changes at once, and with the reset-by-email flow a thief holding
only a stolen unenrolled session could take the account over. `profile.show` is on the list,
so the redirect cannot loop; the dashboard, provider linking (`sso.*`) and everything else are not. Guest-only routes and the Stripe webhook are unaffected
(no signed-in user / their own middleware).

**State is read on every request.** The middleware looks at the user row the session just loaded, never at anything remembered in the session. A disabled
factor, a reset, a newly granted operator role or a revoked one takes effect on the next request. "Confirmed" = a stored secret **and**
`two_factor_confirmed_at` (the same test Fortify uses to decide whether to challenge a sign-in).

**No deployed switch.** `config/operator_mfa.php` ships `enforced => true`, reads no environment variable, and `false` is honoured **only when
`APP_ENV=testing`** (the test base class sets it so unrelated operator suites need not enrol; the MFA suites turn it on). In every other environment
the value is ignored. This is a deliberate difference from the customer surface mode.

## 2. Sign-in and enrolment

Password and SSO sign-in are unchanged and remain the **first factor**. An operator with a confirmed factor is challenged (Fortify's challenge) after
either; one without signs in, lands on the dashboard, is redirected to Profile with an explanation, and can reach only the enrolment pages. Provider-side
MFA never counts. Enrolment reuses Fortify end to end (`enable` → QR/secret → `confirm` with a code, behind the existing password confirmation): it is
**not complete until a code has been confirmed**; an enrolment left unconfirmed still counts as none. The profile shows a "required" notice and nothing else
is new in the UI. A pending sign-in parked at the challenge when the factor is removed (reset or disable) is refused at the challenge
(`ActiveAccountTwoFactorLoginRequest`), because no session row names it.

**Operators need a local password (owner ruling).** Fortify's enrolment endpoints stand behind password confirmation, and an account with **no local
password** (SSO-only) cannot confirm one, nor obtain one (`ResetUserPassword` refuses passwordless accounts). So role changes that would newly make such an
account an operator are refused (§5); operators created by `operator:bootstrap` always have a password. A passwordless operator that somehow exists cannot
enrol; the Profile page says so.

## 3. Recovery: `user:reset-two-factor`

`php artisan user:reset-two-factor <email> [--reason=...] [--force]`. Shell access to the server is the only authorization; there is no route and no
remote reset.

* **Exact target:** the address as stored, byte for byte (no id, name, pattern or other case); it prints id, name, email, operator/suspended, factor state first.
* **Confirmation:** a stated reason (option or prompt) and the exact email typed back. **No flag skips the typed confirmation**, so `--no-interaction` cannot satisfy it.
  In production it additionally asks the standard confirmation; `--force` skips only that prompt.
* **Effect**, in one transaction under a row lock: clears secret, recovery codes and confirmation time; signs the account out everywhere
  (stored sessions deleted, remember-me token rotated, outstanding password-reset tokens deleted; this discards password-confirmation and SSO-freshness
  proofs); records the activity-log entry `reset two-factor authentication` on the account with `source`, `reason`, `run_by` (the OS user), `was_confirmed`,
  `target_was_operator`, `sessions_revoked`. It fails closed (nothing changes) unless `SESSION_DRIVER=database`.
* **No secrets:** nothing secret is printed, logged or audited.
* **No bypass of the prompt is offered**, but this is not a defence against a person at a shell, who can answer or script it: shell access is the
  authorization. The protection is the reason, the audit entry and the forced re-enrolment.
* **Not a takeover:** it sets no password, signs nobody in and grants nothing. The operator signs in with the usual first factor, is limited to Profile, and
  must enrol and confirm a new factor before privileged access resumes. It works on any account holding second-factor state (customers included; the audit
  says whether the target was an operator).

## 4. Sessions and the residual first-factor window

The gate reads account state, not what a session once proved. So that a session opened with the first factor alone **before** enrolment cannot become privileged
when the real owner confirms, **confirming a factor signs out all of that account's other sessions and rotates its remember-me token**
(`RevokeOtherSessionsOnConfirmation`; the confirming session continues). It applies to every account, not only operators, because an account may enrol as a
customer and be granted `portal.operator` afterwards. An account that confirmed a factor *before* this release has no such event; its owner signs out
their other sessions when the role is granted (runbook). It cannot prevent the inherent window: an operator
who has not enrolled is protected only by the first factor, and a password holder can enrol their own authenticator first. This is inherent to boundary
enforcement without out-of-band bootstrap; the runbook says to enrol immediately and grant the operator role only to accounts that are about to enrol.

**Known residual race (closure review F1, not closed in this package).** Revocation deletes the session rows that exist when it commits. A request that was
already **in flight** when the owner confirmed can still leave a session behind: a request that creates a session writes a fresh row at the end of the
request (a remember-me cookie that validated the OLD token just before the rotation; a password or SSO sign-in completing across the commit), and the gate
reads only account state, so that session is then fully privileged without ever presenting a second factor. A request on an *existing* session cannot
resurrect its row (its write is an UPDATE that matches nothing). The window is the length of one request around the confirmation, and it needs an attacker
holding a pre-enrolment remember-me cookie (or racing the SSO callback) who polls until confirmation; an attacker holding the password can already enrol
first (the accepted residual above). **Mitigation now:** the runbook has the owner sign out their other sessions again about a minute after confirming.
**Robust fix, a recommended RC1 follow-up (owner decision):** a per-account session epoch, incremented in the same transaction as the remember-token
rotation, stamped into the session on `Login` (which also fires for remember-me logins) and re-stamped on the confirming session, with the gate refusing an
operator session whose stamp is stale.

## 5. Owner rulings and remaining obligations

Rulings (owner, 2026-11-15):

* **Operator MFA is mandatory before RC1.** Confirmed MFA is **not** a prerequisite for *granting* `portal.operator`; it is required before privileged access
  (so an operator is granted the role and then enrols; the runbook treats "granted but not enrolled" as a short, supervised state).
* **Release 1.0 operator accounts must have a local password**, to support Fortify's existing enrolment (which stands behind password confirmation; a
  passwordless account can neither confirm one nor obtain one, `ResetUserPassword` refuses it). **Enforced:** `AccessGrants` refuses (409 / form error,
  nothing changed, `PasswordlessOperatorException`) any role change that would NEWLY make an account with no local password operator-eligible: assigning
  the `operator` role or any role carrying `portal.operator` to such a user, and adding `portal.operator` to a custom role such a user holds. Only new
  eligibility is refused, so unrelated edits never trip over a passwordless operator that already exists (none is expected; if one does, it stays locked out
  of privileged features and the Profile page says so). No other *interactive* application path grants `portal.operator`. (`roles:sync`, the reviewed built-in-role synchronizer, writes the pivot rows directly
  and does not run this check: if the `operator` role's grant had drifted and a passwordless account held it, a sync would restore the grant. That fails
  closed, the account staying locked out and unable to enrol, so it affects availability, not security.)
* **At least two independently accessible operators with confirmed MFA are required before production activation** (runbook).
* **Throttling password-confirmation attempts is a pre-production hardening requirement**, not part of this PR. Until it lands: `/user/confirm-password`
  (Fortify applies no limiter) is the step that precedes enrolment from a profile-limited session.

Still open: nothing blocks this PR. Whether `users.roles.update` should additionally require a confirmed factor before granting the role was ruled
**no**. Browser suite and seeds: operator personas of the dev seed and the E2E suite carry a confirmed factor with a fixed development secret (dev seed
only).
