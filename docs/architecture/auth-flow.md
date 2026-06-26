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
   │                                │   GET /register?token=...     │
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

1. User clicks "Sign in with Google/Microsoft" on registration/login page
2. Laravel Socialite redirects to provider's OAuth endpoint
3. Provider redirects back to `/auth/callback/{provider}`
4. Socialite retrieves the authenticated email from the provider
5. **On first login (registration context):**
   - System checks for a valid invitation matching the provider email
   - If no invitation or email mismatch → redirect to error page
   - If valid → create `User` + `SocialAccount` records; mark invitation `accepted`
6. **On subsequent logins:**
   - System looks up `SocialAccount` by `provider` + `provider_id`
   - Falls back to email match if `SocialAccount` not found (for migration scenarios)
   - User logged in; session created

## Session Security

- Sessions stored in Redis (not the DB or file system)
- Session cookie: `HttpOnly`, `Secure`, `SameSite=Lax`
- Absolute session timeout: 8 hours (configurable)
- Idle session timeout: 2 hours (configurable)
- Session ID regenerated on privilege escalation (login, 2FA success)

## Password Reset Flow

1. User requests reset at `/password/reset`
2. System sends a signed, time-limited URL (60 min) to the email
3. User clicks link → reset form shown
4. New password validated (same rules; checked against last 5 hashes)
5. Password updated; all sessions invalidated
6. User logged in and redirected to dashboard

## 2FA Flow (TOTP)

1. User enables 2FA from profile settings
2. QR code displayed for authenticator app setup
3. User confirms with a valid TOTP code
4. Recovery codes generated (8 × 16-char codes) — user must download
5. On login after 2FA is enabled:
   - Credentials verified → 2FA challenge screen shown
   - TOTP or recovery code required
   - On success → session established
