# EPIC-012: Document Generation and PDF Architecture

**Status:** Planned / Discovery  
**Architecture decision:** Pending  
**Depends on:** Existing billing/domain data as needed; independent of [EPIC-011](./EPIC-011-react-frontend-migration.md) frontend migration

---

## Goal

Design and prove a maintainable document-generation architecture for the Intechral Client Portal without coupling the decision to the React browser frontend.

The first production use case is expected to be invoice PDFs. The architecture should also be evaluated against a representative Statement of Work document so the selected approach is not optimized too narrowly for a single simple invoice layout.

This epic intentionally does **not** select a renderer yet.

## Why this is separate from EPIC-011

The repository currently has DOMPDF packages installed but no implemented PDF routes, jobs, templates, downloads, or archival workflow. PDF generation is therefore a new subsystem, not a Blade-to-React migration task.

A React browser frontend does not require PDFs to be rendered with React/JSX. The rendering technology should be selected based on document quality, host/runtime constraints, maintainability, operational complexity, and future document needs.

## Candidate approaches

At minimum evaluate:

### A. Spatie Laravel PDF + DOMPDF

- Pure PHP runtime
- Strong Laravel integration
- Low deployment/operations complexity
- More limited modern CSS support
- Likely sufficient for conventional invoices if the design fits DOMPDF's layout capabilities

### B. Spatie Laravel PDF + Cloudflare Browser Rendering

- Laravel-native calling surface
- Chromium-quality modern CSS/Tailwind rendering
- No local Node/Chrome requirement on the Namecheap host
- External network/service dependency
- Requires Cloudflare credentials, usage/cost review, and privacy/availability consideration

### C. Spatie Laravel PDF + Browsershot

- Chromium-quality modern CSS/Tailwind rendering
- Laravel-native package integration
- Requires Node and Chrome/Chromium plus process execution
- Higher operational risk on shared/cPanel hosting

### D. pdfcn + Takumi or Forme

- JSX/component-oriented document authoring
- Attractive if documents become a substantial reusable design system
- Requires a Node-side runtime/bridge or service boundary from Laravel
- Higher custom integration and deployment complexity
- Should be chosen because JSX document components provide long-term value, not merely because the browser UI uses React

Other renderers supported by a chosen abstraction may be considered if the spike exposes a clear reason.

## Discovery Principle

Do not choose the final renderer from documentation alone.

Before committing to the architecture, build a small host/prototype spike that renders the **same representative data** into:

1. A realistic multi-page invoice
2. A realistic Statement of Work sample

The SOW prototype does not imply that the SOW application feature is being implemented in this epic. It is a renderer stress test for richer document structure.

## Prototype document requirements

### Sample invoice

Include enough complexity to expose layout weaknesses:

- Brand/logo/header
- Customer and issuer details
- Invoice metadata
- Multiple line items with long descriptions
- Quantity/rate/amount where relevant
- Subtotal, adjustments, tax if applicable, payments/credits, total/due
- Notes/payment terms
- Long enough data to cross a page boundary
- Repeating header/footer if desired
- Page numbers
- Currency formatting

### Sample Statement of Work

Include:

- Cover/header information
- Client/project metadata
- Overview/objectives
- Scope and deliverables
- Milestones or schedule table
- Assumptions
- Out-of-scope section
- Commercial/fee table
- Terms or acceptance/signature section
- Multi-page content with deliberate page-break cases
- Lists, headings, tables, and long prose

The same fixture content should be used across candidate renderers so visual and operational comparisons are meaningful.

## Evaluation criteria

Score the prototypes qualitatively and record evidence for:

- Visual quality and typography
- Modern CSS/Tailwind fidelity
- Tables and repeated headers
- Page breaks and widow/orphan behavior where available
- Headers/footers/page numbering
- Images and branding
- Multi-page stability
- Deterministic output
- Font handling
- Accessibility/text selection where relevant
- PHP/Laravel integration complexity
- Runtime dependencies
- Queue compatibility
- Host compatibility
- Failure/retry behavior
- Temporary-file handling
- Security and private document delivery
- External service/privacy considerations
- Local development parity
- Automated testing approach
- Long-term maintainability
- Portability to another host
- Suitability for future reports/proposals/contracts/SOWs

## Namecheap / Production Host Spike

