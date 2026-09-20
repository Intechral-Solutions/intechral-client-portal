# Authentication Flow

## Invitation Flow

```
Operator                          System                          Invitee
   │                                │                               │
   │── Create Invitation ──────────►│                               │
   │   (email, role)                │                               │
   │                                │── Store invitation ──────────►│ DB
   │                                │   (token, email, expiry)      │
   │                                │── Send invitation email ──────►│ Mailpit/SMTP
   │                                │                               │
   │                                │               ◄── Click link ─┤
   │                                │   GET /invitation/{token}    │
   │                                │── Validate token ─────────────┤
   │                                │── Pre-fill email (read-only) ─►│
   │                                │                               │
   │                        Choose ─┤◄── Register with password ────┤
   │                        method  │       or                      │
   │                                │◄── Register with SSO ─────────┤
```

## Local Registration

1. User clicks invitation link: `GET /invitation/{token}`
2. System validates token (not expired, not used)
3. Registration form displayed with email pre-filled and read-only
4. User submits: name, password, password confirmation
5. Password validated (min 12 chars, complexity rules)
6. `User` record created; `Invitation` marked `accepted`
7. User logged in automatically; redirected to dashboard

## OpenID SSO Flow

1. User starts Google/Microsoft authentication from login, Profile, or an invitation.
2. The server stores a one-time OAuth intent (`login`, `link`, or `invitation`) and its target in the session.
3. Laravel Socialite redirects to the provider, which returns to `/auth/{provider}/callback`.
4. The callback consumes and validates the server-owned intent before performing exactly one operation:
   - Normal login authenticates only an existing `(provider, provider_id)` `SocialAccount`. Provider email is never used to find or automatically link a portal user.
   - Profile linking explicitly associates an unowned provider identity with the authenticated initiating user.
   - Invitation registration validates the provider email against the pending invitation, then atomically creates the passwordless user and `SocialAccount` and accepts the invitation.
5. Successful SSO login and invitation registration currently use Laravel's remember-me authentication unconditionally.

## Session Security

- Browser sessions are stored in the database; Redis remains available for cache and queues.
- Signing out other browser sessions deletes their database rows and rotates the user's remember token, while preserving the current session.
- Session cookie: `HttpOnly`, `Secure`, `SameSite=Lax`
- Absolute session timeout: 8 hours (configurable)
- Idle session timeout: 2 hours (configurable)
- Session ID regenerated on privilege escalation (login, 2FA success)

## Password Reset Flow

1. User requests reset at `/forgot-password`
2. System sends a signed, time-limited URL (60 min) to the email
3. User clicks `/reset-password/{token}` link → reset form shown
4. New password validated (same rules; checked against last 5 hashes)
5. Password updated; all sessions invalidated
6. User is redirected to login and signs in with the new password

## 2FA Flow (TOTP)

1. User enables 2FA from profile settings
2. QR code displayed for authenticator app setup
3. User confirms with a valid TOTP code
4. Recovery codes generated (8 × 16-char codes) — user must download
5. On login after 2FA is enabled:
   - Credentials verified → 2FA challenge screen shown
   - TOTP or recovery code required
   - On success → session established
