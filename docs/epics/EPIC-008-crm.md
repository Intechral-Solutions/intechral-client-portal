# EPIC-008: CRM (Customer Relationship Management)

**Status:** Pending
**Branch:** `epic/008-crm`
**Goal:** Provide a simple but effective CRM for managing client companies and contacts — the anchor record that links projects, invoices, and tickets. CRM companies can be promoted to **Organizations**, granting their users a multi-tenant view of their shared data and the ability to manage org-level roles and invitations.

---

## User Stories

### STORY-008-01: Company Management
**As a** platform operator,
**I want** to create and manage client company records,
**So that** all client activity is linked to an organisation.

**Acceptance Criteria:**
- [ ] Company: name, industry, website, phone, address, notes, logo
- [ ] Company statuses: `prospect`, `active`, `inactive`
- [ ] Soft delete (archive) companies
- [ ] Activity timeline: linked tickets, projects, invoices, time entries

### STORY-008-02: Contact Management
**As a** platform operator,
**I want** to manage individual contacts within a company,
**So that** I know who to reach out to and how.

**Acceptance Criteria:**
- [ ] Contact: first name, last name, email, phone, title, company, notes
- [ ] A contact can belong to one company (or no company for individual clients)
- [ ] Portal user accounts can be linked to a contact record
- [ ] Contact activity timeline: linked tickets, emails, notes

### STORY-008-03: Notes & Activity Log
**As a** platform operator,
**I want** to add notes and log interactions against companies and contacts,
**So that** the team has a shared history of client communication.

**Acceptance Criteria:**
- [ ] Note types: call, email, meeting, other
- [ ] Notes can have a follow-up reminder date
- [ ] All system-generated activity (ticket created, invoice sent, etc.) appears in the timeline automatically
- [ ] Filter timeline by activity type

### STORY-008-05: Promote Company to Organization
**As a** platform operator,
**I want** to promote a CRM company to an Organization,
**So that** its contacts/users gain a scoped, multi-tenant view of their shared data.

**Acceptance Criteria:**
- [ ] "Promote to Organization" action on company record
- [ ] Organization flag on company: `is_organization` (boolean)
- [ ] Organization users see only their org's tickets, projects, billing, and time tracking
- [ ] All modules filter records by `organization_id` for organization members
- [ ] Platform operators continue to see all data across all organizations

### STORY-008-06: Organization User Invitations
**As an** organization admin,
**I want** to invite other people from my organization into the portal,
**So that** my team can collaborate on shared tickets and projects.

**Acceptance Criteria:**
- [ ] Organization admins can send invitations to new users
- [ ] Invited users are automatically associated with the organization on registration
- [ ] Organization admins can only invite — they cannot grant platform operator roles
- [ ] Invitation lists show pending invitations per organization

### STORY-008-07: Organizational Roles
**As an** organization admin,
**I want** to define custom roles scoped to my organization,
**So that** I can control what my team members can see and do within our shared data.

**Acceptance Criteria:**
- [ ] Organization admins can create/edit/delete org-scoped roles
- [ ] Org roles can grant permissions within the organization's data only (scoped, not platform-wide)
- [ ] Default org roles: `org_admin` (all org permissions), `org_member` (view + create)
- [ ] Org role assignment visible in user management for org admins
- [ ] Org role changes propagate immediately (cache invalidation)

### STORY-008-08: CRM Search & Filters
**As a** platform operator,
**I want** to search and filter companies and contacts,
**So that** I can quickly find the client I need.

**Acceptance Criteria:**
- [ ] Global search across company name, contact name, email
- [ ] Filter companies by status, industry, assigned operator
- [ ] Filter contacts by company, tags
- [ ] Exportable contact list to CSV

---

## Definition of Done

- All acceptance criteria above are checked
- TDD applied throughout
- Permissions wired (`crm.view`, `crm.create`, `crm.manage`, `crm.admin`, `org.admin`, `org.invite`, `org.manage_roles`)
- Merged to `main` via PR
