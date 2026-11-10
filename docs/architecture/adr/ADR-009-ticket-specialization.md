# ADR-009: Ticket specialization (Service Request, Incident, and later types)

- **Date:** 2026-11-09
- **Status:** **Accepted as conceptual direction** (owner decision, 2026-11-09). **Implementation decisions are deferred**: nothing here is built, scheduled for Release 1.0, or committed to a storage model.
- **Amends (does not rewrite):** the EPIC-017 [§29](../../epics/EPIC-017-helpdesk-mvp-customer-shell.md#29-incidents) assumption that linking tickets to an Incident "needs only a nullable foreign key". That is one possible implementation, not a commitment (see [Reconciliation](#reconciliation-with-earlier-documents)).
- **Related:** [ADR-005](./ADR-005-organization-multitenancy.md) (tenancy), [ADR-008](./ADR-008-ticket-public-reference.md) (public reference), [Information Architecture](../../product/information-architecture.md), [Product Roadmap](../../product/product-roadmap.md), the R1-1 authorization baseline in [Customer Pilot Surface Gate](../customer-pilot-surface.md#r1-1-authorization-baseline).

## Context

`Ticket` is today a customer support conversation: one requester (`tickets.user_id`), an optional CRM company (`company_id`), replies with an internal-note flag, attachments, status history, an SLA deadline and an operator due-date override, time entries and tasks. Release 1.0 for UNISA makes that organization-aware (R1-2) and adds invitations, partner authorization, notifications and customer workflows.

The product direction ([UX direction](../../product/platform-product-ux-direction.md#helpdesk-direction), [IA](../../product/information-architecture.md#incident)) already expects Incidents: a service-affecting event across many requesters and organizations, managed centrally while each requester keeps their own conversation. Further operational concepts (an Outage backed by monitoring evidence) are plausible. The risk to manage is not building them early; it is letting customer-specific rules harden, unnoticed, into universal Ticket assumptions that a second ticket type would have to undo.

## Decision

1. **`Ticket` remains the foundational entity.** Specializations are conceptual candidates that refine it. No second entity is introduced now.
2. **Service Request** (the specialization the current Ticket already is):
   - Customer-scoped work and communication, typically tied to one customer organization and a requesting or contact user.
   - Owns its conversation, lifecycle and SLA obligations.
3. **Incident** (post-1.0 by default):
   - An operationally scoped investigation or response to a failure, degradation, disruption or similar. May affect several organizations and relate to several Service Requests.
   - Owns the operational investigation and its resolution. It does **not** replace customer-specific workflows: each associated Service Request keeps its own conversation, lifecycle and SLA.
4. **Incident-to-Service-Request communication is selective and explicit.**
   - An Incident may publish an update to associated Service Requests only through an explicit, per-update visibility choice. Nothing an Incident records is visible to a Service Request's requester by default.
   - Tenant isolation holds throughout: publishing to one organization's Service Request discloses nothing about other organizations, other Service Requests, or internal Incident detail.
   - Coordinated resolution (one common message, individual exceptions) may be supported eventually. It must be an **explicit, reviewable operator act with per-request exceptions, never an implicit or unconditional status cascade**. Resolving or closing an Incident never silently changes a Service Request.
5. **Further specializations are permitted, not assumed.** The architecture must not require a rewrite to admit another type, and it must not presume one. A hypothetical **Outage** might reference monitoring alerts, telemetry, application events or affected services. Whether an Outage is a ticket specialization or a separate operational entity associated with tickets is **undecided**.
6. **Operational evidence does not automatically become tickets.** An alert, telemetry sample or application event is evidence. A person or a reviewed rule decides that it warrants a ticket; ingestion never creates one on its own.
7. **No mechanism is chosen.** This ADR decides none of: inheritance, composition, table-per-type, a type discriminator, polymorphic association, shared or separate lifecycles, relationship cardinality between types, or which fields a future type shares.
8. **Immediate Service Request work keeps real integrity constraints.** Foreign keys, NOT NULL, UNIQUE and CHECK constraints that protect Service Request correctness stay in the schema (as R1-2 and later packages require). They are not weakened, made nullable or left to application code to protect a hypothetical type. Their cost is paid at Checkpoint 2, deliberately, with data and requirements in hand.

## Data-model evolution (conceptual classification, not a plan)

The `tickets` data model is to be **reassessed as specializations become concrete**. No field moves now. The table sorts *candidate* shared infrastructure from *candidate* Service Request properties so that new work names the difference; each cell is a hypothesis for Checkpoint 2, not a rule.

| Likely shared infrastructure | Likely Service Request specific | Likely belongs to another type |
|---|---|---|
| Public reference and internal identifier ([ADR-008](./ADR-008-ticket-public-reference.md)) | Customer organization; requester or contact user | Incident: investigation, severity, affected scope |
| Title, description, category, priority (to be confirmed) | SLA obligations; customer due-date handling | Outage: observations, affected services, evidence links |
| Attachments, history, assignment | Customer response, "waiting on customer" and reopening rules | |
| Replies and the internal-note concept (a visibility model, to be confirmed) | Customer-facing status vocabulary | |
| Time attribution; task links | Requester-owner visibility | |

The contested middle is expected: status and lifecycle, priority, SLA deadlines, reply visibility, the reference prefix and notification recipients each look shared today and may prove customer-specific.

## Checkpoints

1. **Before R1-2 (lightweight compatibility assessment).** Purpose: stop customer-specific invariants becoming permanent universal Ticket assumptions. R1-2's design record must include a short answer to the recommended checklist below. It is an assessment, not an abstraction: R1-2 adds no type column, no incident association, no base class, no interface and no polymorphism. Outcome is recorded in R1-2; an ADR amendment is needed only if something surprising is found.
2. **Before the first additional ticket type (substantial review).** Starts from concrete requirements and decides, with evidence: whether fields move or generalize; the lifecycle and status model per type; the reference and routing scheme; policy and visibility for non-requester participants; notification recipients; the migration plan on then-live data (it follows the release-engineering, data-migration, backup and recovery procedures that apply at that time; the [Release 1.0 notes](../../product/release-1.0-notes.md) are historical context, not a permanent contract); and whether Outage is a ticket specialization, **only if that question is relevant to the concrete type being introduced**, otherwise it stays explicitly open. It produces its own ADR.

### Recommended R1-2 checklist

1. **Classify.** For each column, constraint, policy ability, service method, notification and route/prop name R1-2 adds or changes, record whether it is *Service Request specific* or *shared/undetermined*. Keep the list short; touched items only.
2. **Name honestly.** Do not give a customer-specific rule a type-neutral name that implies it is universal, and do not hard-code customer-specific meaning into a name that looks shared.
3. **Enforce organization awareness consistently and centrally.** Organization-aware access and scoping must be enforced through a deliberately designed, centralized seam, not by scattering "a ticket has exactly one organization and one requester" through controllers, notifications, reports and props. This ADR does not prescribe the mechanism: extending `TicketPolicy` (including the `viewAsRequesterOrOperator` versus `view` split R1-1 kept), a dedicated access service, a query-scoping layer or another appropriate policy/query seam are all acceptable, and R1-2 is not required to treat the existing `TicketPolicy` methods as its definitive abstraction.
4. **Record the integrity trade-off.** For each new constraint (for example NOT NULL organization), state it as a deliberate Service Request invariant and where it is enforced, so Checkpoint 2 can find it. "A future Incident row could not satisfy this" is an acceptable, recorded answer.
5. **Add nothing speculative.** No `type`, `incident_id`, parent or polymorphic columns; no incident, outage or monitoring vocabulary in schema, routes or UI.

## Where the current model stands

| Existing support for specialization | Compatibility risk to watch |
|---|---|
| One identifier layer: random public reference, internal BIGINT key ([ADR-008](./ADR-008-ticket-public-reference.md)); no domain meaning in the key | The `TK-` reference format and the "canonical reference" guard are written for tickets; a second type's reference scheme is open |
| Replies carry an internal-note flag; attachments and history are separate child tables keyed by `ticket_id` | Reply visibility is binary (internal or not). It does not model controlled Incident publication, provenance (where a published update came from) or selective propagation to chosen Service Requests; how those would be modelled is open |
| Authorization is centralized in `TicketPolicy`, with a requester-or-operator seam kept distinct from `view` (R1-1) | Policy and scoping assume an owner (`user_id`); non-requester participants (partner staff, incident publishers) need a decision, not an extension by accident |
| Organization tenancy exists and tickets are deliberately not directly scoped ([ADR-005](./ADR-005-organization-multitenancy.md)); `company_id` is optional | R1-2 will make organization context meaningful; `user_id` as "requester" and one company per ticket are customer-shaped |
| Operator-only assignment (`tickets.assign` plus `portal.operator`, R1-1) | The same assignment fits an Incident, but assignee eligibility may differ per type |
| Time entries and tasks reference `ticket_id` | They would attach to every type unless that is decided otherwise |
| Status, priority and SLA columns exist | `pending_user`, `sla_due_at` and the due-date override encode customer obligations; they may not apply to an Incident or Outage |
| Notifications and reports are written for a requester | Recipients, wording and CSV columns assume a customer conversation |

## Not decided here (explicitly open)

- Whether specializations share a table, share a base, compose, or split, and how a type is represented.
- Cardinality between Incident and Service Request, including whether a Service Request may follow more than one Incident.
- Whether Incident reuses the `TK-` reference or has its own.
- Whether Outage is a ticket specialization or a separate entity; how monitoring evidence is stored or linked. Checkpoint 2 decides this only if it matters to the type then being introduced; otherwise it remains open.
- The per-update visibility model, who may publish, and the exact shape of coordinated resolution.
- Whether customers ever see an Incident, or only published updates on their own Service Request.
- Which current fields move, generalize or stay (Checkpoint 2).
- Any renaming of "Ticket" in code, routes or UI. The user-facing words are unchanged.

## Sequencing

Release 1.0 scope is unchanged.

| Step | Status |
|---|---|
| R1-1 authorization baseline | Completed and merged |
| This ADR (documentation only) | Now |
| R1-2 organization-aware Service Requests, with Checkpoint 1 | Next |
| Invitations, partner authorization, notifications, customer workflows | Subsequent R1 packages |
| Incident implementation | Post-1.0 by default; promoted only by owner decision |
| Coordinated incident updates and resolution | Future design |
| Monitoring, alerts and Outage integration | Long-term exploration; not a scheduled feature |

No Release 1.0 dependency is introduced by this ADR. One is added later only by demonstrating a concrete need.

## Reconciliation with earlier documents

- **EPIC-017 §29** ("linking tickets to a future Incident needs only a nullable foreign key") is kept as written, as a historical statement. This ADR supersedes it as guidance: a nullable foreign key is one possible implementation, to be judged at Checkpoint 2. The conclusion that no seam is reserved in Release 1 is unchanged and reinforced.
- **Information Architecture and UX direction** describe "Ticket ↔ Incident: many Tickets to one Incident". That remains the product intent. The cardinality and storage are open per this ADR.
- **Roadmap** classification of Incidents (Class B, default Post-v1) is unchanged.
- The ADR is conceptual and creates no obligation on ADR-005 tenancy or ADR-008 reference decisions.

## Consequences

- Positive: new Release 1.0 work has a short, concrete test for avoiding accidental lock-in, and the Incident direction is recorded once instead of rediscovered.
- Positive: no schema, interface or UI change; integrity constraints are not diluted.
- Negative: a later second type will cost a deliberate data-model review and probably a migration on live data. That is accepted over speculative abstraction now.
- Negative: R1-2 carries a small documentation obligation.

## Alternatives rejected

- **Reserve incident or type columns now.** Speculative; each shape choice (nullable key, discriminator, join table) is exactly what is undecided.
- **Introduce an abstract base class or interface for tickets now.** Commits to inheritance without requirements.
- **Make every Ticket constraint nullable "for flexibility".** Gives up real integrity for the immediate customer to protect an unplanned type.
- **Do nothing and rediscover at Incident time.** Leaves R1-2 free to harden customer-only assumptions without anyone noticing.
