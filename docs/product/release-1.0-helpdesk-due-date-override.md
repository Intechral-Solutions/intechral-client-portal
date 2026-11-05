# Release 1.0: Help Desk due-date overrides

Operators can move a ticket's working due date when work is delayed (waiting on a customer or an external dependency). The original SLA
deadline is never changed.

## Model

- `tickets.sla_due_at`: the original SLA deadline, set at creation. **Immutable**: `Ticket` refuses an update that changes it.
- `tickets.due_override_at`: nullable, operator-set. Not mass-assignable; written only by `TicketService::addReply()`.
- **Effective due date** = `due_override_at ?? sla_due_at` (`Ticket::effectiveDueAt()`).
- `ticket_due_date_changes`: one row per adjustment (previous and new override, actor, time). `reply_id` is NOT NULL and UNIQUE, and
  (`ticket_id`, `reply_id`) is a composite foreign key to `ticket_replies (ticket_id, id)`, so a change always has exactly one
  explaining reply or note and that reply belongs to the same ticket. A null new value means "restored to the SLA deadline".

## Behaviour

- An adjustment is an optional part of the operator reply/note form (`due_action` = keep | set | restore, and `due_at` for set). It is
  never a separate edit. The message is required, including for a restore. It may accompany a public reply or an internal note.
- Authorization: `TicketPolicy::adjustDueDate` (operator = `tickets.assign` and not a restricted customer, on a ticket they can
  view). The controller checks it before validation whenever a due-date field is sent, so a requester gets a 403 and nothing is written;
  `TicketService::addReply` refuses a customer author again.
- Rules, checked on the row locked by `addReply`: ticket still open; a new date is in the future and at most 365 days ahead; not the
  same date again; restore only when an override exists (and allowed even if the SLA deadline is already past).
- Atomic: reply, attachments, files, the override and the history row commit together under the existing row lock, or none does.
- Time zone: the `datetime-local` value is read in `app.timezone` (UTC, pinned by `RuntimeProfile`), named beside the field.
  `DueDateInput` parses strictly and refuses a wall-clock time that does not exist; an ambiguous one means its first occurrence.
- Visibility: the change line appears only on the operator ticket page. The requester presenter, props and reply mail carry no due data.

## Display and reporting

- Operator queue and ticket page say "Due". Without an override the date carries a small "SLA" tag; with one, the tag is gone and the
  original SLA is disclosed ("Adjusted" disclosure in the queue, a visible line on the ticket page).
- "Overdue" and queue ordering use the effective due date (`isOverdue()`, `scopeOverdue`, `scopeOrderByEffectiveDue`).
- CSV export: `SLA Due` is unchanged (the original); `Effective Due` is appended.
- Not in 1.0: SLA compliance reporting, pause/resume, business hours, new notifications, task due dates.

## Compatibility

Additive migration (`2026_11_04_120000_add_ticket_due_date_override`), no backfill. The Release 0.9 build ignores the new column and
table. Rehearsed on populated data: see [migration rehearsal](../testing/migration-rehearsal.md).
