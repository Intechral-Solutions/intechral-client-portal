# EPIC-008: CRM (Customer Relationship Management)

**Status:** Implemented
**Committed:** 2026-03-25

---

## Goal

Provide a simple but effective CRM for managing client companies and contacts — the anchor record that links projects, invoices, and tickets. CRM companies can be promoted to **Organizations**, granting their users a multi-tenant view of their shared data and the ability to manage org-level roles and invitations.

---

## User Stories

### STORY-008-01: Company Management
**As a** platform operator,
**I want** to create and manage client company records,
**So that** all client activity is linked to an organisation.

**Acceptance Criteria:**
- [x] Company: name, industry, website, phone, address, notes, logo, status (prospect/active/inactive)
- [x] Soft delete (archive) companies
- [ ] Activity timeline: linked tickets, projects, invoices, time entries

### STORY-008-02: Contact Management
**As a** platform operator,
**I want** to manage individual contacts within a company,
**So that** I know who to reach out to and how.

**Acceptance Criteria:**
- [x] Contact: first name, last name, email, phone, title, company, notes
- [x] A contact can belong to one company (or no company)
- [ ] Portal user accounts can be linked to a contact record
- [ ] Contact activity timeline

### STORY-008-03: Notes & Activity Log
**As a** platform operator,
**I want** to add notes and log interactions against companies and contacts,
**So that** the team has a shared history of client communication.

**Acceptance Criteria:**
- [ ] Note types: call, email, meeting, other
- [ ] Notes can have a follow-up reminder date
- [ ] System-generated activity appears in timeline automatically
- [ ] Filter timeline by activity type

### STORY-008-05: Promote Company to Organization
**As a** platform operator,
**I want** to promote a CRM company to an Organization,
**So that** its contacts/users gain a scoped, multi-tenant view of their shared data.

**Acceptance Criteria:**
- [x] "Promote to Organization" action on company record
- [x] `is_organization` flag on company record
- [x] Organization users see only their org's tickets, projects, billing, and time tracking
- [x] Platform operators continue to see all data across all organizations

### STORY-008-06: Organization User Invitations
**As an** organization admin,
**I want** to invite other people from my organization into the portal,
**So that** my team can collaborate on shared tickets and projects.

**Acceptance Criteria:**
- [x] Operators can add members to an organization
- [x] Invited users are automatically associated with the organization
- [ ] Organization admins can self-service invite (without operator involvement)

### STORY-008-07: Organizational Roles
**As an** organization admin,
**I want** to define custom roles scoped to my organization,
**So that** I can control what my team members can see and do within our shared data.

**Acceptance Criteria:**
- [x] Members can be assigned a role within the organization
- [ ] Full org-role CRUD (create/edit/delete custom org roles)
- [ ] Default org roles: `org_admin` and `org_member`
- [ ] Org role changes propagate immediately

### STORY-008-08: CRM Search & Filters
**As a** platform operator,
**I want** to search and filter companies and contacts,
**So that** I can quickly find the client I need.

**Acceptance Criteria:**
- [ ] Global search across company name, contact name, email
- [x] Filter companies by status
- [x] Filter contacts by company
- [ ] Exportable contact list to CSV

---

## Implementation

### What Was Built

**Migrations**
- `create_organizations_table` — linked 1:1 to a CRM company, name, slug, settings
- `create_organization_members_table` — user ↔ org pivot with a `role` column (simple string, not a separate roles table)
- `create_crm_companies_table` — name, industry, website, phone, address, notes, status, soft delete
- `create_crm_contacts_table` — first/last name, email, phone, title, company FK, notes
- `add_company_id_to_tickets` — links tickets to CRM company

**Controllers**
- `Crm\CompanyController` — full CRUD + `promote` (promote company → organization)
- `Crm\ContactController` — full CRUD
- `Organization\OrganizationController` — index, show
- `Organization\OrganizationMemberController` — store (add member), updateRole, destroy

**Views**
- `crm/companies/index`, `create`, `edit`, `show`, `_form`
- `crm/contacts/index`, `create`, `edit`, `show`, `_form`
- `organizations/index`, `show`

**Multi-Tenant Data Isolation**
- `OrganizationScope` global scope applied to Ticket, Project, Invoice, TimeEntry
- Platform operators bypass the scope and see all data
- `organization_id` nullable FK on all resource tables

**Routes**
- `/crm/companies` + `/crm/contacts` (`can:crm.manage`)
- `/organizations` with member management (`can:crm.manage`)

### Known Gaps

- Activity timeline (linked events auto-appearing on company/contact detail) not confirmed
- Notes module (call/email/meeting types, reminders) not implemented
- Organization admin self-service invite not implemented — operator-only currently
- Full org-scoped role CRUD not built; role is a simple string column on `organization_members`
- Global CRM search not implemented
- CSV export of contacts not confirmed

---

## Definition of Done

- All acceptance criteria above are checked
- TDD applied throughout
- Permissions wired (`crm.view`, `crm.create`, `crm.manage`, `crm.admin`, `org.admin`, `org.invite`, `org.manage_roles`)
- Merged to `main` via PR
