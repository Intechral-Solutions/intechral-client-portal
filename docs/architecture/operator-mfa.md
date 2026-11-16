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

### 4.1 The session epoch (session and authentication hardening)

The revocation above deletes rows **after** the confirmation has been saved. Fortify's confirm action saves `two_factor_confirmed_at` and only then dispatches
`TwoFactorAuthenticationConfirmed`, which is what our listener hangs off. That leaves two gaps that revocation alone cannot close (closure review F1, and one
found while closing it):

1. **The gap between the save and the revocation.** For the length of a few statements the account is confirmed while an earlier, pre-enrolment operator
   session still exists, and the gate reads only account state. A request on that session in that gap is fully privileged.
2. **A request in flight across the confirmation.** A sign-in that loaded the user before the confirmation and completes after it (a remember-me cookie that
   validated against the old token, a password or SSO sign-in) writes its session row after the revocation has run. Verified against the framework: a request
   that had **already read** its session row can only UPDATE it, so a deleted row is not resurrected; a request that began **with no row** INSERTs one
   (`DatabaseSessionHandler::write`, pinned by a test). That second kind is the one that survives.

**Mechanism.** `users.session_epoch` is an opaque random token. `User::booted` rotates it **in the same UPDATE** that changes `two_factor_confirmed_at`, and
a *new* confirmation rotates the remember-me token in that same statement. So there is no instant at which the account is confirmed while an earlier session
still matches or an earlier remember-me cookie still validates; it is one row write, not a sequence. Every sign-in stamps the session with the epoch of
**the user row that sign-in holds** (`StampSessionEpoch`, on `Login`, which fires for password, challenge, SSO, invitation and remember-me logins), and the
session that confirms is re-stamped. `OperatorMfaGate::staleSession()` compares the stamp with the row's epoch for an operator-eligible account; on a
mismatch `EnforceOperatorMfa` signs **that device** out (the remember-me token is not touched) and sends the person to sign in, which presents the factor.
It is a sign-out, not the profile redirect: the profile's allow-list includes the endpoints that change the factor, and a stale session must reach none of them.

Two details make "the epoch of the row that sign-in holds" true in practice. Both were found by the independent security review of this package and are tested:

* **A password sign-in loads the user twice.** Fortify decides on a challenge from the first copy (`RedirectIfTwoFactorAuthenticatable`, after one password hash) and
  `attempt()` loads and checks the user again (a second hash) before `login()`. A confirmation committing between the two takes a few hundred milliseconds of
  window, not "a few statements". `EpochRecordingTwoFactorRedirect` (bound in `FortifyServiceProvider`) records the epoch the FIRST copy saw when it decided no
  challenge was needed, and `StampSessionEpoch` stamps that, so a sign-in that straddles the confirmation carries the old epoch. It is consumed at `Login` and used only
  for the same account; it can only be older than the object the sign-in holds, never newer.
* **A stale user object could mint a remember-me token.** `login(..., remember: true)` writes a new token with `save()` on the object it holds when the account has none,
  and queues a cookie carrying it. After a confirmation that write would overwrite the token the confirmation rotated, and the cookie would log the holder back in
  through the remember-me path with the *current* epoch stamped. `ActiveUserProvider::updateRememberToken` therefore makes the write conditional on the account's
  epoch still being the one the object loaded; when it has moved nothing is written and the queued cookie matches no stored token.

What it guarantees, claim by claim (each has its own tests; the third is a **non-claim**):

| Claim | Result |
|---|---|
| **Existing sessions are invalid on their next request.** | **Yes, atomically with the confirmation.** The epoch changes in the confirming UPDATE, so a pre-enrolment session is refused from that instant, with no revocation having run. |
| **A stale in-flight request cannot restore a *privileged* session.** | **Yes for operator-eligible accounts.** The in-flight sign-in stamps the epoch of the row it loaded before the confirmation (for a password sign-in, the copy that decided no challenge was needed; see the two details above); the gate refuses it. The old remember-me cookie stops validating in the confirming UPDATE, and a stale object cannot mint a replacement token. |
| **The row itself.** | **No.** A stale sign-in can still INSERT a session row. For an operator it is unprivileged (signed out on first use); for a **customer** it is an ordinary customer session, because the epoch is judged only for operator-eligible accounts. A customer who later becomes an operator is judged then, by the epoch they hold. |
| **A privileged operation already in progress.** | **No.** The gate judges a request when it arrives. A request admitted before the rotation runs to completion, and nothing here interrupts it (pinned by a test so nobody assumes otherwise). It was not privileged at admission unless the account was already confirmed. |

**Rollout.** The column is nullable with no default. An account that has never changed its factor keeps `NULL` and an unstamped session compares equal to it, so
existing sessions and accounts are untouched. After a confirmation the account has an epoch and an unstamped session is stale. Accounts created or enrolled
by a fresh seed carry an epoch from the start; the browser suite exercises both states.

**Why not the alternatives.** *Binding the stamp to the remember-me token*: logout rotates that token, so signing out on one device would silently end an
operator's other devices. *Deriving it from `two_factor_confirmed_at`*: closes gap 1 but not the remember-me part of gap 2, because the cookie stays valid until
the listener rotates the token. *A session-store-level epoch for every account*: a broader change (every authenticated request, customers included) than the
meaningful risk needs; not taken.

**What remains.**

* The unconfirmed-operator window is unchanged: someone holding only the first factor can enrol first (section 4, above).
* A stale in-flight request can leave a customer-level session row (table above).
* **An operator who disables their own factor is signed out on the next request** (the epoch rotates on disable, and only a confirmation re-stamps the session). It
  fails closed; they sign in again (tested).
