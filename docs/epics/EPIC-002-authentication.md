# EPIC-002: Authentication & User Onboarding

**Status:** Implemented
**Committed:** 2026-03-25

---

## Goal

Implement a complete, secure authentication system including invitation-based registration, local credentials, and OpenID SSO — verifying users against the email address they were invited with.

---

## User Stories

### STORY-002-01: Invitation System
**As a** platform operator,
**I want** to invite users by email,
**So that** only invited people can register on the platform.

**Acceptance Criteria:**
- [x] Operator can send an invitation from the admin UI
- [x] Invitation record stored in DB with token, email, expiry, and status
- [x] Invitation email sent via queue with a signed URL (48-hour expiry)
- [ ] Re-sending an invitation invalidates the previous token
- [x] Expired/used invitations return a clear error page

### STORY-002-02: Local Registration via Invitation
**As an** invited user,
**I want** to register with a username and password using my invitation link,
**So that** I can access the portal with local credentials.

**Acceptance Criteria:**
- [x] Registration form pre-fills email from invitation (read-only)
- [ ] Password meets strength requirements (min 12 chars, complexity)
- [x] Email verification step is skipped (email already verified by invitation)
- [x] User record created with `invited_by` and `invitation_id` references
- [x] Invitation marked as `accepted` on success

### STORY-002-03: OpenID SSO Registration/Login
**As an** invited user,
**I want** to register and log in using my existing SSO provider (Google, Microsoft, etc.),
**So that** I don't need to manage a separate password.

**Acceptance Criteria:**
- [x] Laravel Socialite configured for at least Google and Microsoft (Azure AD)
- [x] On first SSO login, email is verified against the invitation email
- [x] If emails don't match, SSO flow is rejected with a clear error
- [x] Subsequent SSO logins match on `provider` + `provider_id` (email as fallback)
- [x] Users can link/unlink SSO providers from their profile

### STORY-002-04: Login & Session Management
**As a** registered user,
**I want** to log in securely and have my session managed safely,
**So that** my account cannot be hijacked.

**Acceptance Criteria:**
- [x] Login form with email + password (Laravel Fortify)
- [x] Remember me functionality with secure cookie
- [ ] Session invalidated on password change
- [x] "Logout all devices" option available in profile
- [x] Brute-force protection via throttling (5 attempts / minute)

### STORY-002-05: Password Management
**As a** registered user,
**I want** to reset and change my password,
**So that** I can recover my account and keep it secure.

**Acceptance Criteria:**
- [x] Password reset via email (signed link, 60-minute expiry)
- [ ] Password change from profile page requires current password
- [ ] Password history check prevents reusing last 5 passwords
- [ ] All active sessions terminated on password reset

### STORY-002-06: Two-Factor Authentication (2FA)
**As a** registered user,
**I want** to enable TOTP 2FA on my account,
**So that** my account is protected even if my password is compromised.

**Acceptance Criteria:**
- [x] TOTP 2FA via Laravel Fortify (Google Authenticator compatible)
- [ ] Recovery codes generated and downloadable on 2FA setup
- [ ] Operators can require 2FA for all users (policy setting)
- [ ] 2FA bypass via recovery code works once per code

---

## Implementation

### What Was Built

**Migrations**
- `create_invitations_table` — token, email, expiry, status, invited_by, role_id
- `add_auth_fields_to_users_table` — invited_by, invitation_id, profile fields, soft delete
- `create_social_accounts_table` — provider, provider_id, encrypted token/refresh_token
- `create_password_histories_table` — previous password hashes

**Controllers**
- `Auth\InvitationController` — `show` (accept page), `register` (complete registration), `store` (operator sends invite)
- `Auth\SocialiteController` — `redirect` (to provider), `callback` (OAuth return with email verification)
- `ProfileController` — profile show, destroy other sessions, unlink social provider

**Fortify Integration**
- Owns login, logout, forgot-password, reset-password, two-factor-challenge, confirm-password
- Sessions stored in Redis; brute-force throttling via Fortify defaults

**Views**
- `auth/login`, `auth/register`, `auth/invitation-invalid`, `auth/forgot-password`, `auth/reset-password`, `auth/two-factor-challenge`, `auth/confirm-password`
- `profile/show` — profile info, linked social providers, session management

**Routes**
- `GET /invitation/{token}` — show invitation
- `POST /invitation/{token}` — complete registration
- `GET /auth/{provider}/redirect` + `/callback` — SSO flow
- `POST /invitations` — operator sends invitation (`can:users.invite`)

### Known Gaps

- Password history enforcement (prevent reuse of last 5) — migration exists, logic not verified
- Operator-enforced 2FA policy not implemented
- Invitation re-send / token invalidation not confirmed
- Session invalidation on password change not confirmed
- Email queue jobs not verified end-to-end (Mailpit available in dev)

---

## Definition of Done

- All acceptance criteria above are checked
- Feature + unit tests written before each implementation
- OpenID configured for at least Google and Microsoft providers
- Security audit checklist completed (OWASP auth top-10)
- Merged to `main` via PR
