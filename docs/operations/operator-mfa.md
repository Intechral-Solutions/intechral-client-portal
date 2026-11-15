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

## After ANY enrolment on a live release

Confirming a factor signs out the account's other sessions, but a request that was already in flight at that instant can leave a session behind (see the
architecture note, section 4). So after **any** enrolment, including a newly granted operator or a customer who enrols before being promoted, the owner
opens **Profile > Browser sessions** about a minute later and signs out the other sessions once more.

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
  ends a thief's session if they got in during the gap. **About a minute after confirming, open Profile > Browser sessions and sign out the other sessions
  once more**: a request that was already in flight at the instant of confirmation can leave a session behind (architecture note, section 4). It does **not** stop someone who knows the password from enrolling first, nor undo a thief having
  read the setup secret of an enrolment the owner then finished. If compromise is suspected, the response is a password reset **and** `user:reset-two-factor`,
  then a fresh enrolment.
* When granting `portal.operator` to an account that **enrolled before this release**, have its owner open **Profile > Browser sessions** and sign out their
  other sessions: nothing ends them at the grant (new confirmations do, for every account).
* The password-confirmation page (`/user/confirm-password`) and password change have no attempt limiter of their own (Fortify applies none; unchanged
  from before). A thief with a profile-limited session could guess the password there. The first-factor password rules and breach check are the defence.
  **A per-account limiter is a required pre-production hardening step** (owner ruling), tracked separately from this release package.
