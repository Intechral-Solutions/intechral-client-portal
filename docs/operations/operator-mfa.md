# Operator MFA: readiness and recovery runbook (Release 1.0)

Design: [`docs/architecture/operator-mfa.md`](../architecture/operator-mfa.md). From the release that contains it, **every account holding
`portal.operator` must have a confirmed second factor to use anything but its Profile page.** There is no grace period and no setting that relaxes it.

## Before activation (staging or production): two operators, both enrolled

Do these **before** the release is activated, in this order. Nothing here is done by the release or by this repository.

1. List **every** account that holds `portal.operator` (built-in role, custom role or direct grant). There must be at least **two**, held by two different
   people who can each reach their own authenticator device independently (not both on one phone, not one person's two accounts). **Each of them must be
   enrolled below, or have the role removed, before activation**: an operator who is not enrolled at activation is limited to Profile until they are.
2. Each of them has a **local password** (required: SSO-only accounts cannot enrol, and the portal now refuses to grant operator access to one).
3. On the *currently running* release, each signs in, opens **Profile > Two-factor authentication**, enables it, scans the QR code, enters a code to
   **confirm**, and stores the recovery codes somewhere only they can reach.
4. Each then opens **Profile > Browser sessions** and signs out their **other** sessions (enrolling on the *current* release does not end sessions opened
   before it; the release you are activating does that automatically, but only for enrolments made after it is live), then signs out and back in to see
   the code challenge.
5. Only then activate the release. Check that each can reach `/dashboard` and `/operator/tickets` after sign-in.

If only one operator exists, create a second first (an authorized operator grants the role; `operator:bootstrap` is for a brand-new installation only).
**Do not activate with a single operator:** one lost device is then the whole team locked out of the product, recoverable only by shell access.

## After enrolment on a live release

Confirming a factor ends every earlier session of the account **at the instant of confirmation**: the account's session epoch and remember-me token change in the
same database write, so an operator session opened before the confirmation, and a remembered browser, are refused on their next request and sign in again
(architecture note, section 4.1). Nothing further is required of the owner after enrolling. The only thing it cannot stop is a request that was already running
at that instant, which was not privileged when it started.

The one case that needs a human is an account that **confirmed a factor before this release and is granted `portal.operator` afterwards**: it has no new
confirmation, so its other sessions are not touched. Its owner opens **Profile > Browser sessions** and signs out the other sessions when the role is granted.

## Lost device and lost recovery codes

A person with their recovery codes uses one at the sign-in challenge, then regenerates codes under Profile. This runbook is only for **both lost**.

1. **Verify the person out of band** (a call or in person, a ticket reference). The command has no way to verify anyone; it trusts the shell user.
2. On the server, in the application directory, as the application's user:
   `php artisan user:reset-two-factor person@example.com --reason="ticket 123: lost phone and recovery codes"`
3. Check the printed account (id, name, email, operator, factor state) is the right one, then type the email back exactly. In production, answer the
   confirmation too (`--force` skips only that).
4. The account is signed out everywhere and its factor removed; the command prints no secret. Tell the person to sign in, go to **Profile**, enrol again
   and confirm a code. Until they do they reach only Profile.
5. The reset is recorded in the activity log (`reset two-factor authentication`, on that account: reason, OS user, whether it was a confirmed factor,
   sessions revoked).

If the command refuses with a message about sessions, the session driver is not `database`; fix that first (it is a deployment prerequisite).

## Never

* Do not edit `two_factor_*` columns by hand: the command also revokes sessions and writes the audit.
* Do not run it to "test" on a real operator. It signs them out and removes their factor.
* Do not treat the command's refusal to run without a typed confirmation as a security control against a person at a shell (they can answer or script the
  prompt): the authorization is shell access itself, and the protection is the reason, the audit entry and the forced re-enrolment.

## Residual risk to know about (a first factor alone can enrol)

Enforcement is at the boundary, so an operator who has **not yet enrolled** is protected only by their first factor: anyone holding that account's password
(or a signed-in session that recently confirmed it) can enrol *their own* authenticator and thereby become a fully privileged operator. The window is
from the moment an account holds `portal.operator` (or is reset) until its real owner confirms a factor. During an enrolment the owner has started but not
confirmed, a password holder can read the same setup secret. Consequences for how you run this:

* **Enrol operators immediately**, and grant the operator role only to an account that is about to enrol, ideally one that already holds a confirmed factor
  (as a customer, enrolling is optional and available). Treat "granted but not enrolled" as a short, supervised state.
* After a **reset**, tell the person to enrol straight away. Confirming a factor signs out the account's other sessions and remembered browsers, which
  ends a thief's session if they got in during the gap. It does **not** stop someone who knows the password from enrolling first, nor undo a thief having
  read the setup secret of an enrolment the owner then finished. If compromise is suspected, the response is a password reset **and** `user:reset-two-factor`,
  then a fresh enrolment. (The forgotten-password flow alone does **not** end existing sessions in this application, only the remember-me token; changing the
  password from a signed-in session, or `user:reset-two-factor`, does.)
* When granting `portal.operator` to an account that **enrolled before this release**, have its owner open **Profile > Browser sessions** and sign out their
  other sessions: nothing ends them at the grant (new confirmations do, for every account).
* The three operations that check the current password (`/user/confirm-password`, the password change and "sign out other browser sessions") share one limit of 5 attempts a
  minute and 20 an hour per account (architecture note, section 6). A person who hits it waits out the message's countdown; it never locks the account out of signing in. **It
  can, however, be used to keep an owner out of those operations for the rest of the window** (someone holding a session of the account can spend the allowance each hour). If
  that is suspected, do not wait: end every session of the account. For an account that holds any second-factor state (a confirmed factor or an enrolment in progress) run
  `user:reset-two-factor`; for one that holds **none** that command does nothing, so have another `users.manage` administrator **suspend and then reactivate** the account (both
  sign it out everywhere; suspension also revokes the account's open invitations and partner-support designation, which must be reissued). Then have the owner sign in, change the password and enrol afresh. The first-factor
  password rules and breach check are the other defence.
