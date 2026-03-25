# EPIC-002: Authentication & User Onboarding

**Status:** Pending
**Branch:** `epic/002-authentication`
**Goal:** Implement a complete, secure authentication system including invitation-based registration, local credentials, and OpenID SSO — verifying users against the email address they were invited with.

---

## User Stories

### STORY-002-01: Invitation System
**As a** platform operator,
**I want** to invite users by email,
**So that** only invited people can register on the platform.

**Acceptance Criteria:**
- [ ] Operator can send an invitation from the admin UI
- [ ] Invitation record stored in DB with token, email, expiry, and status
- [ ] Invitation email sent via queue with a signed URL (48-hour expiry)
- [ ] Re-sending an invitation invalidates the previous token
- [ ] Expired/used invitations return a clear error page

### STORY-002-02: Local Registration via Invitation
**As an** invited user,
**I want** to register with a username and password using my invitation link,
**So that** I can access the portal with local credentials.

**Acceptance Criteria:**
- [ ] Registration form pre-fills email from invitation (read-only)
- [ ] Password meets strength requirements (min 12 chars, complexity)
- [ ] Email verification step is skipped (email already verified by invitation)
- [ ] User record created with `invited_by` and `invitation_id` references
- [ ] Invitation marked as `accepted` on success

### STORY-002-03: OpenID SSO Registration/Login
**As an** invited user,
**I want** to register and log in using my existing SSO provider (Google, Microsoft, etc.),
**So that** I don't need to manage a separate password.

**Acceptance Criteria:**
- [ ] Laravel Socialite configured for at least Google and Microsoft (Azure AD)
- [ ] On first SSO login, email is verified against the invitation email
- [ ] If emails don't match, SSO flow is rejected with a clear error
- [ ] Subsequent SSO logins match on `provider` + `provider_id` (email as fallback)
- [ ] Users can link/unlink SSO providers from their profile

### STORY-002-04: Login & Session Management
**As a** registered user,
**I want** to log in securely and have my session managed safely,
**So that** my account cannot be hijacked.

**Acceptance Criteria:**
- [ ] Login form with email + password (Laravel Fortify)
- [ ] Remember me functionality with secure cookie
- [ ] Session invalidated on password change
- [ ] "Logout all devices" option available in profile
- [ ] Brute-force protection via throttling (5 attempts / minute)

### STORY-002-05: Password Management
**As a** registered user,
**I want** to reset and change my password,
**So that** I can recover my account and keep it secure.

**Acceptance Criteria:**
- [ ] Password reset via email (signed link, 60-minute expiry)
- [ ] Password change from profile page requires current password
- [ ] Password history check prevents reusing last 5 passwords
- [ ] All active sessions terminated on password reset

### STORY-002-06: Two-Factor Authentication (2FA)
**As a** registered user,
**I want** to enable TOTP 2FA on my account,
**So that** my account is protected even if my password is compromised.

**Acceptance Criteria:**
- [ ] TOTP 2FA via Laravel Fortify (Google Authenticator compatible)
- [ ] Recovery codes generated and downloadable on 2FA setup
- [ ] Operators can require 2FA for all users (policy setting)
- [ ] 2FA bypass via recovery code works once per code

---

## Definition of Done

- All acceptance criteria above are checked
- Feature + unit tests written before each implementation
- OpenID configured for at least Google and Microsoft providers
- Security audit checklist completed (OWASP auth top-10)
- Merged to `main` via PR