* `enable` with `force` on an already-confirmed account replaces the secret without changing `two_factor_confirmed_at`, so the epoch does not rotate. That is
  Fortify's behaviour and needs a freshly password-confirmed session; unchanged by this package.
* A request on the *confirming* session that read the session before the re-stamp was written is stale and signs the owner out once; they sign in again. Nothing in the
  frontend polls, so this is rare.
* The explicit "sign out other browser sessions" button (`revokeOtherSessions`) is hygiene, not a boundary; it does not rotate the epoch. A session restored across
  *that* action is a customer-level or already-confirmed-operator session the owner can sign out again. Not part of this residual.
* `revokeAll` (suspension, reactivation) is not epoch-based either; a suspended account is stopped by the active-account provider, which is a different control.

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
* **Throttling password-confirmation attempts** was a pre-production requirement outside the operator-MFA PR. **Delivered in the session and authentication
  hardening package**, section 6.

Still open: nothing blocks this PR. Whether `users.roles.update` should additionally require a confirmed factor before granting the role was ruled
**no**. Browser suite and seeds: operator personas of the dev seed and the E2E suite carry a confirmed factor with a fixed development secret (dev seed
only).

## 6. Password-verification throttle

Three operations verify the **current password** of a signed-in session, and each is a place to test password guesses: Fortify's confirmation
`POST /user/confirm-password` (`password.confirm.store`, the step in front of enrolment, recovery-code display and the profile's sensitive actions), Fortify's password
change `PUT /user/password` (`user-password.update`) and our "sign out other sessions" `DELETE /profile/sessions` (`profile.sessions.destroy`). The last two are on the
allow-list of an operator who has **not** confirmed a second factor, so a stolen profile-limited session could guess there as freely as at the confirmation. All three
are now limited by `App\Auth\PasswordConfirmationThrottle` (limiter `password-confirmation`), applied by `App\Http\Middleware\ThrottlePasswordConfirmation`.

* **One shared allowance per account: 5 a minute and 20 an hour**, spent across the three operations together (the limiter is keyed on the account, not the
  route), so alternating between them does not multiply it. Never per address: the caller is signed in, so changing address gains a guesser nothing (the same convention as
  `SsoThrottle`). A mistyped password costs one or two attempts.
* **Every attempt that is allowed to run counts, including one that fails for another reason** (a weak new password on a password change); a refused attempt does not
  count and does not extend the wait. **Nothing is validated, changed or deleted on a refused attempt**: a correct password presented while limited is refused exactly
  like a wrong one, so the refusal tells a caller nothing about the password. A guest never reaches it (the `auth` middleware answers as before, and nothing is counted),
  so it is not an account-existence oracle; a stale operator session is signed out by the second-factor boundary before the limiter and neither consumes nor learns
  anything about the allowance; an unconfirmed operator is refused here and still refused everywhere else.
* **Each operation keeps its own failure shape**, so no page changed: a browser or Inertia form is sent back with the message on the field and in the error bag where a
  wrong password already appears for that operation (`password`; `updatePassword` / `current_password`; `destroySessions` / `password`: "Too many password attempts. Try
  again in N seconds."), and a JSON caller gets 429 with `Retry-After` and the same field. Behaviour below the limit is unchanged.
* **Why a web-group middleware keyed on the route name**, not `throttle:` on each route: two of the three routes belong to Fortify, a change made to them at boot would be
  lost under `route:cache`, and one mechanism cannot drift between them. A test pins that every named route exists, so a rename in a Fortify upgrade cannot silently turn a
  limit off.
* **A burst can overrun the counter slightly.** The framework checks the windows and then hits them as separate steps, and the `database` session driver does not lock a
  session, so parallel requests can all pass the check, up to the number of PHP workers. It bounds guessing; it is not an exact meter.

**Accepted residual (owner ruling): a fixed-window limit can temporarily deny a legitimate password-confirmed operation.** It is not an account lockout: sign-in, the
forgotten-password flow, SSO, the confirmation page and its status check, and sign-out are untouched, and only these three operations pause, for at most the remainder of the
window (the response says how long). But the windows are fixed and the allowance is shared, so a holder of a session of the account can spend it at the start of each window, and
the owner cannot enrol, disable a factor, view recovery codes, **change the password or sign out other sessions** until it clears. **Recovery procedure:**

1. **Wait for the allowance.** The message and `Retry-After` give the time; the minute window clears in under a minute, and a full hourly burst clears within the hour.
   This is the normal answer to a person who simply mistyped.
2. **If a hostile session is suspected (the allowance keeps being spent), do not wait: end every session of the account.** Which tool does that depends on the account:
   * **The account holds any second-factor state** (a confirmed factor, or an enrolment started and not finished): someone with shell access runs
     `user:reset-two-factor <email>`. It clears the factor and signs the account out everywhere (every stored session and the remember-me token).
   * **The account holds none** (for example an operator granted the role who has not begun enrolling, which is exactly the population the limiter protects):
     `user:reset-two-factor` does nothing (it reports "no two-factor state" and changes nothing), so it does **not** end the session. Another administrator holding
     `users.manage` **suspends and then reactivates the account** in the admin screens; both steps call `revokeAll`, which deletes every stored session and rotates the
     remember-me token. (Direct deletion of the account's rows in the `sessions` table is the last resort, and is a database operation that needs the usual care.)
     Suspending also permanently revokes the account's open organization invitations and any partner-support designation, and reactivating does not restore them:
     reissue them afterwards.

   After either, the owner signs in, the allowance is no longer being spent, and they change the password and enrol (or re-enrol).
3. The forgotten-password flow alone does **not** end existing sessions in this application (it rotates the remember-me token only), so it is not a recovery on its own.