If a candidate requires local Node/process execution, verify on the actual production hosting environment rather than relying only on the cPanel UI.

Capture actual command output/paths for:

- `node --version`
- `npm --version`
- `which node`
- `which npm`
- Supported Node version for the candidate renderer
- `npm ci` / production dependency installation
- Disk quota impact
- PHP-FPM `PATH` versus SSH/cPanel Node app `PATH`
- Whether PHP can execute the exact Node binary
- Availability of `exec`, `shell_exec`, `proc_open`, `proc_terminate`, and Symfony Process behavior
- One-shot Node execution from CLI PHP
- One-shot Node execution from a web request, if safe for the test
- Execution from the real queue-worker mechanism
- Persistent Node application startup/restart behavior if a service design is considered
- Whether Laravel can reach a private/authenticated Node endpoint if needed
- Writable temp/output paths
- Timeouts
- CPU/memory/process-count/runtime limits
- Cron/worker overlap behavior
- Behavior during deployment/cPanel restarts
- Representative concurrent render behavior
- Cleanup after failed renders

If Cloudflare Browser Rendering is evaluated, separately verify:

- Outbound HTTPS connectivity
- Credential storage
- Request size constraints
- Rendering latency
- Failure/retry behavior
- Usage/cost at expected invoice volume
- Privacy/security implications for document contents

## Proposed document lifecycle

Unless the later spike identifies a reason to change it, use the following conceptual lifecycle:

### Draft documents

Draft invoices may be previewed dynamically. Re-rendering is acceptable because the document is not yet an immutable business artifact.

### Issued/sent documents

When an invoice reaches the business state at which its historical representation should be stable, generate and retain a stored PDF artifact rather than regenerating it on every download.

Benefits:

- Historical invoices do not visually change when templates change later.
- Renderer migrations do not rewrite old documents.
- Download behavior is fast and deterministic.
- Hash/metadata can be stored for auditability if later required.

The exact state transition that freezes an invoice PDF must be decided against the current invoice workflow before implementation.

## Synchronous versus queued generation

Do not decide this purely from renderer preference.

The spike should measure representative render times and production worker reliability.

Potential model:

- Draft preview: synchronous if reliably fast enough
- Final/issued artifact: queued generation with idempotent retry if operationally safer
- Download: serve the stored authorized artifact

If production queues are not reliably long-running, design around the hosting reality rather than assuming Docker's local worker model matches production.

## Security and authorization

Any production implementation must ensure:

- Documents are not publicly enumerable.
- Download authorization uses the same tenant/client access rules as the underlying invoice/document.
- Temporary files are private and cleaned up.
- External rendering credentials are never exposed to the browser.
- Sensitive document content is not logged unnecessarily.
- Retries are idempotent and do not create uncontrolled duplicate artifacts.

## Testing direction

The selected implementation should eventually include:

- Unit/domain tests for document-data mapping
- Integration test proving authorized generation/download
- Unauthorized cross-tenant/client download tests
- Representative fixture renders
- Structural assertions where practical (page count/text presence/metadata)
- Visual snapshot or golden-document review only where stable enough to be useful
- Failure/retry tests for queued/external rendering

## Deliverables of the Discovery Phase

Before an ADR selects a renderer, produce:

1. Representative invoice fixture/data
2. Representative SOW fixture/data
3. At least a DOMPDF/Spatie prototype
4. At least one high-fidelity prototype (Cloudflare or another Chromium-quality option)
5. pdfcn/Takumi prototype only if JSX/component authoring remains strategically attractive after the first comparisons
6. Host/runtime evidence for any local Node candidate
7. Side-by-side visual samples
8. Operational comparison
9. Recommendation and rationale
10. Proposed final document lifecycle and storage model

## Decision Gate

After the prototype/spike, create a dedicated ADR that records:

- Selected Laravel abstraction/package
- Selected renderer/driver
- Fallback renderer if any
- Sync/queue behavior
- Storage and immutability policy
- Production runtime dependencies
- Security model
- Testing strategy

Implementation should begin only after that decision is accepted.

## Out of Scope for Discovery

- Full SOW domain model/workflow
- Full proposal/contract authoring product
- Rich document editor
- Customer e-signature workflow
- Rebuilding the billing domain
- Selecting pdfcn merely because EPIC-011 uses React
