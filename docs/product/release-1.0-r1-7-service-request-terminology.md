# Release 1.0: R1-7 Service request terminology (design record)

**Status:** implemented on `feature/r1-7-service-request-terminology` from `main` at `2708eb0` (R1-6, PR #65). Presentation only: no route, permission, model, table, column, enum value, queue payload or API changed. Not merged, not deployed; Release 0.9 staging is untouched.

## 1. Glossary

| Term | Use it for | Examples |
|---|---|---|
| **Service request** | the full user-facing name of the entity: page titles, headings, an unqualified noun, report names, the first mention in a mail | "Service request queue", "Service request reports", "No service requests found.", "Your service request **TK-…** has been received." |
| **Request** | the short form wherever the sentence, control or label is already about requests: running text, buttons, menu items, compact labels, counts, flash and validation messages | "My requests", "New request", "Request status updated…", "3 requests updated.", the timer context "Request: TK-…" |
| **Helpdesk** | the functional workspace (navigation group, area name) | the Helpdesk workspace; **Queue**, **My requests**, **Supported requests**, **Reports** are its views |
| **Ticket** | the technical entity only: models, tables, columns, routes and route names, permissions (`tickets.*`), enum and API keys, queue classes, commands, logs, the activity-log description, tests and developer documentation | `App\Models\Ticket`, `/operator/tickets`, `tickets.assign`, `ticket_number`, `tickets:auto-close` |
| **Request number** | the public identifier `TK-XXXXX-XXXXX` (the `TK` prefix is the reference format and does not change) | mail subjects `[TK-…] …` |

Rules: user-visible text never says "ticket" (a person using the portal has never been told what a ticket is). Do not mix "support ticket", "ticket" and "request" for the same thing in one screen. "Support" stays where it names a *role* or *relationship* (partner support, **Supported requests**) and is not a synonym for the entity. No new entity type, subtype or "Incident" concept is implied by the wording.

## 2. Inventory: what said "ticket" to a user, and what it says now

| Surface | Before | After |
|---|---|---|
| Operator queue page (title and heading) | Ticket Queue | Service request queue |
| Operator queue: column, bulk-select labels, empty state | Ticket; "Select all tickets on this page"; "Select ticket X"; "No tickets found." | Request; "Select all requests on this page"; "Select request X"; "No service requests found." |
| Operator reports page (title; two table columns) | Ticket Reports; Tickets | Service request reports; Requests |
| Operator flash messages | "Ticket status updated to …"; "Ticket assignment updated."; "N tickets updated."; "No tickets were updated." | "Request status updated to …"; "Request assignment updated."; "N requests updated."; "No requests were updated." |
| Validation messages | "One or more selected tickets no longer exist."; "The selected assignee cannot be assigned tickets."; "The selected ticket id is invalid." (time) | "…selected requests no longer exist."; "…cannot be assigned requests."; "The selected request is invalid." |
| Dashboard | Open Tickets; New Ticket; Recent tickets; No open tickets | Open Requests; New Request; Recent requests; No open requests |
| Time: context selector, timer label (`Ticket: TK-…`), CSV column, empty timer hint | Ticket; "task or ticket" | Request; "task or request" |
| Operator CSV export | header `Ticket #`; file `tickets-YYYY-MM-DD.csv` | header `Request #`; file `service-requests-YYYY-MM-DD.csv` |
| Requester receipt mail | subject "Ticket received:"; "Your support ticket … has been received."; button "View Ticket" | "Request received:"; "Your service request … has been received."; "View request" |
| Reply mail (requester and assignee) | "A new reply has been added to ticket …"; "View Ticket" | "…added to request …"; "View request" |
| Operator new-request alert | greeting "New support request" | "New service request" |
| Operator organization page (partner relationship note) | "customer's tickets, projects, time or invoices" | "customer's service requests, …" |

Already coherent and **not** changed: the customer and partner React pages (My requests, Supported requests, Your requests, New request, request details), the status mails, the R1-5/R1-6 partner mails, the navigation (Helpdesk, Queue, My requests, Supported requests, Reports).

## 3. Deliberately left alone (and why)

- **Contracts and identifiers:** models, tables, columns, routes and URLs (`/tickets`, `/operator/tickets`), route names, permissions, enum values, Inertia component names, TypeScript types, `data-ticket-*` attributes, form field names (`ticket_refs`, `ticket_id`), JSON keys and the time-context `kind: 'ticket'`. The time context `type` is a display label only (the pill and the Blade timer print `type: label`); its TypeScript union moved from `'Ticket'` to `'Request'` with it, and no code branches on it.
- **Stored data:** the activity-log description `assigned ticket` is written to the audit log and is not rewritten; the legacy `TKT-` references and the `TK-` reference format are untouched.
- **Operational surface:** Artisan command names and output (`tickets:auto-close`, `tickets:rereference`, `e2e:cleanup-tickets`, backup and restore output), the operator runbooks, exception messages that only a developer sees, and code comments. These speak to operators and developers, for whom "ticket" is the entity's technical name.
- **Notification class names** (`TicketCreatedNotification`, …) and the queue payloads, so jobs already queued run unchanged.
- **Recipient rules and message safety:** untouched. No recipient, eligibility, escaping or link changed, only constant wording.

## 4. Intentional changes to checked contracts

These tests assert presentation strings and were updated, not weakened: the operator CSV header and file name, the bulk and assignment flash messages, the assignee validation message, the requester receipt subject, the time-context label, the accessible names of the queue checkboxes, the dashboard labels and the shell "queue heading is absent" assertions (which now name the new heading so they still prove the heading is hidden from a restricted customer).
The one externally visible contract that moves is the **CSV export**: a consumer that reads the first column by its header name (`Ticket #`) must read `Request #`, and the file name changes prefix. The column order and every value are unchanged.

## 5. Evidence

See the hand-back for the focused runs; hosted CI is the full regression gate.
