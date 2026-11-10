# ADR-009: Ticket classification and the operational Incident boundary

- **Date:** 2026-11-09
- **Status:** **Accepted as conceptual direction, revised 2026-11-09 before implementation**. No implementation or Release 1.0 feature commitment.
- **Revision history:** The initial version of this ADR treated Service Request and Incident as conceptual Ticket specializations. Architectural review on the same day clarified that Incident is instead an independent operational entity. The original wording remains available in Git history; no implementation was built against it.
- **Related:** [Information Architecture](../../product/information-architecture.md), [Platform Product & UX Direction](../../product/platform-product-ux-direction.md), [Product Roadmap](../../product/product-roadmap.md), [EPIC-017 §29](../../epics/EPIC-017-helpdesk-mvp-customer-shell.md#29-incidents), [ADR-005](./ADR-005-organization-multitenancy.md) (tenancy) and [ADR-008](./ADR-008-ticket-public-reference.md) (Ticket public identity).

## Context

The current `Ticket` model is a requester-oriented support record with conversation, notes, attachments, assignment, status history, time attribution, customer authorization, and due-date/SLA behavior. It is well suited to UNISA customer support. The initial draft of this decision proposed Service Request and Incident as Ticket specializations. This conflated **a customer request or report** with **the underlying operational disruption**.

For example, one integration failure can generate several customer Tickets. An operational Incident may be detected by monitoring before a customer reports it, or may arise with no Ticket at all. Equating the Incident with a Ticket would force requester, tenant, notification, and lifecycle assumptions onto an operational event.

This decision specifies conceptual boundaries without choosing a schema, framework or shared-service abstraction.

## Decision

1. **Ticket remains the durable support-record entity.** Existing Ticket identity, policies, replies, assignments, history, timers, tasks and data-integrity guarantees continue unchanged. `Ticket` can remain the implementation term even where the customer-facing interface says "Request".
2. **Service Request is the first intended Ticket classification.** It represents customer requests for help, information, or work. Existing Tickets are not automatically relabeled, migrated or given a `type` column. A conceptual type does not require subclassing, a new table or a discriminator while there is only one supported kind. Whether and when other ticket classifications are useful is a future product decision. Reports of faults can remain ordinary customer Tickets without creating an Incident.
3. **Incident is a separate operational domain entity, not a Ticket subtype.** It represents an investigated or managed disruption, degradation or failure, and may have its own incident identifier, severity/impact, affected services/organizations, evidence, operators and response lifecycle. It can exist without a Ticket. Several Tickets may reference the same Incident; the precise relationship cardinality and storage mechanism remain open.
4. **Outage is not a Ticket type.** It may become an Incident classification, an impact/availability condition, or another incident-domain concept if needed. Do not select a data model before concrete monitoring and operational requirements exist.
5. **Association does not grant visibility or synchronize lifecycle.** Linking a Ticket to an Incident must not expose internal Incident details or other customers' data. Any customer-visible incident update needs explicit, authorized publication with appropriate provenance and recipient/tenant scoping. Resolving or closing an Incident must not automatically resolve or close associated Tickets. Coordinated actions, if later introduced, must be explicit and reviewable.
6. **Reuse infrastructure selectively, not through domain inheritance.** Comments, attachments, notifications, events, history and time attribution might eventually share well-tested infrastructure, but their permissions, audience, lifecycle and semantics belong to their respective domains. No shared base class, polymorphic entity layer, general-purpose workflow engine or common schema is prescribed now.
7. **Operational evidence is distinct from both entities.** Alerts, telemetry, health checks and application events are evidence, not automatically Tickets or Incidents. Future automated creation may be considered through explicitly designed, authorized rules; it is neither required nor categorically forbidden by this ADR.

## Constraints and future decision gates

**Release 1.0 is unchanged.** Do not add a Ticket `type`, Incident table, `incident_id`, join table, new capabilities, new navigation or customer UI as a consequence of this ADR. Preserve the Release 0.9 durable-data guarantees, current `TK-` public references, and the existing tenancy and authorization boundaries. Do not loosen foreign keys or required fields for a hypothetical future type.

**The original draft's R1-2 subtype-compatibility checkpoint is withdrawn.** R1-2 and subsequent managed-organization packages should continue normal security, organization-scope, rollback, and data-integrity review for the Ticket model they actually implement. They have no extra requirement to classify each touched field as "shared across future ticket subtypes", and Incident does not shape R1-2's schema.

**Before introducing another persisted Ticket classification:** establish a concrete second consumer, determine whether type-specific behavior exists, and decide the smallest compatible type representation, validation, UI and migration of authoritative legacy Tickets. Avoid assuming inheritance.

**Before implementing Incident management or Ticket-to-Incident links:** write a focused design decision covering Incident ownership and lifecycle, monitoring/manual intake, identity, impact/severity, organization scope, operator authorization, customer publication, relationship cardinality and data integrity, and whether shared infrastructure is warranted. Preserve tenant isolation by design; do not presuppose a nullable FK or polymorphic relationship.

## Relationship to existing documents

- [Product and UX direction](../../product/platform-product-ux-direction.md) and [Information Architecture](../../product/information-architecture.md) already describe an Incident as a centrally managed operational event that can affect many Tickets. This revised ADR makes that **distinct-entity boundary** explicit and restores their coherence.
- [EPIC-017 §29](../../epics/EPIC-017-helpdesk-mvp-customer-shell.md#29-incidents) remains authoritative about the **Release 1.0 exclusion** of Incidents, but its historical "only a nullable foreign key" premise is not an implementation decision.
- The original draft's candidate shared-field table, incident-as-ticket assumption, R1-2 compatibility checklist, "first additional ticket type" gate as applied to Incidents, and Outage-as-ticket question are **withdrawn**. Its useful cautions about tenant isolation, explicit publication and protecting present-day integrity remain.

## Consequences

- **Benefit:** support conversations remain simple, while operational incidents can later serve monitoring, multiple organizations and several related customer reports without inheriting ticket-specific constraints.
- **Benefit:** no Release 1.0 work, database design or migration is added; implementation momentum and durable records are protected.
- **Trade-off:** future Incident management must deliberately design its own workflow, security and relationship to Tickets instead of merely reusing the Ticket schema.
- **Open:** Ticket classification timing, Incident scope and lifecycle, update-publication mechanics, and whether Outage is an Incident type or an impact attribute. These remain genuine design questions, not hidden commitments.
