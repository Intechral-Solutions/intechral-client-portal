# EPIC-006: Billing & Invoicing

**Status:** Pending
**Branch:** `epic/006-billing`
**Goal:** Provide a full billing system with invoices, Stripe-powered payment collection, and payment history — linked to projects and time tracking.

## Libraries

- **Stripe PHP SDK** (`stripe/stripe-php`) — payment intents, customer management, webhook handling
- **Laravel Cashier (optional)** — subscription billing if recurring billing is needed

---

## User Stories

### STORY-006-01: Invoice Creation
**As a** platform operator,
**I want** to create and send invoices to clients,
**So that** billing is handled within the platform.

**Acceptance Criteria:**
- [ ] Invoice: client (CRM), line items (description, qty, unit price, tax), notes, due date
- [ ] Line items can be pulled from time tracking entries or entered manually
- [ ] Invoice PDF generated server-side (Barryvdh DomPDF or similar)
- [ ] Invoice numbering: configurable prefix + sequential (e.g., `INV-2024-0001`)
- [ ] Invoice statuses: `draft` → `sent` → `paid` / `overdue` / `void`

### STORY-006-02: Client Invoice Portal
**As a** portal user (client),
**I want** to view my invoices and their payment status,
**So that** I can manage my financial relationship with the service provider.

**Acceptance Criteria:**
- [ ] Client-facing invoice list with download link (PDF)
- [ ] Invoice detail page showing all line items and totals
- [ ] Payment status visible per invoice
- [ ] Email notification when a new invoice is issued

### STORY-006-03: Stripe Online Payment
**As a** portal user (client),
**I want** to pay my invoices online using a credit/debit card via Stripe,
**So that** I can settle invoices instantly without bank transfers.

**Acceptance Criteria:**
- [ ] "Pay Now" button on unpaid invoices launches a Stripe Payment Intent
- [ ] Stripe Checkout or hosted Elements UI used (no raw card data on server)
- [ ] Stripe webhook (`payment_intent.succeeded`) updates invoice status to `paid`
- [ ] Payment receipt emailed to client on successful payment
- [ ] Failed payments shown clearly with retry option

### STORY-006-04: Manual Payment Recording
**As a** platform operator,
**I want** to record offline payments (bank transfer, cheque, etc.) against invoices,
**So that** all payment methods are tracked in one place.

**Acceptance Criteria:**
- [ ] Manual payment recording: amount, date, method, reference
- [ ] Partial payments supported (invoice stays `partially_paid` until cleared)
- [ ] Payment confirmation email sent to client on recording
- [ ] Payment history visible on invoice detail

### STORY-006-05: Client Payment History
**As a** portal user (client),
**I want** to view a complete history of all my payments,
**So that** I have a clear record of what I've paid and when.

**Acceptance Criteria:**
- [ ] Payment history page listing all payments (date, amount, method, invoice reference)
- [ ] Each entry links to the related invoice
- [ ] Stripe payment entries show Stripe transaction ID
- [ ] Downloadable payment receipt PDF per payment

### STORY-006-04: Billing Reports
**As a** platform operator,
**I want** billing reports summarizing revenue and outstanding amounts,
**So that** I have financial visibility.

**Acceptance Criteria:**
- [ ] Revenue report: total invoiced vs. collected by period
- [ ] Aging report: outstanding invoices grouped by age (30/60/90+ days)
- [ ] Per-client revenue summary
- [ ] Exportable to CSV

### STORY-006-05: Tax & Currency Configuration
**As a** platform operator,
**I want** to configure tax rates and currency settings,
**So that** invoices comply with local requirements.

**Acceptance Criteria:**
- [ ] Global default currency setting
- [ ] Multiple named tax rates (e.g., GST 10%, VAT 20%)
- [ ] Tax rates selectable per line item
- [ ] Tax totals broken out separately on invoice PDF

---

## Definition of Done

- All acceptance criteria above are checked
- TDD applied, including PDF generation smoke tests
- Permissions wired (`billing.view`, `billing.create`, `billing.manage`, `billing.admin`)
- Merged to `main` via PR
